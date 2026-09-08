<?php

namespace Tests\Feature;

use App\Livewire\Recordings\TimelineRail;
use App\Livewire\Recordings\TimelineReview;
use App\Livewire\Recordings\TimelineStage;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\CameraStorageService;
use App\Services\RecordingReviewAssetService;
use App\Services\RecordingTimelineReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class TimelineReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_preserves_a_requested_camera_and_absolute_focus_on_reload(): void
    {
        $user = User::factory()->create();
        $first = Camera::query()->create(['name' => 'First', 'local_ip' => '192.0.2.1']);
        $second = Camera::query()->create(['name' => 'Second', 'local_ip' => '192.0.2.2']);
        foreach ([$first, $second] as $camera) {
            CameraRecording::query()->create([
                'camera_id' => $camera->id,
                'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
                'status' => CameraRecording::STATUS_RECORDED,
                'scheduled_for' => '2026-04-03 12:00:00',
                'started_at' => '2026-04-03 12:00:00',
                'ended_at' => '2026-04-03 12:01:00',
                'relative_path' => 'test.mp4',
            ]);
        }
        $params = [
            'date_from' => '2026-04-03', 'date_to' => '2026-04-03',
            'active_camera_id' => $second->id, 'focus_at' => '2026-04-03T12:00:15.000Z',
        ];
        $this->actingAs($user)->get(route('recordings.timeline', $params))
            ->assertOk()
            ->assertSeeHtml('data-active-camera-id="'.$second->id.'"')
            ->assertSeeHtml('data-focus-ms="'.Carbon::parse($params['focus_at'])->valueOf().'"');
        $params['camera_ids'] = [$first->id];
        $this->get(route('recordings.timeline', $params))->assertOk()
            ->assertSeeHtml('data-active-camera-id="'.$first->id.'"');
    }

    public function test_adjacent_clip_navigation_crosses_gaps_but_respects_camera_status_and_range(): void
    {
        $user = User::factory()->create();
        $camera = Camera::query()->create(['name' => 'Gate', 'local_ip' => '192.0.2.3']);
        $other = Camera::query()->create(['name' => 'Other', 'local_ip' => '192.0.2.4']);
        $create = fn (string $time, array $extra = []) => CameraRecording::query()->create(array_merge([
            'camera_id' => $camera->id, 'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED, 'scheduled_for' => $time,
            'started_at' => $time, 'ended_at' => Carbon::parse($time, 'UTC')->addMinute(),
            'relative_path' => 'test.mp4',
        ], $extra));
        $first = $create('2026-04-03 10:00:00');
        $last = $create('2026-04-03 18:00:00', ['started_at' => null]);
        $create('2026-04-03 12:00:00', ['camera_id' => $other->id]);
        $create('2026-04-03 13:00:00', ['status' => CameraRecording::STATUS_FAILED]);
        $create('2026-04-03 14:00:00', ['relative_path' => null]);
        $create('2026-04-04 01:00:00');
        $params = [
            'camera' => $camera->id,
            'day_start_ms' => Carbon::parse('2026-04-03', 'UTC')->valueOf(),
            'day_end_ms' => Carbon::parse('2026-04-04', 'UTC')->valueOf(),
            'focus_ms' => $first->started_at->valueOf(), 'direction' => 'next',
        ];
        $this->actingAs($user)->getJson(route('recordings.timeline.stage-data', $params))
            ->assertOk()->assertJsonPath('segment.id', $last->id);
        $params['focus_ms'] = $last->scheduled_for->valueOf();
        $this->getJson(route('recordings.timeline.stage-data', $params))->assertOk()->assertJsonPath('segment', null);
        $params['direction'] = 'previous';
        $this->getJson(route('recordings.timeline.stage-data', $params))->assertOk()->assertJsonPath('segment.id', $first->id);
        $params['focus_ms'] = $first->started_at->valueOf();
        $this->getJson(route('recordings.timeline.stage-data', $params))->assertOk()->assertJsonPath('segment', null);
        $overlapping = $create('2026-04-02 23:59:40');
        $params['focus_ms'] = $overlapping->started_at->valueOf();
        $this->getJson(route('recordings.timeline.stage-data', $params))->assertOk()->assertJsonPath('segment', null);
        $params['direction'] = 'next';
        $this->getJson(route('recordings.timeline.stage-data', $params))->assertOk()->assertJsonPath('segment.id', $first->id);
        $params['direction'] = 'invalid';
        $this->getJson(route('recordings.timeline.stage-data', $params))->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson(route('recordings.timeline.stage-data', $params))->assertUnauthorized();
    }

    public function test_timeline_review_initializes_focus_state_from_payload(): void
    {
        Livewire::test(TimelineReview::class, [
            'timelineCameraOptions' => [
                ['id' => 7, 'name' => 'North Gate', 'local_ip' => '192.168.1.70'],
            ],
            'reviewTiles' => [
                [
                    'cameraId' => 7,
                    'cameraName' => 'North Gate',
                    'cameraIp' => '192.168.1.70',
                    'hasFocusSegment' => true,
                    'segmentCount' => 1,
                    'previewTimeLabel' => '2026-04-03 12:00:00 UTC',
                ],
            ],
            'timelinePayload' => [
                'dayStartMs' => 0,
                'dayEndMs' => 12_000,
                'focusAtMs' => 4_000,
                'activeCameraId' => 7,
            ],
        ])
            ->assertSet('focusAtMs', 4_000)
            ->assertSet('activeCameraId', 7);
    }

    public function test_timeline_review_keeps_large_segment_maps_out_of_public_livewire_state(): void
    {
        $component = Livewire::test(TimelineReview::class, [
            'timelineCameraOptions' => [
                ['id' => 7, 'name' => 'North Gate', 'local_ip' => '192.168.1.70'],
            ],
            'reviewTiles' => [
                [
                    'cameraId' => 7,
                    'cameraName' => 'North Gate',
                    'cameraIp' => '192.168.1.70',
                    'hasFocusSegment' => true,
                    'segmentCount' => 1,
                    'previewTimeLabel' => '2026-04-03 12:00:00 UTC',
                    'previewThumbnailUrl' => 'https://example.test/recordings/north-gate-thumb.jpg',
                ],
            ],
            'timelinePayload' => [
                'dayStartMs' => 0,
                'dayEndMs' => 12_000,
                'focusAtMs' => 4_000,
                'activeCameraId' => 7,
            ],
        ]);

        $reviewTiles = $component->get('reviewTiles');

        $this->assertIsArray($reviewTiles);
        $this->assertCount(1, $reviewTiles);
        $this->assertSame('North Gate', $reviewTiles[0]['cameraName']);
        $this->assertSame('NG', $reviewTiles[0]['cameraInitials']);
        $this->assertSame('North Gate camera preview', $reviewTiles[0]['cameraPreviewAlt']);
        $this->assertTrue($reviewTiles[0]['cameraPreviewAvailable']);
        $this->assertSame('https://example.test/recordings/north-gate-thumb.jpg', $reviewTiles[0]['cameraPreviewUrl']);
        $this->assertSame(1, $reviewTiles[0]['segmentCount']);
        $this->assertTrue($reviewTiles[0]['hasFocusSegment']);
        $this->assertArrayNotHasKey('segments', $reviewTiles[0]);
    }

    public function test_timeline_review_prefers_controller_supplied_initial_payloads(): void
    {
        Livewire::test(TimelineReview::class, [
            'timelineCameraOptions' => [
                ['id' => 7, 'name' => 'North Gate', 'local_ip' => '192.168.1.70'],
            ],
            'reviewTiles' => [
                [
                    'cameraId' => 7,
                    'cameraName' => 'North Gate',
                    'cameraIp' => '192.168.1.70',
                    'hasFocusSegment' => true,
                    'segmentCount' => 1,
                    'previewTimeLabel' => '2026-04-03 12:00:00 UTC',
                ],
            ],
            'timelinePayload' => [
                'dayStartMs' => 0,
                'dayEndMs' => 3_600_000,
                'focusAtMs' => 5_000,
                'activeCameraId' => 7,
            ],
            'initialCurrentSegment' => [
                'id' => 81,
                'cameraId' => 7,
                'startMs' => 2_000,
                'endMs' => 8_000,
                'durationSeconds' => 6,
                'streamUrl' => 'https://example.test/recordings/north-gate-stream.mp4',
                'timeLabel' => '2026-04-03 12:00:00 UTC',
                'modeLabel' => 'Movement clip',
                'durationLabel' => '6 s',
                'fileSizeLabel' => '12.00 MB',
                'downloadUrl' => 'https://example.test/recordings/north-gate-download.mkv',
            ],
            'initialRailSegments' => [[
                'id' => 81,
                'cameraId' => 7,
                'startMs' => 2_000,
                'endMs' => 8_000,
                'midpointMs' => 5_000,
                'topPercent' => 12,
                'renderHeightPercent' => 18,
                'captureMode' => 'motion',
                'timeLabel' => '2026-04-03 12:00:00 UTC',
                'modeLabel' => 'Movement clip',
                'thumbnailUrl' => 'https://example.test/recordings/north-gate-thumb.jpg',
                'scheduledLabel' => '2026-04-03 12:00:00 UTC',
            ]],
            'initialRailWindowStartMs' => 0,
            'initialRailWindowEndMs' => 3_600_000,
        ])
            ->assertSeeHtml('data-recording-id="81"')
            ->assertSeeHtml('data-role="rail-segment"');
    }

    public function test_timeline_route_does_not_probe_recording_files_during_initial_render(): void
    {
        $user = User::factory()->create();
        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/in-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Window segment',
        ]);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])->makePartial();
        $storage->shouldNotReceive('recordingExists');
        $this->app->instance(CameraStorageService::class, $storage);

        $this->actingAs($user)
            ->get(route('recordings.timeline', [
                'camera_ids' => [$camera->id],
                'date_from' => '2026-04-03',
                'date_to' => '2026-04-03',
            ]))
            ->assertOk()
            ->assertSee('Timeline Review')
            ->assertSeeHtml('data-recording-id="'.$recording->id.'"');
    }

    public function test_timeline_review_shell_uses_client_owned_rail_loading(): void
    {
        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $inWindow = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/in-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Window segment',
        ]);

        $this->writeRecordedSegment($inWindow);

        Livewire::test(TimelineReview::class, [
            'timelineCameraOptions' => [
                ['id' => $camera->id, 'name' => 'North Gate', 'local_ip' => '192.168.1.70'],
            ],
            'reviewTiles' => [
                [
                    'cameraId' => $camera->id,
                    'cameraName' => 'North Gate',
                    'cameraIp' => '192.168.1.70',
                    'hasFocusSegment' => true,
                    'segmentCount' => 2,
                    'previewTimeLabel' => '2026-04-03 12:00:00 UTC',
                ],
            ],
            'timelinePayload' => [
                'dayStartMs' => now()->utc()->setDate(2026, 4, 3)->startOfDay()->valueOf(),
                'dayEndMs' => now()->utc()->setDate(2026, 4, 5)->startOfDay()->valueOf(),
                'focusAtMs' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0)->valueOf(),
                'activeCameraId' => $camera->id,
            ],
        ])
            ->assertSeeHtml('data-role="camera-switch"')
            ->assertSeeHtml('data-rail-url="'.route('recordings.timeline.rail-data', ['camera' => $camera]).'"')
            ->assertDontSeeHtml('wire:click="selectCamera(');
    }

    public function test_camera_summaries_use_the_saved_camera_fleet_preview_for_switcher_images(): void
    {
        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $previewPath = app(CameraStorageService::class)->writableAbsolutePath('cameras/'.$camera->id.'/previews/north-gate.png');
        File::ensureDirectoryExists(dirname($previewPath));
        File::put($previewPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII='));

        $camera->forceFill([
            'metadata' => [
                'rtsp_profiles' => [[
                    'name' => 'Main stream',
                    'uri' => 'rtsp://north-gate/stream1',
                    'preview_path' => 'cameras/'.$camera->id.'/previews/north-gate.png',
                    'preview_generated_at' => '2026-04-03 12:00:00 UTC',
                ]],
            ],
        ])->save();

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/in-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Window segment',
        ]);

        $summaries = app(RecordingTimelineReviewService::class)->buildCameraSummaries(
            collect([$camera->fresh()]),
            collect([$recording]),
            now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 30),
            now()->utc()->setDate(2026, 4, 3)->startOfDay(),
            now()->utc()->setDate(2026, 4, 4)->startOfDay(),
        );

        $this->assertSame(
            route('camera-fleet.preview', ['camera' => $camera, 'profileIndex' => 0]),
            $summaries->first()['cameraPreviewUrl'] ?? null,
        );
        $this->assertSame(
            route('camera-fleet.preview', ['camera' => $camera, 'profileIndex' => 0]),
            $summaries->first()['previewThumbnailUrl'] ?? null,
        );
    }

    public function test_camera_summaries_do_not_build_full_review_payloads(): void
    {
        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/in-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Window segment',
        ]);

        $reviewAssets = \Mockery::mock(RecordingReviewAssetService::class);
        $reviewAssets->shouldNotReceive('timelinePlaybackMetadata');

        $service = new RecordingTimelineReviewService(
            app(ApplicationSettingsService::class),
            $reviewAssets,
        );

        $summaries = $service->buildCameraSummaries(
            collect([$camera]),
            collect([$recording]),
            now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 30),
            now()->utc()->setDate(2026, 4, 3)->startOfDay(),
            now()->utc()->setDate(2026, 4, 4)->startOfDay(),
        );

        $this->assertSame(
            app(ApplicationSettingsService::class)->formatTimeRange(
                now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
                now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
                'H:i:s',
            ),
            $summaries->first()['previewTimeLabel'] ?? null,
        );
    }

    public function test_timeline_playback_metadata_skips_manifest_probes_for_network_storage(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/in-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Window segment',
        ]);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])->makePartial();
        $storage->shouldReceive('usingNetworkStorage')->andReturn(true);
        $storage->shouldReceive('reviewSpritesStoredLocally')->andReturn(true);
        $storage->shouldReceive('privateFileExists')
            ->once()
            ->with('review-sprites/cameras/'.$camera->id.'/recordings/2026/04/03/_review/in-window/scrub-sprite.jpg')
            ->andReturn(true);
        $storage->shouldNotReceive('resolveReviewAssetAbsolutePath');
        $this->app->instance(CameraStorageService::class, $storage);

        $metadata = app(RecordingReviewAssetService::class)->timelinePlaybackMetadata($recording);

        $this->assertSame(RecordingReviewAssetService::STATUS_MISSING, $metadata['status'] ?? null);
        $this->assertFalse((bool) ($metadata['ready'] ?? true));
        $this->assertTrue((bool) ($metadata['scrub']['available'] ?? false));
        $this->assertSame('review-sprites/cameras/'.$camera->id.'/recordings/2026/04/03/_review/in-window/scrub-sprite.jpg', $metadata['scrub']['relative_path'] ?? null);
    }

    public function test_recording_review_payload_uses_public_fallback_thumbnail_for_network_storage(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/in-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Window segment',
        ]);

        $reviewAssets = \Mockery::mock(RecordingReviewAssetService::class);
        $reviewAssets->shouldReceive('timelinePlaybackMetadata')
            ->once()
            ->andReturn([
                'status' => RecordingReviewAssetService::STATUS_MISSING,
                'duration_seconds' => 60,
                'scrub' => [
                    'relative_path' => 'review-sprites/cameras/'.$camera->id.'/recordings/2026/04/03/_review/in-window/scrub-sprite.jpg',
                    'frame_count' => 6,
                    'frame_interval_seconds' => 10,
                    'frame_width' => 128,
                    'frame_height' => 72,
                    'columns' => 4,
                    'rows' => 2,
                    'available' => true,
                ],
            ]);

        $service = new RecordingTimelineReviewService(
            app(ApplicationSettingsService::class),
            $reviewAssets,
        );

        $payload = $service->recordingReviewPayload(
            $recording,
            now()->utc()->setDate(2026, 4, 3)->startOfDay(),
            now()->utc()->setDate(2026, 4, 4)->startOfDay(),
        );

        $this->assertSame(asset('img/recording-preview-missing.svg'), $payload['thumbnailUrl'] ?? null);
        $this->assertSame(route('recordings.preview-sprite', ['recording' => $recording]), $payload['thumbnailSpriteUrl'] ?? null);
        $this->assertSame(route('recordings.preview-sprite', ['recording' => $recording]), $payload['scrubSpriteUrl'] ?? null);
    }

    public function test_timeline_review_initial_stage_falls_back_to_the_latest_clip_before_focus(): void
    {
        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/in-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Window segment',
        ]);

        $this->writeRecordedSegment($recording);

        Livewire::test(TimelineReview::class, [
            'timelineCameraOptions' => [
                ['id' => $camera->id, 'name' => 'North Gate', 'local_ip' => '192.168.1.70'],
            ],
            'reviewTiles' => [
                [
                    'cameraId' => $camera->id,
                    'cameraName' => 'North Gate',
                    'cameraIp' => '192.168.1.70',
                    'hasFocusSegment' => false,
                    'segmentCount' => 1,
                    'previewTimeLabel' => '2026-04-03 12:00:00 UTC',
                ],
            ],
            'timelinePayload' => [
                'dayStartMs' => now()->utc()->setDate(2026, 4, 3)->startOfDay()->valueOf(),
                'dayEndMs' => now()->utc()->setDate(2026, 4, 4)->startOfDay()->valueOf(),
                'focusAtMs' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 5, 0)->valueOf(),
                'activeCameraId' => $camera->id,
            ],
        ])
            ->assertSeeHtml('data-recording-id="'.$recording->id.'"');
    }

    public function test_timeline_review_stage_selector_prefers_the_latest_clip_before_focus_when_no_exact_match_exists(): void
    {
        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $previousRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/in-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Window segment',
        ]);

        $nextRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 10, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 10, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 11, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/next-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Next window segment',
        ]);

        $this->writeRecordedSegment($previousRecording);
        $this->writeRecordedSegment($nextRecording);

        $selectedRecording = app(RecordingTimelineReviewService::class)->selectRecordingForStage(
            collect([$previousRecording, $nextRecording]),
            now()->utc()->setDate(2026, 4, 3)->setTime(12, 5, 0),
        );

        $this->assertInstanceOf(CameraRecording::class, $selectedRecording);
        $this->assertSame($previousRecording->id, $selectedRecording->id);
    }

    public function test_timeline_stage_renders_audio_toggle_state(): void
    {
        Livewire::test(TimelineStage::class, [
            'tile' => [
                'cameraName' => 'North Gate',
            ],
            'segment' => [
                'id' => 81,
                'startMs' => 2_000,
                'endMs' => 8_000,
                'durationSeconds' => 6,
                'streamUrl' => 'https://example.test/recordings/north-gate-stream.mp4',
                'timeLabel' => '2026-04-03 12:00:00 UTC',
                'modeLabel' => 'Motion',
                'durationLabel' => '00:06',
                'fileSizeLabel' => '12 MB',
            ],
        ])
            ->assertSeeHtml('data-audio-state="muted"')
            ->assertSeeHtml('data-playback-state="playing"')
            ->assertSeeHtml('aria-pressed="false"')
            ->assertSeeHtml('data-role="playback-toggle"')
            ->assertSeeHtml('data-role="companion-audio"')
            ->assertSeeHtml('data-role="audio-volume-slider"')
            ->assertSee('100%')
            ->assertSee('Playback stream')
            ->assertSeeHtml('src="https://example.test/recordings/north-gate-stream.mp4"')
            ->assertSeeHtml('data-direct-stream-url="https://example.test/recordings/north-gate-stream.mp4"')
            ->assertDontSeeHtml(' controls');

        Livewire::test(TimelineStage::class, [
            'tile' => [
                'cameraName' => 'North Gate',
            ],
            'segment' => [
                'id' => 81,
                'startMs' => 2_000,
                'endMs' => 8_000,
                'durationSeconds' => 6,
                'streamUrl' => 'https://example.test/recordings/north-gate-stream.mp4',
                'timeLabel' => '2026-04-03 12:00:00 UTC',
                'modeLabel' => 'Motion',
                'durationLabel' => '00:06',
                'fileSizeLabel' => '12 MB',
            ],
        ])
            ->assertSeeHtml('data-audio-state="muted"')
            ->assertSeeHtml('aria-pressed="false"')
            ->assertSeeHtml('src="https://example.test/recordings/north-gate-stream.mp4"');

        Livewire::test(TimelineStage::class, [
            'tile' => [
                'cameraName' => 'North Gate',
            ],
            'segment' => [
                'id' => 81,
                'startMs' => 2_000,
                'endMs' => 8_000,
                'durationSeconds' => 6,
                'streamUrl' => 'https://example.test/recordings/north-gate-stream.mp4',
                'timeLabel' => '2026-04-03 12:00:00 UTC',
                'modeLabel' => 'Motion',
                'durationLabel' => '00:06',
                'fileSizeLabel' => '12 MB',
            ],
        ])
            ->assertSee('Playback stream')
            ->assertSeeHtml('src="https://example.test/recordings/north-gate-stream.mp4"');
    }

    public function test_timeline_rail_uses_js_owned_focus_targets_without_livewire_click_dispatch(): void
    {
        Livewire::test(TimelineRail::class, [
            'tile' => [
                'cameraName' => 'North Gate',
                'cameraId' => 7,
            ],
            'initialSegments' => [
                [
                    'id' => 81,
                    'startMs' => 2_000,
                    'endMs' => 8_000,
                    'midpointMs' => 5_000,
                    'topPercent' => 12,
                    'renderHeightPercent' => 18,
                    'captureMode' => 'motion',
                    'timeLabel' => '2026-04-03 12:00:00 UTC',
                    'modeLabel' => 'Movement clip',
                    'thumbnailUrl' => 'https://example.test/recordings/north-gate-thumb.jpg',
                    'scheduledLabel' => '2026-04-03 12:00:00 UTC',
                ],
            ],
            'initialWindowStartMs' => 0,
            'initialWindowEndMs' => 3_600_000,
            'focusAtMs' => 5_000,
            'focusLabel' => '2026-04-03 12:00:05 UTC',
            'reviewRangeLabel' => '2026-04-03 00:00:00 UTC - 2026-04-03 23:59:59 UTC',
            'dayStartMs' => 0,
            'dayEndMs' => 3_600_000,
            'activeSegmentId' => 81,
        ])
            ->assertSeeHtml('data-role="rail-segment"')
            ->assertSeeHtml('data-role="rail-thumbnail"')
            ->assertSeeHtml('data-focus-ms="2000"')
            ->assertSeeHtml('data-focus-ms="900000"')
            ->assertSeeHtml('data-tick-kind="secondary"')
            ->assertSeeHtml('recording-review-focus__rail-tick--secondary')
            ->assertSeeHtml('data-role="rail-tick-label"')
            ->assertSeeHtml('data-role="rail-segments-json"')
            ->assertSeeHtml('data-role="rail-viewport"')
            ->assertSeeHtml('aria-busy="false"')
            ->assertSeeHtml('data-role="visible-range-label"')
            ->assertSeeHtml('data-role="rail-status"')
            ->assertSeeHtml('data-role="zoom-out"')
            ->assertSeeHtml('data-role="zoom-reset"')
            ->assertSeeHtml('data-role="zoom-in"')
            ->assertSeeHtml('data-role="focus-cursor"')
            ->assertSeeHtml('role="slider"')
            ->assertSeeHtml('aria-orientation="vertical"')
            ->assertSeeHtml('aria-valuemin="0"')
            ->assertSeeHtml('aria-valuemax="3599000"')
            ->assertSeeHtml('aria-valuenow="5000"')
            ->assertDontSeeHtml('wire:click="$dispatch(\'timeline-focus-selected\'');
    }

    public function test_timeline_rail_drops_overlapping_thumbnails_instead_of_shifting_them_far_from_their_clip(): void
    {
        $component = Livewire::test(TimelineRail::class, [
            'tile' => [
                'cameraName' => 'North Gate',
                'cameraId' => 7,
            ],
            'initialSegments' => [
                [
                    'id' => 81,
                    'startMs' => 0,
                    'endMs' => 60_000,
                    'midpointMs' => 30_000,
                    'topPercent' => 0,
                    'renderHeightPercent' => 0.833333,
                    'captureMode' => 'continuous',
                    'timeLabel' => '2026-04-03 12:00:00 UTC',
                    'modeLabel' => 'Continuous clip',
                    'thumbnailUrl' => 'https://example.test/recordings/north-gate-thumb-a.jpg',
                    'scheduledLabel' => '2026-04-03 12:00:00 UTC',
                ],
                [
                    'id' => 82,
                    'startMs' => 60_000,
                    'endMs' => 120_000,
                    'midpointMs' => 90_000,
                    'topPercent' => 0.833333,
                    'renderHeightPercent' => 0.833333,
                    'captureMode' => 'continuous',
                    'timeLabel' => '2026-04-03 12:01:00 UTC',
                    'modeLabel' => 'Continuous clip',
                    'thumbnailUrl' => 'https://example.test/recordings/north-gate-thumb-b.jpg',
                    'scheduledLabel' => '2026-04-03 12:01:00 UTC',
                ],
            ],
            'initialWindowStartMs' => 0,
            'initialWindowEndMs' => 7_200_000,
            'focusAtMs' => 90_000,
            'focusLabel' => '2026-04-03 12:01:30 UTC',
            'reviewRangeLabel' => '2026-04-03 12:00:00 UTC - 2026-04-03 14:00:00 UTC',
            'dayStartMs' => 0,
            'dayEndMs' => 7_200_000,
            'timelineZoomScale' => 1,
            'activeSegmentId' => 82,
        ]);

        $html = $component->html();

        $this->assertSame(1, substr_count($html, 'data-role="rail-thumbnail"'));
        $this->assertMatchesRegularExpression('/data-role="rail-thumbnail"[^>]*data-recording-id="82"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/data-role="rail-thumbnail"[^>]*data-recording-id="81"/s', $html);
    }

    private function writeRecordedSegment(CameraRecording $recording, string $contents = 'recorded-segment'): void
    {
        if (! is_string($recording->relative_path) || trim($recording->relative_path) === '') {
            return;
        }

        $absolutePath = app(CameraStorageService::class)->writableAbsolutePath($recording->relative_path);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $contents);

        $recording->forceFill([
            'file_size_bytes' => filesize($absolutePath) ?: null,
        ])->save();
    }
}
