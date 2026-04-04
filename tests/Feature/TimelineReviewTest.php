<?php

namespace Tests\Feature;

use App\Livewire\Recordings\TimelineReview;
use App\Livewire\Recordings\TimelineRail;
use App\Livewire\Recordings\TimelineStage;
use Livewire\Livewire;
use Tests\TestCase;

class TimelineReviewTest extends TestCase
{
    public function test_it_toggles_timeline_stage_audio_state(): void
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
                    'segmentCount' => 1,
                    'previewTimeLabel' => '2026-04-03 12:00:00 UTC',
                    'segments' => [
                        [
                            'id' => 81,
                            'startMs' => 2_000,
                            'endMs' => 8_000,
                            'durationSeconds' => 6,
                        ],
                    ],
                ],
            ],
            'timelinePayload' => [
                'dayStartMs' => 0,
                'dayEndMs' => 12_000,
                'focusAtMs' => 4_000,
                'activeCameraId' => 7,
            ],
        ])
            ->assertSet('isMuted', true)
            ->call('toggleAudio')
            ->assertSet('isMuted', false)
            ->call('toggleAudio')
            ->assertSet('isMuted', true);
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
                'streamUrl' => 'https://example.test/recordings/north-gate-stream.mp4',
                'timeLabel' => '2026-04-03 12:00:00 UTC',
                'modeLabel' => 'Motion',
                'durationLabel' => '00:06',
                'fileSizeLabel' => '12 MB',
            ],
            'isMuted' => true,
        ])
            ->assertSeeHtml('data-audio-state="muted"')
            ->assertSeeHtml('data-playback-state="playing"')
            ->assertSeeHtml('aria-pressed="false"')
            ->assertSeeHtml('data-role="playback-toggle"')
            ->assertSeeHtml('data-role="audio-volume-slider"')
            ->assertSeeHtml('data-role="scrub-preview-layer"')
            ->assertSeeHtml('data-active-layer-index="0"')
            ->assertSee('100%')
            ->assertSeeHtml('src="https://example.test/recordings/north-gate-preview.mp4"')
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
                'streamUrl' => 'https://example.test/recordings/north-gate-stream.mp4',
                'timeLabel' => '2026-04-03 12:00:00 UTC',
                'modeLabel' => 'Motion',
                'durationLabel' => '00:06',
                'fileSizeLabel' => '12 MB',
            ],
            'isMuted' => false,
        ])
            ->assertSeeHtml('data-audio-state="active"')
            ->assertSeeHtml('aria-pressed="true"');
    }

    public function test_timeline_rail_uses_js_owned_focus_targets_without_livewire_click_dispatch(): void
    {
        Livewire::test(TimelineRail::class, [
            'tile' => [
                'cameraName' => 'North Gate',
                'segments' => [
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
            ],
            'timelineTicks' => [
                [
                    'focusMs' => 3_000,
                    'topPercent' => 0,
                    'heightPercent' => 12,
                    'label' => '00:00',
                ],
            ],
            'focusAtMs' => 5_000,
            'focusLabel' => '2026-04-03 12:00:05 UTC',
            'reviewRangeLabel' => '2026-04-03 00:00:00 UTC - 2026-04-03 23:59:59 UTC',
            'dayStartMs' => 0,
            'dayEndMs' => 24_000,
            'activeSegmentId' => 81,
        ])
            ->assertSeeHtml('data-role="rail-segment"')
            ->assertSeeHtml('data-role="rail-thumbnail"')
            ->assertSeeHtml('data-focus-ms="2000"')
            ->assertDontSeeHtml('wire:click="$dispatch(\'timeline-focus-selected\'');
    }

    public function test_timeline_rail_drops_overlapping_thumbnails_instead_of_shifting_them_far_from_their_clip(): void
    {
        $component = Livewire::test(TimelineRail::class, [
            'tile' => [
                'cameraName' => 'North Gate',
                'segments' => [
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
            ],
            'timelineTicks' => [],
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