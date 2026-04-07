<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->json('recording_motion_mask')->nullable()->after('recording_motion_area');
        });

        DB::table('cameras')
            ->orderBy('id')
            ->select(['id', 'recording_motion_area'])
            ->chunkById(100, function ($cameras): void {
                foreach ($cameras as $camera) {
                    $area = json_decode((string) ($camera->recording_motion_area ?? 'null'), true);

                    if (!is_array($area)) {
                        $mask = [
                            'version' => 1,
                            'grid_width' => 160,
                            'grid_height' => 90,
                            'selected_pixels' => 160 * 90,
                            'runs' => [[0, (160 * 90) - 1]],
                        ];
                    } else {
                        $x = max(0, min(95, (int) ($area['x'] ?? 0)));
                        $y = max(0, min(95, (int) ($area['y'] ?? 0)));
                        $width = max(5, min(100 - $x, (int) ($area['width'] ?? 100)));
                        $height = max(5, min(100 - $y, (int) ($area['height'] ?? 100)));
                        $gridWidth = 160;
                        $gridHeight = 90;
                        $startX = max(0, min($gridWidth - 1, (int) floor(($x / 100) * $gridWidth)));
                        $startY = max(0, min($gridHeight - 1, (int) floor(($y / 100) * $gridHeight)));
                        $endX = max($startX, min($gridWidth - 1, (int) ceil((($x + $width) / 100) * $gridWidth) - 1));
                        $endY = max($startY, min($gridHeight - 1, (int) ceil((($y + $height) / 100) * $gridHeight) - 1));
                        $runs = [];
                        $selectedPixels = 0;

                        for ($row = $startY; $row <= $endY; $row++) {
                            $run = [($row * $gridWidth) + $startX, ($row * $gridWidth) + $endX];
                            $runs[] = $run;
                            $selectedPixels += ($run[1] - $run[0]) + 1;
                        }

                        $mask = [
                            'version' => 1,
                            'grid_width' => $gridWidth,
                            'grid_height' => $gridHeight,
                            'selected_pixels' => $selectedPixels,
                            'runs' => $runs,
                        ];
                    }

                    DB::table('cameras')
                        ->where('id', $camera->id)
                        ->update(['recording_motion_mask' => json_encode($mask, JSON_THROW_ON_ERROR)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn('recording_motion_mask');
        });
    }
};