<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('live_wall_tiles');
    }
};