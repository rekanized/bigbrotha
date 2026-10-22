<?php

namespace Tests\Unit;

use App\Models\Camera;
use App\Services\RecordingMotionDetectorService;
use App\Services\RecordingMotionMaskService;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class RecordingMotionDetectorServiceTest extends TestCase
{
    public function test_latest_transition_matches_full_analysis_with_varied_rejection_windows(): void
    {
        $random = new Randomizer(new Mt19937(850));
        $detector = app(RecordingMotionDetectorService::class);
        $camera = new Camera([
            'recording_motion_mask' => app(RecordingMotionMaskService::class)->fullFrameMask(8, 8),
            'recording_motion_trigger_pixels' => 3,
        ]);
        $quiet = str_repeat(chr(40), 64);
        $local = $quiet;
        foreach ([18, 19, 26, 27] as $index) {
            $local[$index] = chr(160);
        }
        // Includes quiet frames, local movement, noise and global refreshes.
        $choices = [$quiet, $local, str_repeat(chr(255), 64), chr(255).substr($quiet, 1)];
        foreach ([1, 2, 5, 50] as $window) {
            config()->set('recording.motion.persistence_window_frames', $window);
            $output = '';
            for ($count = 1; $count <= 24; $count++) {
                $output .= $choices[$random->getInt(0, count($choices) - 1)];
                foreach ([true, false] as $lookahead) {
                    $full = $detector->analyzeFrames($camera, $output, $lookahead);
                    $latest = $detector->analyzeFrames($camera, $output, $lookahead, true);
                    $this->assertSame($full['frame_count'], $latest['frame_count']);
                    $this->assertSame($full['latest'] ?? null, $latest['latest'] ?? null);
                    // An incomplete decoder frame must never change the decision.
                    $this->assertSame($latest, $detector->analyzeFrames($camera, $output.'partial', $lookahead, true));
                    if (isset($latest['latest'])) {
                        $this->assertSame($latest['latest']['effective_trigger_pixels'], $latest['changed_pixels']);
                        $this->assertSame($latest['latest']['detected'], $latest['detected']);
                    }
                }
            }
        }
    }

    public function test_identical_frames_and_empty_masks_remain_quiet(): void
    {
        $detector = app(RecordingMotionDetectorService::class);
        $camera = new Camera([
            'recording_motion_mask' => app(RecordingMotionMaskService::class)->fullFrameMask(8, 8),
            'recording_motion_trigger_pixels' => 1,
        ]);
        $motion = $detector->analyzeFrames($camera, str_repeat(chr(127), 64 * 20), false);
        $this->assertFalse($motion['detected']);
        $this->assertSame([], $motion['changed_indexes']);
        $this->assertSame('quiet', $motion['latest']['reason']);
        $this->assertSame(20, $motion['frame_count']);

        $camera->recording_motion_mask = ['grid_width' => 8, 'grid_height' => 8, 'runs' => []];
        $motion = $detector->analyzeFrames($camera, str_repeat(chr(127), 64 * 20));
        $this->assertFalse($motion['detected']);
        $this->assertSame(0, $motion['selected_pixels']);
    }
}
