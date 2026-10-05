<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camera_motion_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('active_recording_id')->nullable()->constrained('camera_recordings')->nullOnDelete();
            $table->unsignedSmallInteger('source_profile_index')->nullable();
            $table->timestamp('last_processed_segment_at')->nullable();
            $table->timestamp('event_started_at')->nullable();
            $table->timestamp('last_motion_at')->nullable();
            $table->timestamp('finalize_after')->nullable();
            $table->timestamps();

            $table->index(['active_recording_id', 'finalize_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_motion_states');
    }
};
