<?php

namespace Tests\Unit;

use App\Services\RecordingMotionMaskService;
use Tests\TestCase;

class RecordingMotionMaskServiceTest extends TestCase
{
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