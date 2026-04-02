<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('live_walls', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('grid_columns')->default(3);
            $table->string('default_tile_orientation')->default('landscape');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('live_wall_tiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_wall_id')->constrained()->cascadeOnDelete();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->string('orientation')->default('landscape');
            $table->unsignedTinyInteger('column_span')->default(1);
            $table->unsignedTinyInteger('row_span')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['live_wall_id', 'camera_id']);
            $table->index(['live_wall_id', 'position']);
        });

        $timestamp = now();
        $defaultWallId = DB::table('live_walls')->insertGetId([
            'name' => 'Primary Wall',
            'slug' => 'primary-wall',
            'description' => 'Default operator wall for configured camera tiles.',
            'grid_columns' => 3,
            'default_tile_orientation' => 'landscape',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $enabledCameraIds = DB::table('cameras')
            ->where('is_enabled', true)
            ->orderBy('name')
            ->pluck('id');

        $tiles = [];

        foreach ($enabledCameraIds as $index => $cameraId) {
            $tiles[] = [
                'live_wall_id' => $defaultWallId,
                'camera_id' => $cameraId,
                'position' => $index + 1,
                'orientation' => 'landscape',
                'column_span' => 1,
                'row_span' => 1,
                'is_enabled' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($tiles !== []) {
            DB::table('live_wall_tiles')->insert($tiles);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_wall_tiles');
        Schema::dropIfExists('live_walls');
    }
};