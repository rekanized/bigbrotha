<?php

namespace Tests\Unit;

use App\Services\RecordingMotionMaskService;
use Tests\TestCase;

class RecordingMotionMaskServiceTest extends TestCase
{
    public function test_bounds_match_selected_pixels_for_every_run_on_small_grids(): void
    {
        $service = app(RecordingMotionMaskService::class);

        foreach ([[1, 4], [4, 1], [4, 3], [7, 5]] as [$width, $height]) {
            for ($start = 0; $start < $width * $height; $start++) {
                for ($end = $start; $end < $width * $height; $end++) {
                    $mask = ['grid_width' => $width, 'grid_height' => $height, 'runs' => [[$start, $end]]];
                    $xs = $ys = [];
                    foreach ($service->selectedIndexes($mask) as $index) {
                        $xs[] = $index % $width;
                        $ys[] = intdiv($index, $width);
                    }

                    $this->assertSame([
                        'x' => min(95, (int) floor(min($xs) / $width * 100)),
                        'y' => min(95, (int) floor(min($ys) / $height * 100)),
                        'width' => max(5, (int) ceil((max($xs) - min($xs) + 1) / $width * 100)),
                        'height' => max(5, (int) ceil((max($ys) - min($ys) + 1) / $height * 100)),
                    ], $service->bounds($mask), json_encode($mask));
                }
            }
        }
    }

    public function test_bounds_handle_empty_disjoint_and_clipped_runs(): void
    {
        $service = app(RecordingMotionMaskService::class);
        $this->assertSame(['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100], $service->bounds([
            'grid_width' => 640, 'grid_height' => 640, 'runs' => [],
        ]));
        $this->assertSame(['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100], $service->bounds([
            'grid_width' => 4, 'grid_height' => 4, 'runs' => [[-5, 0], [15, 50]],
        ]));
        $this->assertSame(['x' => 25, 'y' => 25, 'width' => 50, 'height' => 50], $service->bounds([
            'grid_width' => 4, 'grid_height' => 4, 'runs' => [[10, 10], [5, 5]],
        ]));
    }

    public function test_it_builds_a_full_frame_mask_by_default(): void
    {
        $service = app(RecordingMotionMaskService::class);
        $mask = $service->fullFrameMask(4, 3);

        $this->assertSame(4, $mask['grid_width']);
        $this->assertSame(3, $mask['grid_height']);
        $this->assertSame(12, $mask['selected_pixels']);
        $this->assertSame([[0, 11]], $mask['runs']);
    }

    public function test_it_merges_runs_and_reports_bounds(): void
    {
        $service = app(RecordingMotionMaskService::class);
        $mask = $service->normalize([
            'grid_width' => 4,
            'grid_height' => 4,
            'runs' => [
                [0, 1],
                [2, 3],
                [8, 9],
            ],
        ]);

        $this->assertSame([[0, 3], [8, 9]], $mask['runs']);
        $this->assertSame(6, $mask['selected_pixels']);
        $this->assertSame([
            'x' => 0,
            'y' => 0,
            'width' => 100,
            'height' => 75,
        ], $service->bounds($mask));
    }
}
