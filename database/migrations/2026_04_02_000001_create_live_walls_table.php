<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

        DB::table('live_walls')->insert([
            'name' => 'Primary Wall',
            'slug' => 'primary-wall',
            'description' => 'Default operator wall for configured camera tiles.',
            'grid_columns' => 3,
            'default_tile_orientation' => 'landscape',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('live_walls');
    }
};
