<?php

namespace Tests\Feature;

use App\Livewire\CameraFleet\Manager;
use App\Models\Camera;
use App\Models\CameraAnalysisEvent;
use App\Models\CameraRecording;
use App\Models\User;
use App\Services\Analysis\AnalysisCoordinator;
use App\Services\Analysis\AnalysisLabels;
use App\Services\Analysis\AnalysisSampler;
use App\Services\CameraRecordingService;
use App\Services\RecordingMotionDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class CameraAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.file.path' => storage_path('framework/cache/data')]);
        Cache::forgetDriver('file');
    }

    private function camera(): Camera
    {
        return Camera::create(['name' => 'Test camera', 'local_ip' => '192.0.2.1', 'supports_rtsp' => true,
            'is_enabled' => true, 'recording_mode' => 'continuous', 'recording_retention_days' => 2]);
    }

    public function test_generation_ignores_recorder_heartbeats_but_changes_with_analysis_settings(): void
    {
        $camera = $this->camera();
        $generation = AnalysisCoordinator::generation($camera);
        $camera->update(['recording_last_motion_at' => now(), 'last_seen_at' => now()]);
        $this->assertSame($generation, AnalysisCoordinator::generation($camera));
        $camera->update(['ai_enabled' => true]);
        $this->assertNotSame($generation, AnalysisCoordinator::generation($camera));
    }

    public function test_confirmation_requires_distinct_recent_spatial_observations(): void
    {
        $service = new AnalysisLabels;
        $prior = [];
        $detection = ['class' => 'cat', 'confidence' => .7, 'box' => [.1, .1, .3, .3], 'high_confidence' => false];
        $this->assertSame([], $service->confirm([$detection], 100, $prior));
        $this->assertSame([], $service->confirm([$detection], 100, $prior));
        $confirmed = $service->confirm([$detection], 100.5, $prior);
        $this->assertCount(1, $confirmed);
        $this->assertSame([100.0, 100.5], $confirmed[0]['times']);
        $this->assertSame([], $service->confirm([$detection], 104, $prior));
        $moving = $detection;
        $moving['box'] = [.27, .1, .47, .3];
        $this->assertCount(1, $service->confirm([$moving], 104.7, $prior));

        $detection['high_confidence'] = true;
        $this->assertCount(1, $service->confirm([$detection], 110, $prior));
    }

    public function test_labels_associate_with_late_recordings_at_observation_times_and_are_idempotent(): void
    {
        $camera = $this->camera();
        $event = CameraAnalysisEvent::create(['camera_id' => $camera->id, 'generation' => str_repeat('a', 64), 'started_at' => now()->subMinute()]);
        $service = new AnalysisLabels;
        $at = now()->subSeconds(10)->timestamp;
        $observations = [['class' => 'dog', 'confidence' => .8, 'times' => [$at]],
            ['class' => 'dog', 'confidence' => .9, 'times' => [$at]]];
        $service->record($event, $observations);
        $service->record($event, $observations);
        $this->assertDatabaseCount('camera_analysis_labels', 1);
        $this->assertEqualsWithDelta(.9, $event->labels()->first()->confidence, .00001);
        $this->assertCount(1, $event->labels()->first()->observed_at);
        $this->assertDatabaseCount('camera_analysis_label_recording', 0);
        $recording = CameraRecording::create(['camera_id' => $camera->id, 'capture_mode' => 'continuous', 'status' => 'recorded',
            'scheduled_for' => now()->subSeconds(30), 'started_at' => now()->subSeconds(30), 'ended_at' => now()]);
        $earlier = CameraRecording::create(['camera_id' => $camera->id, 'capture_mode' => 'continuous', 'status' => 'recorded',
            'scheduled_for' => now()->subMinute(), 'started_at' => now()->subMinute(), 'ended_at' => now()->subSeconds(30)]);
        $service->maintain();
        $service->maintain();
        $this->assertSame(['dog'], $recording->analysisLabelNames());
        $this->assertSame([], $earlier->analysisLabelNames());
        $this->assertDatabaseCount('camera_analysis_label_recording', 1);
        $this->actingAs(User::factory()->create())->get('/recordings?object=animals')->assertOk()->assertSee('Dog');
        $event->update(['ended_at' => now()]);
        $recording->delete();
        $service->maintain();
        $this->assertDatabaseCount('camera_analysis_labels', 0);
    }

    public function test_unassociated_events_expire_and_ai_defaults_to_disabled(): void
    {
        $camera = $this->camera()->fresh();
        $this->assertFalse($camera->ai_enabled);
        $this->assertSame('disabled', $camera->analysisStatus()['state']);
        CameraAnalysisEvent::create(['camera_id' => $camera->id, 'generation' => str_repeat('b', 64), 'started_at' => now()->subDays(3)]);
        (new AnalysisLabels)->maintain();
        $this->assertDatabaseCount('camera_analysis_events', 0);
    }

    public function test_one_decoder_produces_matching_color_and_gray_frames_without_upscaling(): void
    {
        $sampler = new AnalysisSampler('/usr/bin/ffmpeg', $this->camera(), [], ['-re', '-f', 'lavfi', '-i', 'color=red:size=320x180:rate=3']);
        $frames = [];
        $deadline = microtime(true) + 8;
        try {
            while (count($frames) < 3 && microtime(true) < $deadline) {
                array_push($frames, ...$sampler->read());
                usleep(30000);
            }
        } finally {
            $sampler->close();
        }
        $this->assertGreaterThanOrEqual(3, count($frames));
        foreach ($frames as $frame) {
            $this->assertSame(160 * 90, strlen($frame['gray']));
            $this->assertStringStartsWith("P6\n320 180\n255\n", $frame['frame']);
        }
    }

    public function test_trigger_uses_lookahead_frame_and_pending_work_stays_bounded(): void
    {
        config(['analysis.enabled' => true, 'analysis.token' => str_repeat('x', 32)]);
        $camera = $this->camera();
        $camera->update(['ai_enabled' => true]);
        $motion = \Mockery::mock(RecordingMotionDetectorService::class);
        $motion->shouldReceive('analyzeFrames')->andReturn(['detected' => true]);
        $coordinator = new AnalysisCoordinator(app(CameraRecordingService::class), $motion, new AnalysisLabels);
        (new ReflectionMethod($coordinator, 'refresh'))->invoke($coordinator);
        $states = (new ReflectionProperty($coordinator, 'states'))->getValue($coordinator);
        $state = $states[$camera->id];
        $frameMethod = new ReflectionMethod($coordinator, 'frame');
        $start = microtime(true);
        for ($index = 0; $index < 3; $index++) {
            $frameMethod->invokeArgs($coordinator, [&$state, ['at' => $start + $index / 3, 'gray' => 'gray', 'frame' => 'color'.$index]]);
        }
        $this->assertSame('color1', $state['pending']['initial']['frame']);
        $this->assertSame($start + 1 / 3, $state['pending']['initial']['at']);
        $frameMethod->invokeArgs($coordinator, [&$state, ['at' => $start + 1, 'gray' => 'gray', 'frame' => 'color3']]);
        $this->assertCount(1, $state['pending']);
        $frameMethod->invokeArgs($coordinator, [&$state, ['at' => $start + 4 / 3, 'gray' => 'gray', 'frame' => 'color4']]);
        $this->assertSame('color3', $state['pending']['latest']['frame']);
        for ($index = 5; $index < 30; $index++) {
            $frameMethod->invokeArgs($coordinator, [&$state, ['at' => $start + $index / 3, 'gray' => 'gray', 'frame' => 'color'.$index]]);
        }
        $this->assertCount(2, $state['pending']);
        $this->assertCount(7, $state['ring']);
        $this->assertSame('color1', $state['pending']['initial']['frame']);
        $this->assertGreaterThan(0, $state['event']->fresh()->skipped_frames);
        (new ReflectionMethod($coordinator, 'finish'))->invokeArgs($coordinator, [&$state]);
        $this->assertNull($state['event']);
        $this->assertSame([], $state['pending']);
        $this->assertNotNull(CameraAnalysisEvent::first()->ended_at);
    }

    public function test_empty_masks_and_disabled_cameras_do_not_start_samplers(): void
    {
        config(['analysis.enabled' => true, 'analysis.token' => str_repeat('x', 32)]);
        $camera = $this->camera();
        $camera->update(['ai_enabled' => true, 'recording_motion_mask' => ['grid_width' => 160, 'grid_height' => 90, 'runs' => []]]);
        $coordinator = app(AnalysisCoordinator::class);
        $refresh = new ReflectionMethod($coordinator, 'refresh');
        $states = new ReflectionProperty($coordinator, 'states');
        $refresh->invoke($coordinator);
        $this->assertSame([], $states->getValue($coordinator));
        $camera->update(['recording_motion_mask' => null]);
        $refresh->invoke($coordinator);
        $this->assertCount(1, $states->getValue($coordinator));
        $camera->update(['ai_enabled' => false]);
        $refresh->invoke($coordinator);
        $this->assertSame([], $states->getValue($coordinator));
    }

    public function test_observations_cross_segment_boundaries_without_labeling_the_gap(): void
    {
        $camera = $this->camera();
        $start = now()->startOfMinute();
        $event = CameraAnalysisEvent::create(['camera_id' => $camera->id, 'generation' => str_repeat('c', 64), 'started_at' => $start]);
        $segments = collect([0, 10, 20])->map(fn ($seconds) => CameraRecording::create([
            'camera_id' => $camera->id, 'status' => 'recorded', 'capture_mode' => 'continuous',
            'scheduled_for' => $start->copy()->addSeconds($seconds), 'started_at' => $start->copy()->addSeconds($seconds),
            'ended_at' => $start->copy()->addSeconds($seconds + 10),
        ]));
        (new AnalysisLabels)->record($event, [['class' => 'person', 'confidence' => .9, 'times' => [$start->timestamp + 10, $start->timestamp + 20]]]);
        $this->assertSame([], $segments[0]->analysisLabelNames());
        $this->assertSame(['person'], $segments[1]->analysisLabelNames());
        $this->assertSame(['person'], $segments[2]->analysisLabelNames());
    }

    public function test_continuous_camera_can_save_ai_and_reject_an_unknown_profile(): void
    {
        $camera = $this->camera();
        $camera->update(['rtsp_path' => '/fixture', 'http_port' => 80, 'onvif_port' => 80, 'rtsp_port' => 554,
            'recording_motion_trigger_pixels' => 10, 'recording_motion_pre_roll_seconds' => 8, 'recording_motion_post_trigger_seconds' => 20]);
        $component = Livewire::test(Manager::class)
            ->call('editCamera', $camera->id)->set('form.ai_enabled', true)
            ->assertSee('Object labeling starts on motion and stops after three quiet seconds.')
            ->assertDontSee('Post-trigger seconds')
            ->set('form.ai_profile_index', 999)->call('saveCamera')->assertHasErrors('form.ai_profile_index')
            ->set('form.ai_profile_index', '')->call('saveCamera')->assertHasNoErrors();
        $this->assertTrue($camera->fresh()->ai_enabled);
        $this->assertNull($camera->fresh()->ai_profile_index);
        $this->assertSame('continuous', $camera->fresh()->recording_mode);
        $camera->update(['ai_profile_index' => 999]);
        $component->call('editCamera', $camera->id)->set('form.ai_enabled', false)
            ->call('saveCamera')->assertHasNoErrors();
        $this->assertFalse($camera->fresh()->ai_enabled);
    }

    public function test_scheduler_expires_work_rotates_cameras_and_cancels_obsolete_configuration(): void
    {
        config(['analysis.enabled' => true, 'analysis.token' => str_repeat('x', 32)]);
        $first = $this->camera();
        $second = $this->camera();
        $first->update(['ai_enabled' => true]);
        $second->update(['ai_enabled' => true]);
        $coordinator = app(AnalysisCoordinator::class);
        $refresh = new ReflectionMethod($coordinator, 'refresh');
        $refresh->invoke($coordinator);
        $statesProperty = new ReflectionProperty($coordinator, 'states');
        $flightProperty = new ReflectionProperty($coordinator, 'flight');
        $states = $statesProperty->getValue($coordinator);
        $events = [];
        foreach ([$first, $second] as $camera) {
            $event = CameraAnalysisEvent::create(['camera_id' => $camera->id, 'generation' => $states[$camera->id]['generation'], 'started_at' => now()->subSecond()]);
            $events[$camera->id] = $event;
            $states[$camera->id]['event'] = $event;
            $states[$camera->id]['pending'] = ['initial' => ['at' => microtime(true), 'frame' => 'fixture', 'event' => $event]];
        }
        $states[$first->id]['pending']['latest'] = $states[$first->id]['pending']['initial'];
        $states[$first->id]['pending']['initial']['at'] -= 3;
        $statesProperty->setValue($coordinator, $states);
        $multi = curl_multi_init();
        (new ReflectionProperty($coordinator, 'multi'))->setValue($coordinator, $multi);
        (new ReflectionProperty($coordinator, 'leaseAt'))->setValue($coordinator, microtime(true) + 60);
        $dispatch = new ReflectionMethod($coordinator, 'dispatch');
        try {
            $dispatch->invoke($coordinator);
            $flight = $flightProperty->getValue($coordinator);
            $this->assertSame($first->id, $flight['id']);
            $this->assertSame(1, $events[$first->id]->fresh()->skipped_frames);
            curl_multi_remove_handle($multi, $flight['handle']);
            $flightProperty->setValue($coordinator, null);
            $dispatch->invoke($coordinator);
            $this->assertSame($second->id, $flightProperty->getValue($coordinator)['id']);
            $second->update(['ai_profile_index' => 1]);
            $refresh->invoke($coordinator);
            $this->assertNull($flightProperty->getValue($coordinator));
            $this->assertSame(1, $events[$second->id]->fresh()->skipped_frames);
            $this->assertNotNull($events[$second->id]->fresh()->ended_at);
        } finally {
            curl_multi_close($multi);
        }
    }

    public function test_invalid_detections_cannot_be_successful_empty_analysis(): void
    {
        $labels = new AnalysisLabels;
        $this->assertTrue($labels->validDetections([]));
        foreach ([
            [['class' => 'cat', 'confidence' => .9, 'box' => ['a' => .1, 'b' => .1, 'c' => .5, 'd' => .5]]],
            [['class' => 'cat', 'confidence' => INF, 'box' => [.1, .1, .5, .5]]],
            [['class' => 'cat', 'confidence' => .9, 'box' => [.5, .5, .1, .1]]],
            ['unexpected' => []],
        ] as $detections) {
            $this->assertFalse($labels->validDetections($detections));
        }
    }

    public function test_healthy_service_does_not_hide_a_failed_sampler_or_overload(): void
    {
        config(['analysis.enabled' => true, 'analysis.token' => str_repeat('x', 32)]);
        $camera = $this->camera();
        $camera->update(['ai_enabled' => true]);
        $coordinator = app(AnalysisCoordinator::class);
        (new ReflectionMethod($coordinator, 'refresh'))->invoke($coordinator);
        $states = new ReflectionProperty($coordinator, 'states');
        $status = new ReflectionMethod($coordinator, 'status');
        $lease = new ReflectionMethod($coordinator, 'receiveLease');
        $lease->invoke($coordinator, ['loaded' => true, 'provider' => 'CPUExecutionProvider'], true);
        $state = $states->getValue($coordinator)[$camera->id];
        $this->assertSame('starting', $status->invoke($coordinator, $state));
        $state['retry_at'] = microtime(true) + 5;
        $this->assertSame('unavailable', $status->invoke($coordinator, $state));
        $state['stream_ready'] = true;
        $state['overloaded_until'] = microtime(true) + 5;
        $states->setValue($coordinator, [$camera->id => $state]);
        $lease->invoke($coordinator, ['loaded' => true, 'provider' => 'CPUExecutionProvider'], true);
        $this->assertSame('overloaded', $status->invoke($coordinator, $states->getValue($coordinator)[$camera->id]));
        $lease->invoke($coordinator, 'invalid response', true);
        $this->assertSame('unavailable', $status->invoke($coordinator, $states->getValue($coordinator)[$camera->id]));
    }

    public function test_bad_responses_and_model_changes_do_not_confirm_labels(): void
    {
        config(['analysis.enabled' => true, 'analysis.token' => str_repeat('x', 32)]);
        $camera = $this->camera();
        $camera->update(['ai_enabled' => true]);
        $coordinator = app(AnalysisCoordinator::class);
        (new ReflectionMethod($coordinator, 'refresh'))->invoke($coordinator);
        $states = new ReflectionProperty($coordinator, 'states');
        $state = $states->getValue($coordinator)[$camera->id];
        $event = CameraAnalysisEvent::create(['camera_id' => $camera->id, 'generation' => $state['generation'], 'started_at' => now()]);
        $state['event'] = $event;
        $states->setValue($coordinator, [$camera->id => $state]);
        $at = microtime(true);
        $flight = ['id' => $camera->id, 'generation' => $state['generation'], 'event' => $event, 'previous' => [], 'at' => $at];
        $response = ['camera_id' => $camera->id, 'generation' => $state['generation'], 'captured_at' => $at,
            'model_version' => 'fixture', 'model_sha256' => str_repeat('a', 64),
            'detections' => [['class' => 'cat', 'confidence' => .8, 'box' => [.1, .1, .5, .5]]]];
        $receive = new ReflectionMethod($coordinator, 'receiveResult');
        $receive->invoke($coordinator, $flight, [...$response, 'captured_at' => 'bad'], true);
        $receive->invoke($coordinator, $flight, [...$response, 'camera_id' => 999], true);
        $receive->invoke($coordinator, $flight, [...$response, 'detections' => [['box' => []]]], true);
        $this->assertSame(3, $event->fresh()->skipped_frames);
        $this->assertSame(0, $event->fresh()->analyzed_frames);
        $receive->invoke($coordinator, $flight, $response, true);
        $this->assertSame(1, $event->fresh()->analyzed_frames);
        $this->assertSame(str_repeat('a', 64), $event->fresh()->model_sha256);
        $flight['at'] += .7;
        $receive->invoke($coordinator, $flight, [...$response, 'captured_at' => $flight['at'], 'model_sha256' => str_repeat('b', 64)], true);
        $this->assertSame(4, $event->fresh()->skipped_frames);
        $this->assertNotNull($event->fresh()->ended_at);
        $this->assertDatabaseCount('camera_analysis_labels', 0);
        $this->assertSame([], $states->getValue($coordinator)[$camera->id]['previous']);
    }

    public function test_event_boundaries_preserve_microseconds_and_deleted_metadata_is_ignored(): void
    {
        $camera = $this->camera();
        $event = CameraAnalysisEvent::create(['camera_id' => $camera->id, 'generation' => str_repeat('a', 64),
            'started_at' => '2026-10-01 12:00:00.250000', 'ended_at' => '2026-10-01 12:00:00.750000']);
        $this->assertSame('250000', $event->fresh()->started_at->format('u'));
        $this->assertSame('750000', $event->fresh()->ended_at->format('u'));
        $service = new AnalysisLabels;
        $service->record($event, [['class' => 'dog', 'confidence' => .9, 'times' => [microtime(true)]]]);
        $label = $event->labels()->first();
        $event->delete();
        $service->associate($label);
        $service->record($event, [['class' => 'dog', 'confidence' => .9, 'times' => [microtime(true)]]]);
        $this->assertDatabaseCount('camera_analysis_labels', 0);
    }

    public function test_sampler_rejects_stale_buffered_frames_and_is_safe_to_close_twice(): void
    {
        $sampler = new AnalysisSampler('/usr/bin/ffmpeg', $this->camera(), [], ['-re', '-f', 'lavfi', '-i', 'color=blue:size=160x90:rate=3']);
        (new ReflectionProperty($sampler, 'lastReadAt'))->setValue($sampler, microtime(true) - 3);
        try {
            $sampler->read();
            $this->fail('Stale frames must not receive fresh capture timestamps.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('stale', $exception->getMessage());
        } finally {
            $sampler->close();
            $sampler->close();
        }
    }
}
