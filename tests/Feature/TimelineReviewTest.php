<?php

namespace Tests\Feature;

use App\Livewire\Recordings\TimelineReview;
use App\Livewire\Recordings\TimelineRail;
use App\Livewire\Recordings\TimelineStage;
use App\Models\Camera;
use App\Models\CameraRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimelineReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_review_select_focus_updates_server_focus_state_only(): void
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
            ->call('selectFocus', 8_000)
            ->assertSet('focusAtMs', 8_000)
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
        $this->assertSame(1, $reviewTiles[0]['segmentCount']);
        $this->assertTrue($reviewTiles[0]['hasFocusSegment']);
        $this->assertArrayNotHasKey('segments', $reviewTiles[0]);
    }

    public function test_timeline_review_loads_only_the_requested_rail_window(): void
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

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(18, 0, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(18, 0, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(18, 1, 0),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/out-of-window.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Out of window segment',
        ]);

        $component = Livewire::test(TimelineReview::class, [
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
        ]);

        $payload = $component->instance()->loadRailChunk(
            $camera->id,
            now()->utc()->setDate(2026, 4, 3)->setTime(11, 0, 0)->valueOf(),
            now()->utc()->setDate(2026, 4, 3)->setTime(13, 0, 0)->valueOf(),
        );

        $this->assertSame($camera->id, $payload['cameraId']);
        $this->assertCount(1, $payload['segments']);
        $this->assertSame($inWindow->id, $payload['segments'][0]['id']);
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
                'preferredStreamUrl' => 'https://example.test/recordings/north-gate-preview.mp4',
                'reviewStreamUrl' => 'https://example.test/recordings/north-gate-review.mp4',
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
            ->assertSeeHtml('data-role="scrub-preview-layer"')
            ->assertSeeHtml('data-active-layer-index="0"')
            ->assertSee('100%')
            ->assertSeeHtml('src="https://example.test/recordings/north-gate-preview.mp4"')
            ->assertSeeHtml('data-review-stream-url="https://example.test/recordings/north-gate-review.mp4"')
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
                'preferredStreamUrl' => 'https://example.test/recordings/north-gate-preview.mp4',
                'reviewStreamUrl' => 'https://example.test/recordings/north-gate-review.mp4',
                'streamUrl' => 'https://example.test/recordings/north-gate-stream.mp4',
                'timeLabel' => '2026-04-03 12:00:00 UTC',
                'modeLabel' => 'Motion',
                'durationLabel' => '00:06',
                'fileSizeLabel' => '12 MB',
            ],
        ])
            ->assertSeeHtml('data-audio-state="muted"')
            ->assertSeeHtml('aria-pressed="false"')
            ->assertSeeHtml('src="https://example.test/recordings/north-gate-preview.mp4"');
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
}