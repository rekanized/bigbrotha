<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camera_recordings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->string('capture_mode');
            $table->string('status')->default('queued');
            $table->unsignedSmallInteger('source_profile_index')->nullable();
            $table->timestamp('scheduled_for');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('relative_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->decimal('motion_score', 6, 4)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['camera_id', 'scheduled_for']);
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_recordings');
    }
};
