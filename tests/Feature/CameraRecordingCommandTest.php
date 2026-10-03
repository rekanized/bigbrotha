<?php

namespace Tests\Feature;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use App\Services\CameraRecordingService;
use App\Services\CameraStorageService;
use App\Services\RecordingReviewAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\BuildsFakeRecordingFfmpegBinary;
use Tests\TestCase;

class CameraRecordingCommandTest extends TestCase
{
    use BuildsFakeRecordingFfmpegBinary;
    use RefreshDatabase;

    public function test_review_generation_bounds_decoding_encoding_and_filter_threads(): void
    {
        config()->set('ffmpeg.ffmpeg.threads', 2);
        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous-log-args')]);
        $camera = Camera::query()->create(['name' => 'Review cost', 'local_ip' => '192.168.1.88']);
        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id, 'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subMinute(), 'started_at' => now()->utc()->subMinute(),
            'ended_at' => now()->utc(), 'relative_path' => 'cameras/'.$camera->id.'/recordings/thread-test.mkv',
        ]);
        $path = app(CameraStorageService::class)->writableAbsolutePath($recording->relative_path);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'recorded-segment');
        app(RecordingReviewAssetService::class)->generateForRecording($recording);

        $commands = explode("---\n", File::get(storage_path('app/private/test-binaries/ffmpeg-segment-args.log')));
        $this->assertCount(3, $commands);
        foreach (array_slice($commands, 0, 2) as $index => $command) {
            $arguments = explode("\n", trim($command));
            $inputIndex = array_search('-i', $arguments, true);
            $this->assertIsInt($inputIndex);
            $limit = $index === 0 ? '2' : '1';
            $threadIndexes = array_keys($arguments, '-threads', true);
            $this->assertCount(2, $threadIndexes);
            $this->assertLessThan($inputIndex, $threadIndexes[0]);
            $this->assertGreaterThan($inputIndex, $threadIndexes[1]);
            foreach ($threadIndexes as $threadIndex) {
                $this->assertSame($limit, $arguments[$threadIndex + 1]);
            }
            $filterIndex = array_search('-filter_threads', $arguments, true);
            $this->assertIsInt($filterIndex);
            $this->assertSame('1', $arguments[$filterIndex + 1]);
        }
        $this->assertTrue(app(RecordingReviewAssetService::class)->hasReadyAssets($recording->fresh(), true));
    }

    public function test_it_records_a_continuous_segment_for_an_enabled_camera(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.168.1.67',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(Camera::RECORDING_MODE_CONTINUOUS, $recording->capture_mode);
        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertNotNull($recording->relative_path);
        $this->assertFileExists(storage_path('app/private/'.$recording->relative_path));

        $reviewAssets = app(RecordingReviewAssetService::class);
        $assetState = $reviewAssets->assetState($recording);

        $this->assertFileExists($reviewAssets->playbackAbsolutePath($recording));
        $this->assertFileExists($reviewAssets->scrubSpriteAbsolutePath($recording));
        $this->assertTrue((bool) ($assetState['thumbnail_available'] ?? false));
        $this->assertSame(RecordingReviewAssetService::STATUS_READY, $assetState['status']);

        $camera->refresh();

        $this->assertNotNull($camera->recording_last_recorded_at);
    }

    public function test_it_bootstraps_the_persistent_segmenter_when_a_legacy_continuous_row_runs(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'South Gate',
            'local_ip' => '192.168.1.76',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream7',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => Carbon::create(2026, 4, 4, 18, 2, 0, 'UTC'),
            'message' => 'Queued by scheduler.',
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous')]);

        $this->travelTo(Carbon::create(2026, 4, 4, 18, 3, 29, 'UTC'));

        try {
            app(CameraRecordingService::class)->processRecording($recording);
        } finally {
            $this->travelBack();
        }

        $recording->refresh();
        $importedRecording = CameraRecording::query()
            ->whereKeyNot($recording->getKey())
            ->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_SKIPPED, $recording->status);
        $this->assertStringContainsString('persistent segment muxer', (string) $recording->message);
        $this->assertSame(CameraRecording::STATUS_RECORDED, $importedRecording->status);
        $this->assertSame('2026-04-04 18:03:29', $importedRecording->scheduled_for?->utc()->toDateTimeString());
        $this->assertSame('2026-04-04 18:03:29', $importedRecording->started_at?->utc()->toDateTimeString());
        $this->assertSame('2026-04-04 18:04:29', $importedRecording->ended_at?->utc()->toDateTimeString());
        $this->assertSame(60, (int) $importedRecording->started_at?->diffInSeconds($importedRecording->ended_at));
    }

    public function test_it_uses_rtsp_timeout_arguments_that_are_compatible_with_the_host_ffmpeg_build(): void
    {
        config()->set('queue.default', 'sync');

        Camera::query()->create([
            'name' => 'Hallway 2nd floor',
            'local_ip' => '192.168.1.71',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('reject-rw-timeout')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertFileExists(app(RecordingReviewAssetService::class)->scrubSpriteAbsolutePath($recording));
    }

    public function test_queue_review_assets_requires_a_selective_filter(): void
    {
        Queue::fake();

        $this->artisan('camera-recordings:queue-review-assets')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_queue_review_assets_can_target_one_camera(): void
    {
        Queue::fake();
        config()->set('recording.review_assets.queue', 'review-assets');

        $selectedCamera = Camera::query()->create([
            'name' => 'Loading Dock',
            'local_ip' => '192.168.1.44',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $otherCamera = Camera::query()->create([
            'name' => 'Garage',
            'local_ip' => '192.168.1.45',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $selectedRecording = CameraRecording::query()->create([
            'camera_id' => $selectedCamera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 7, 9, 15, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 9, 15, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 9, 16, 0, 'UTC'),
            'relative_path' => 'cameras/'.$selectedCamera->id.'/recordings/2026/04/07/loading-dock.mkv',
            'message' => 'Clip saved.',
        ]);

        $otherRecording = CameraRecording::query()->create([
            'camera_id' => $otherCamera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 7, 9, 15, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 9, 15, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 9, 16, 0, 'UTC'),
            'relative_path' => 'cameras/'.$otherCamera->id.'/recordings/2026/04/07/garage.mkv',
            'message' => 'Clip saved.',
        ]);

        $this->artisan('camera-recordings:queue-review-assets', [
            '--camera_id' => (string) $selectedCamera->id,
        ])->assertSuccessful();

        Queue::assertPushedOn('review-assets', GenerateRecordingReviewAssetsJob::class);
        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($selectedRecording): bool {
            return $job->recordingId === $selectedRecording->id;
        });
        Queue::assertNotPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($otherRecording): bool {
            return $job->recordingId === $otherRecording->id;
        });
    }

    public function test_queue_review_assets_can_target_a_display_date_range(): void
    {
        Queue::fake();
        config()->set('recording.review_assets.queue', 'review-assets');

        app(ApplicationSettingsService::class)->saveAppTimezone('Europe/Amsterdam');

        $camera = Camera::query()->create([
            'name' => 'Back Entrance',
            'local_ip' => '192.168.1.81',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $matchingRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 7, 22, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 22, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 22, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/back-entrance-2230.mkv',
            'message' => 'Clip saved.',
        ]);

        $outsideRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 8, 22, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 8, 22, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 8, 22, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/08/back-entrance-2230-next-day.mkv',
            'message' => 'Clip saved.',
        ]);

        $this->artisan('camera-recordings:queue-review-assets', [
            '--date_from' => '2026-04-08',
            '--date_to' => '2026-04-08',
        ])->assertSuccessful();

        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($matchingRecording): bool {
            return $job->recordingId === $matchingRecording->id;
        });
        Queue::assertNotPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($outsideRecording): bool {
            return $job->recordingId === $outsideRecording->id;
        });
    }

    public function test_review_assets_only_queue_once_while_a_recording_is_already_pending(): void
    {
        Queue::fake();
        config()->set('recording.review_assets.queue', 'review-assets');

        $camera = Camera::query()->create([
            'name' => 'Back Entrance',
            'local_ip' => '192.168.1.81',
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
            'scheduled_for' => Carbon::create(2026, 4, 7, 22, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 22, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 22, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/back-entrance-2230.mkv',
            'message' => 'Clip saved.',
        ]);

        $reviewAssets = app(RecordingReviewAssetService::class);

        $this->assertTrue($reviewAssets->ensureQueued($recording, true));
        $this->assertFalse($reviewAssets->ensureQueued($recording, true));
        Queue::assertPushedOn('review-assets', GenerateRecordingReviewAssetsJob::class);
    }

    public function test_review_asset_queue_reconciliation_deletes_duplicates_and_moves_legacy_jobs(): void
    {
        config()->set('queue.default', 'database');
        config()->set('recording.review_assets.queue', 'review-assets');

        $camera = Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.170',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream170',
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
            'scheduled_for' => Carbon::create(2026, 4, 7, 11, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 11, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 11, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/atrium-review-assets.mkv',
            'message' => 'Clip saved.',
        ]);

        GenerateRecordingReviewAssetsJob::dispatch($recording->id)->onQueue('recordings');

        $queuedJob = DB::table('jobs')->first();

        $this->assertNotNull($queuedJob);

        DB::table('jobs')->insert([
            'queue' => 'recordings',
            'payload' => $queuedJob->payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->addSecond()->timestamp,
        ]);

        $this->artisan('camera-recordings:reconcile-review-asset-queue')
            ->assertSuccessful();

        $jobs = DB::table('jobs')->get();

        $this->assertCount(1, $jobs);
        $this->assertSame('review-assets', $jobs[0]->queue);
    }

    public function test_review_asset_generation_retries_scrub_generation_when_a_preview_is_ready_but_the_sprite_failed(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.170',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream170',
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
            'scheduled_for' => Carbon::create(2026, 4, 7, 11, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 11, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 11, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/atrium-review-assets.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Clip saved.',
        ]);

        $recordingPath = app(CameraStorageService::class)->writableAbsolutePath($recording->relative_path);
        File::ensureDirectoryExists(dirname($recordingPath));
        File::put($recordingPath, 'recorded-segment');

        $recording->forceFill([
            'file_size_bytes' => filesize($recordingPath) ?: null,
        ])->save();

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous')]);

        $reviewAssets = app(RecordingReviewAssetService::class);
        $reviewAssets->generateForRecording($recording);

        $manifestPath = app(CameraStorageService::class)->recordingReviewAssetAbsolutePath($recording->relative_path, 'manifest.json', true);
        $scrubSpriteAbsolutePath = $reviewAssets->scrubSpriteAbsolutePath($recording, true);

        @unlink($scrubSpriteAbsolutePath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertIsArray($manifest);

        file_put_contents($manifestPath, json_encode(array_merge($manifest, [
            'scrub_status' => RecordingReviewAssetService::STATUS_FAILED,
            'scrub_error_message' => 'Unable to generate the scrub preview sprite.',
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $reviewAssets->generateForRecording($recording->fresh());

        $this->assertFileExists($reviewAssets->scrubSpriteAbsolutePath($recording));
        $this->assertSame(
            RecordingReviewAssetService::STATUS_READY,
            app(RecordingReviewAssetService::class)->assetState($recording)['scrub_status']
        );
    }

    public function test_review_asset_generation_reports_the_missing_recording_lookup_path(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.171',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream171',
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
            'scheduled_for' => Carbon::create(2026, 4, 7, 12, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 12, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 12, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/missing-review-assets.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Clip saved.',
        ]);

        try {
            app(RecordingReviewAssetService::class)->generateForRecording($recording);
            $this->fail('Expected review asset generation to fail when the recording file is missing.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('The saved recording segment is not available on disk.', $exception->getMessage());
            $this->assertStringContainsString('relative_path='.$recording->relative_path, $exception->getMessage());
            $this->assertStringContainsString('storage_mode=local', $exception->getMessage());
            $this->assertStringContainsString('absolute_path='.storage_path('app/private/'.$recording->relative_path), $exception->getMessage());
        }
    }

    public function test_review_asset_job_marks_missing_recordings_as_failed_without_throwing(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.171',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream171',
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
            'scheduled_for' => Carbon::create(2026, 4, 7, 12, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 12, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 12, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/missing-review-assets-job.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Clip saved.',
        ]);

        $reviewAssets = app(RecordingReviewAssetService::class);

        $this->assertTrue($reviewAssets->markQueued($recording->getKey()));

        (new GenerateRecordingReviewAssetsJob($recording->getKey()))->handle($reviewAssets);

        $this->assertFalse($reviewAssets->isQueued($recording->getKey()));
        $this->assertSame(RecordingReviewAssetService::STATUS_FAILED, $reviewAssets->assetState($recording)['status']);
        $this->assertStringContainsString(
            'saved recording segment is no longer available on disk',
            (string) ($reviewAssets->manifest($recording)['error_message'] ?? ''),
        );
    }

    public function test_review_asset_job_keeps_retrying_when_the_recording_is_temporarily_unreachable(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.173',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream173',
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
            'scheduled_for' => Carbon::create(2026, 4, 7, 12, 35, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 12, 35, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 12, 36, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/unreachable-review-assets-job.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Clip saved.',
        ]);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])->makePartial();
        $storage->shouldReceive('resolveRecordingAbsolutePath')
            ->once()
            ->with($recording->relative_path, \Mockery::on(static fn (mixed $timeout): bool => is_int($timeout) && $timeout >= 30))
            ->andReturn(null);
        $storage->shouldReceive('missingRecordingSegmentMessage')
            ->once()
            ->with($recording->relative_path)
            ->andReturn('The saved recording segment is not available on disk. storage_mode=network');
        $storage->shouldReceive('recordingAvailability')
            ->once()
            ->with($recording->relative_path)
            ->andReturn(CameraStorageService::RECORDING_AVAILABILITY_UNREACHABLE);
        $this->app->instance(CameraStorageService::class, $storage);

        $reviewAssets = app(RecordingReviewAssetService::class);

        $this->assertTrue($reviewAssets->markQueued($recording->getKey()));

        try {
            (new GenerateRecordingReviewAssetsJob($recording->getKey()))->handle($reviewAssets);
            $this->fail('Expected a transiently unreachable recording to remain retryable.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('The saved recording segment is not available on disk.', $exception->getMessage());
        }

        $this->assertTrue($reviewAssets->isQueued($recording->getKey()));
    }

    public function test_review_asset_generation_requests_a_bounded_storage_read(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.172',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream172',
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
            'scheduled_for' => Carbon::create(2026, 4, 7, 13, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 13, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 13, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/bounded-read-review-assets.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Clip saved.',
        ]);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])->makePartial();
        $storage->shouldReceive('resolveRecordingAbsolutePath')
            ->once()
            ->with($recording->relative_path, \Mockery::on(static fn (mixed $timeout): bool => is_int($timeout) && $timeout >= 30))
            ->andReturn(null);
        $storage->shouldReceive('missingRecordingSegmentMessage')
            ->once()
            ->with($recording->relative_path)
            ->andReturn('The saved recording segment is not available on disk.');
        $this->app->instance(CameraStorageService::class, $storage);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The saved recording segment is not available on disk.');

        app(RecordingReviewAssetService::class)->generateForRecording($recording);
    }

    public function test_build_review_assets_can_limit_a_missing_backfill_to_the_newest_segments(): void
    {
        config()->set('queue.default', 'database');

        $camera = Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.170',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream170',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $olderRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 6, 11, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 6, 11, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 6, 11, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/06/atrium-older.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Older clip saved.',
        ]);

        $newerRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 7, 11, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 11, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 11, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/atrium-newer.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Newer clip saved.',
        ]);

        foreach ([$olderRecording, $newerRecording] as $recording) {
            $recordingPath = app(CameraStorageService::class)->writableAbsolutePath($recording->relative_path);
            File::ensureDirectoryExists(dirname($recordingPath));
            File::put($recordingPath, 'recorded-segment');

            $recording->forceFill([
                'file_size_bytes' => filesize($recordingPath) ?: null,
            ])->save();
        }

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous')]);

        $this->artisan('camera-recordings:build-review-assets', [
            '--missing' => true,
            '--limit' => '1',
        ])->assertSuccessful();

        $reviewAssets = app(RecordingReviewAssetService::class);

        $this->assertTrue($reviewAssets->hasReadyAssets($newerRecording->fresh(), true));
        $this->assertFalse($reviewAssets->hasReadyAssets($olderRecording->fresh(), true));
    }

    public function test_it_uses_the_segment_muxer_for_continuous_recording(): void
    {
        config()->set('queue.default', 'sync');

        Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.170',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream170',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous-log-args')]);

        Artisan::call('camera-recordings:tick');

        $argumentLog = storage_path('app/private/test-binaries/ffmpeg-segment-args.log');

        $this->assertFileExists($argumentLog);
        $arguments = (string) file_get_contents($argumentLog);

        $this->assertStringContainsString('-f', $arguments);
        $this->assertStringContainsString('segment', $arguments);
        $this->assertStringContainsString('-segment_time', $arguments);
        $this->assertStringContainsString('-reset_timestamps', $arguments);
        $this->assertStringContainsString('-strftime', $arguments);
        $this->assertStringContainsString('libx264', $arguments);
        $this->assertStringContainsString('expr:gte(t,n_forced*60)', $arguments);
        $this->assertStringNotContainsString('concat', $arguments);
    }

    public function test_continuous_recording_prefers_the_internal_source_relay_even_with_live_transcode_overrides(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('mediamtx.auth.reader_user', 'internal-reader');
        config()->set('mediamtx.auth.reader_pass', 'relay-pass');
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:8554');

        $inputLogPath = storage_path('app/private/test-binaries/ffmpeg-last-input.log');
        File::delete($inputLogPath);

        $camera = Camera::query()->create([
            'name' => 'HEVC Warehouse',
            'local_ip' => '192.168.1.171',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream171',
            'recording_rtsp_path' => '/stream171',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
            'metadata' => [
                'live_transcode' => [
                    'quality' => Camera::LIVE_TRANSCODE_QUALITY_QUALITY,
                    'rate_control' => Camera::LIVE_TRANSCODE_RATE_CONTROL_CBR,
                    'bitrate_kbps' => 3500,
                ],
                'rtsp_profiles' => [
                    [
                        'name' => 'mainStream',
                        'uri' => 'rtsp://192.168.1.171:554/stream171',
                        'encoding' => 'H265',
                        'video_codec' => 'hevc',
                    ],
                ],
            ],
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous-log-input')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(Camera::RECORDING_MODE_CONTINUOUS, $recording->capture_mode);
        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertSame(60, (int) $recording->started_at?->diffInSeconds($recording->ended_at));
        $this->assertFileExists($inputLogPath);
        $this->assertSame(
            'rtsp://internal-reader:relay-pass@127.0.0.1:8554/camera-'.$camera->id.'-source-profile-0',
            trim((string) File::get($inputLogPath)),
        );
    }
}
