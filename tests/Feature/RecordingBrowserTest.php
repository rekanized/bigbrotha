<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\CameraRecording;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\CameraStorageService;
use App\Services\RecordingReviewAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RecordingBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_users_are_redirected_to_login_for_the_recordings_browser(): void
    {
        $this->get(route('recordings.index'))
            ->assertRedirect(route('login'));
    }

    public function test_operator_can_filter_the_recordings_browser(): void
    {
        $operator = User::factory()->create();

        $frontDoor = Camera::query()->create([
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

        $garage = Camera::query()->create([
            'name' => 'Garage',
            'local_ip' => '192.168.1.68',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
        ]);

        $frontDoorRecording = CameraRecording::query()->create([
            'camera_id' => $frontDoor->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subMinute(),
            'started_at' => now()->utc()->subMinute(),
            'ended_at' => now()->utc(),
            'relative_path' => 'cameras/'.$frontDoor->id.'/recordings/2026/04/03/front-door.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Continuous segment saved.',
        ]);

        CameraRecording::query()->create([
            'camera_id' => $garage->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_FAILED,
            'scheduled_for' => now()->utc()->subMinutes(2),
            'started_at' => now()->utc()->subMinutes(2),
            'ended_at' => now()->utc()->subMinute(),
            'relative_path' => null,
            'file_size_bytes' => null,
            'message' => 'ffmpeg failed to write the segment.',
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.index', [
                'search' => 'Garage',
                'status' => 'failed',
                'mode' => Camera::RECORDING_MODE_MOTION,
            ]))
            ->assertOk()
            ->assertSee('Garage')
            ->assertSee('ffmpeg failed to write the segment.')
            ->assertDontSee('Continuous segment saved.')
            ->assertDontSee(route('recordings.show', ['recording' => $frontDoorRecording]), false);
    }

    public function test_recordings_browser_displays_operator_timezone_labels(): void
    {
        app(ApplicationSettingsService::class)->saveAppTimezone('Europe/Amsterdam');

        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Garage',
            'local_ip' => '192.168.1.68',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 1),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/garage.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Continuous segment saved.',
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.index'))
            ->assertOk()
            ->assertSee('Filter by camera, status, capture mode, or recording date in Europe/Amsterdam')
            ->assertSee('2026-04-04 14:00');
    }

    public function test_timeline_rail_emits_precise_hour_offsets_for_segment_and_thumbnail_positions(): void
    {
        app(ApplicationSettingsService::class)->saveAppTimezone('Europe/Amsterdam');

        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Backyard',
            'local_ip' => '192.168.1.173',
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
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(18, 41, 5),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(18, 41, 5),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(18, 42, 5),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/backyard-204105.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Continuous segment saved.',
        ]);

        $response = $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.timeline', ['camera_ids' => [$camera->id]]));

        $response
            ->assertOk()
            ->assertSee('data-recording-id="'.$recording->id.'"', false)
            ->assertSee('data-start-ms="1775328065000"', false)
            ->assertSee('data-end-ms="1775328125000"', false)
            ->assertSee('2026-04-04 20:41:05 CEST');

        $this->assertTimelineSegmentPlacement(
            $response,
            $this->reviewWindowStartUtc($recording->started_at),
            $this->reviewWindowEndUtc($recording->ended_at),
            $recording->started_at,
            $recording->ended_at,
        );
    }

    public function test_timeline_rail_labels_and_positions_recordings_by_started_at_when_schedule_differs(): void
    {
        app(ApplicationSettingsService::class)->saveAppTimezone('Europe/Amsterdam');

        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Backyard',
            'local_ip' => '192.168.1.173',
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
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(19, 33, 2),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(19, 6, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(19, 7, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/backyard-shifted.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Continuous segment saved.',
        ]);

        $response = $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.timeline', ['camera_ids' => [$camera->id]]));

        $response
            ->assertOk()
            ->assertSee('data-recording-id="'.$recording->id.'"', false)
            ->assertSee('data-focus-ms="1775329560000"', false)
            ->assertSee('2026-04-04 21:06:00 CEST');

        $this->assertTimelineSegmentPlacement(
            $response,
            $this->reviewWindowStartUtc($recording->started_at),
            $this->reviewWindowEndUtc($recording->ended_at),
            $recording->started_at,
            $recording->ended_at,
        );
    }

    public function test_operator_can_open_the_recording_playback_screen_and_stream_the_segment(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Warehouse Entrance',
            'local_ip' => '192.168.1.90',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        app(CameraStorageService::class)->ensureCameraDirectories($camera);

        $absolutePath = storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/03/review-segment.mkv');
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'recorded-segment');

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subMinute(),
            'started_at' => now()->utc()->subMinute(),
            'ended_at' => now()->utc(),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/review-segment.mkv',
            'file_size_bytes' => filesize($absolutePath) ?: null,
            'message' => 'Recorded successfully.',
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakePlaybackFfmpegBinary()]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.show', ['recording' => $recording]))
            ->assertOk()
            ->assertSee(route('recordings.stream', ['recording' => $recording]), false)
            ->assertSee('Recorded successfully.');

        $streamResponse = $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.stream', ['recording' => $recording]));

        $streamResponse
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');

        $this->assertSame('playback-stream', $streamResponse->streamedContent());
    }

    public function test_operator_can_open_a_camera_selected_recording_timeline_without_live_feed_bootstrap(): void
    {
        $operator = User::factory()->create();

        $frontDoor = Camera::query()->create([
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

        $garage = Camera::query()->create([
            'name' => 'Garage',
            'local_ip' => '192.168.1.68',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
        ]);

        $frontDoorRecording = CameraRecording::query()->create([
            'camera_id' => $frontDoor->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 2)->setTime(8, 14),
            'started_at' => now()->utc()->setDate(2026, 4, 2)->setTime(8, 14),
            'ended_at' => now()->utc()->setDate(2026, 4, 2)->setTime(8, 15),
            'relative_path' => 'cameras/'.$frontDoor->id.'/recordings/2026/04/02/front-door.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Continuous segment saved.',
        ]);

        $frontDoorLatestRecording = CameraRecording::query()->create([
            'camera_id' => $frontDoor->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 14),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 14),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 15),
            'relative_path' => 'cameras/'.$frontDoor->id.'/recordings/2026/04/03/front-door-latest.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Continuous segment saved.',
        ]);

        $garageRecording = CameraRecording::query()->create([
            'camera_id' => $garage->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 14),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 14),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 15),
            'relative_path' => 'cameras/'.$garage->id.'/recordings/2026/04/03/garage.mkv',
            'file_size_bytes' => 2048,
            'message' => 'Motion segment saved.',
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.timeline', ['camera_ids' => [$frontDoor->id, $garage->id]]))
            ->assertOk()
            ->assertSee('recording-review__workspace', false)
            ->assertSee('Timeline Review')
            ->assertSee('data-role="focus-label">2026-04-03 14:14:00 CEST<', false)
            ->assertDontSee('Current stage')
            ->assertDontSee('Visible rail range')
            ->assertDontSee('Open playback')
            ->assertDontSee('Apply cameras')
            ->assertSee('data-role="rail-viewport"', false)
            ->assertSee('data-role="camera-switch"', false)
            ->assertSee('data-role="rail-segment"', false)
            ->assertSee('Switch which loaded camera is shown on the stage.')
            ->assertSee('Recorded feeds available in the camera strip.')
            ->assertSee('Download file')
            ->assertSee('60 s')
                ->assertSee('Garage')
            ->assertSee(route('recordings.stream', ['recording' => $frontDoorLatestRecording]), false)
            ->assertSee(route('recordings.preview-thumbnail', ['recording' => $frontDoorLatestRecording]), false)
            ->assertDontSee(route('live-wall.session', ['camera' => $frontDoor]), false);
    }

    public function test_timeline_preview_thumbnail_route_returns_a_placeholder_when_the_asset_is_missing(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Loading Dock',
            'local_ip' => '192.168.1.99',
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
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(14, 22),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(14, 22),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(14, 23),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/loading-dock.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Clip saved.',
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.preview-thumbnail', ['recording' => $recording]))
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml; charset=UTF-8')
            ->assertSee('Thumbnail not ready yet');
    }

    public function test_timeline_preview_stream_route_serves_the_generated_preview_asset(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Receiving Bay',
            'local_ip' => '192.168.1.100',
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
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(15, 10),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(15, 10),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(15, 11),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/receiving-bay.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Clip saved.',
        ]);

        $previewPath = app(CameraStorageService::class)->recordingReviewAssetAbsolutePath($recording->relative_path, 'preview.mp4', true);
        File::put($previewPath, 'preview-stream');
        $this->writeCurrentReviewManifest($recording, [
            'preview_relative_path' => app(CameraStorageService::class)->recordingRelativePathFromAbsolute($previewPath),
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.preview-stream', ['recording' => $recording]))
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');
    }

    public function test_timeline_preview_sprite_route_serves_the_generated_scrub_sprite(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Receiving Bay',
            'local_ip' => '192.168.1.100',
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
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(15, 10),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(15, 10),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(15, 11),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/receiving-bay.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Clip saved.',
        ]);

        $spritePath = app(CameraStorageService::class)->recordingReviewAssetAbsolutePath($recording->relative_path, 'scrub-sprite.jpg', true);
        File::put($spritePath, 'sprite-stream');
        $this->writeCurrentReviewManifest($recording, [
            'scrub_status' => RecordingReviewAssetService::STATUS_READY,
            'scrub_sprite_relative_path' => app(CameraStorageService::class)->recordingRelativePathFromAbsolute($spritePath),
            'scrub_frame_count' => 30,
            'scrub_frame_interval_seconds' => 2,
            'scrub_frame_width' => 160,
            'scrub_frame_height' => 90,
            'scrub_columns' => 4,
            'scrub_rows' => 8,
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.preview-sprite', ['recording' => $recording]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_timeline_initial_stage_prefers_the_preview_stream_when_a_cached_asset_exists(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Hallway 2nd floor',
            'local_ip' => '192.168.1.72',
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
            'scheduled_for' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 45),
            'started_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 45),
            'ended_at' => now()->utc()->setDate(2026, 4, 3)->setTime(12, 46),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/hallway-2nd-floor.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Clip saved.',
        ]);

        $previewPath = app(CameraStorageService::class)->recordingReviewAssetAbsolutePath($recording->relative_path, 'preview.mp4', true);
        File::put($previewPath, 'preview-stream');
        $this->writeCurrentReviewManifest($recording, [
            'preview_relative_path' => app(CameraStorageService::class)->recordingRelativePathFromAbsolute($previewPath),
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.timeline', ['camera_ids' => [$camera->id], 'focus_at' => '2026-04-03 12:45:30']))
            ->assertOk()
            ->assertSee('src="'.route('recordings.preview-stream', ['recording' => $recording]).'"', false);
    }

    public function test_timeline_exact_clip_boundary_selects_the_following_adjacent_segment(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.122',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $firstRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 1),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/north-gate-a.mkv',
            'file_size_bytes' => 1024,
            'message' => 'First clip saved.',
        ]);

        $secondRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 1),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 1),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 2),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/north-gate-b.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Second clip saved.',
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.timeline', ['camera_ids' => [$camera->id], 'focus_at' => '2026-04-04 12:01:00']))
            ->assertOk()
            ->assertSee('src="'.route('recordings.stream', ['recording' => $secondRecording]).'"', false)
            ->assertDontSee('src="'.route('recordings.stream', ['recording' => $firstRecording]).'"', false);
    }

    public function test_timeline_initial_stage_ignores_stale_cached_preview_assets(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Backyard',
            'local_ip' => '192.168.1.173',
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
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(17, 23),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(17, 23),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(17, 24),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/backyard.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Clip saved.',
        ]);

        $storage = app(CameraStorageService::class);
        $previewPath = $storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'preview.mp4', true);
        $manifestPath = $storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'manifest.json', true);

        File::put($previewPath, 'stale-preview-stream');
        File::put($manifestPath, json_encode([
            'status' => RecordingReviewAssetService::STATUS_READY,
            'version' => 'outdated-review-asset-version',
            'generated_at' => now()->utc()->toIso8601String(),
            'duration_seconds' => 60,
            'preview_relative_path' => $storage->recordingRelativePathFromAbsolute($previewPath),
            'thumbnail_relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/_review/backyard/poster.jpg',
            'thumbnail_offset_seconds' => 30,
            'preview_width' => 640,
            'thumbnail_width' => 320,
            'scrub_status' => RecordingReviewAssetService::STATUS_MISSING,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.timeline', ['camera_ids' => [$camera->id], 'focus_at' => '2026-04-04 17:23:30']))
            ->assertOk()
            ->assertSee('src="'.route('recordings.stream', ['recording' => $recording]).'"', false)
            ->assertDontSee('src="'.route('recordings.preview-stream', ['recording' => $recording]).'"', false)
            ->assertSee('Direct stream');
    }

    public function test_it_audits_expired_segments_without_deleting_them(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Audit Lane',
            'local_ip' => '192.168.1.181',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream11',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $relativePath = 'cameras/'.$camera->id.'/recordings/2026/04/01/audit-expired.mkv';
        $absolutePath = storage_path('app/private/'.$relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'audit-expired');

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subDays(2)->startOfMinute(),
            'started_at' => now()->utc()->subDays(2)->startOfMinute(),
            'ended_at' => now()->utc()->subDays(2)->startOfMinute()->addMinute(),
            'relative_path' => $relativePath,
            'file_size_bytes' => 13,
            'message' => 'Expired audit candidate.',
            'created_at' => now()->utc()->subDays(2)->startOfMinute(),
            'updated_at' => now()->utc()->subDays(2)->startOfMinute(),
        ]);

        \Illuminate\Support\Facades\Artisan::call('camera-recordings:prune-audit');

        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('audit-expired.mkv', $output);
        $this->assertStringContainsString('Audit Lane', $output);
        $this->assertStringContainsString('eligible for prune based on created_at retention cutoffs', $output);
        $this->assertFileExists($absolutePath);
        $this->assertDatabaseHas('camera_recordings', [
            'relative_path' => $relativePath,
        ]);
    }

    public function test_operator_can_download_the_original_recording_file(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Back Lot',
            'local_ip' => '192.168.1.91',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        app(CameraStorageService::class)->ensureCameraDirectories($camera);

        $absolutePath = storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/03/download-segment.mkv');
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'download-segment');

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subMinute(),
            'started_at' => now()->utc()->subMinute(),
            'ended_at' => now()->utc(),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/03/download-segment.mkv',
            'file_size_bytes' => filesize($absolutePath) ?: null,
            'message' => 'Ready for download.',
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.download', ['recording' => $recording]))
            ->assertOk()
            ->assertDownload('back-lot-'.$recording->scheduled_for->format('Ymd_His').'.mkv');
    }

    private function assertTimelineSegmentPlacement(
        TestResponse $response,
        Carbon $reviewWindowStart,
        Carbon $reviewWindowEnd,
        ?Carbon $segmentStart,
        ?Carbon $segmentEnd,
    ): void {
        $segmentStart ??= now()->utc();
        $segmentEnd ??= $segmentStart->copy()->addMinute();

        $timelineDurationMs = max(1, $reviewWindowEnd->valueOf() - $reviewWindowStart->valueOf());
        $segmentDurationMs = max(1000, $segmentEnd->valueOf() - $segmentStart->valueOf());
        $topRatio = max(0, min(1, ($segmentStart->valueOf() - $reviewWindowStart->valueOf()) / $timelineDurationMs));
        $heightRatio = max(1000 / $timelineDurationMs, $segmentDurationMs / $timelineDurationMs);
        $trackHeightPx = max(1800, (int) ceil(($timelineDurationMs / 3600000) * 88));

        $response
            ->assertSee('style="top: '.number_format($topRatio * 100, 6, '.', '').'%; height: '.number_format($heightRatio * 100, 6, '.', '').'%;"', false)
            ->assertSee('style="top: '.number_format($topRatio * $trackHeightPx, 3, '.', '').'px;"', false);
    }

    private function reviewWindowStartUtc(?Carbon $recordingStart): Carbon
    {
        return ($recordingStart ?? now()->utc())
            ->copy()
            ->setTimezone('Europe/Amsterdam')
            ->startOfDay()
            ->subDays(3)
            ->utc();
    }

    private function reviewWindowEndUtc(?Carbon $recordingEnd): Carbon
    {
        return ($recordingEnd ?? now()->utc())
            ->copy()
            ->setTimezone('Europe/Amsterdam')
            ->addDay()
            ->startOfDay()
            ->addDays(3)
            ->utc();
    }

    private function fakePlaybackFfmpegBinary(): string
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $binaryPath = $binaryDirectory.'/ffmpeg-recording-playback.sh';

        File::put($binaryPath, <<<'BASH'
#!/usr/bin/env bash
set -e
printf '%s' 'playback-stream'
BASH);
        chmod($binaryPath, 0755);

        return $binaryPath;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function writeCurrentReviewManifest(CameraRecording $recording, array $overrides = []): void
    {
        $storage = app(CameraStorageService::class);
        $reviewAssets = app(RecordingReviewAssetService::class);
        $manifestPath = $storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'manifest.json', true);
        $previewPath = $storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'preview.mp4', true);
        $thumbnailPath = $storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'poster.jpg', true);

        $assetVersionMethod = new \ReflectionMethod($reviewAssets, 'assetVersion');
        $assetVersionMethod->setAccessible(true);
        $currentVersion = $assetVersionMethod->invoke($reviewAssets, $recording);

        File::put($manifestPath, json_encode(array_merge([
            'status' => RecordingReviewAssetService::STATUS_READY,
            'version' => $currentVersion,
            'generated_at' => now()->utc()->toIso8601String(),
            'duration_seconds' => 60,
            'preview_relative_path' => $previewPath !== null ? $storage->recordingRelativePathFromAbsolute($previewPath) : null,
            'thumbnail_relative_path' => $thumbnailPath !== null ? $storage->recordingRelativePathFromAbsolute($thumbnailPath) : null,
            'thumbnail_offset_seconds' => 30,
            'preview_width' => 640,
            'thumbnail_width' => 320,
            'scrub_status' => RecordingReviewAssetService::STATUS_MISSING,
        ], $overrides), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}