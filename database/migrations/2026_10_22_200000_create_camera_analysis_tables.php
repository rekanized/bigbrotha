<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->boolean('ai_enabled')->default(false);
            $table->unsignedSmallInteger('ai_profile_index')->nullable();
        });
        Schema::table('camera_recordings', fn (Blueprint $table) => $table->index(['updated_at', 'id'], 'recordings_analysis_updated_index'));
        Schema::create('camera_analysis_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->string('generation', 64);
            $table->timestamp('started_at', 6);
            $table->timestamp('ended_at', 6)->nullable();
            $table->unsignedInteger('analyzed_frames')->default(0);
            $table->unsignedInteger('skipped_frames')->default(0);
            $table->string('model_version')->nullable();
            $table->string('model_sha256', 64)->nullable();
            $table->timestamps();
            $table->index(['camera_id', 'started_at']);
            $table->index(['ended_at', 'updated_at']);
        });
        Schema::create('camera_analysis_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('camera_analysis_events')->cascadeOnDelete();
            $table->string('class', 24);
            $table->float('confidence');
            $table->json('observed_at');
            $table->boolean('was_associated')->default(false);
            $table->timestamps();
            $table->unique(['event_id', 'class']);
        });
        Schema::create('camera_analysis_label_recording', function (Blueprint $table): void {
            $table->foreignId('label_id')->constrained('camera_analysis_labels')->cascadeOnDelete();
            $table->foreignId('recording_id')->constrained('camera_recordings')->cascadeOnDelete();
            $table->primary(['label_id', 'recording_id']);
            $table->index(['recording_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::table('camera_recordings', fn (Blueprint $table) => $table->dropIndex('recordings_analysis_updated_index'));
        Schema::dropIfExists('camera_analysis_label_recording');
        Schema::dropIfExists('camera_analysis_labels');
        Schema::dropIfExists('camera_analysis_events');
        Schema::table('cameras', fn (Blueprint $table) => $table->dropColumn(['ai_enabled', 'ai_profile_index']));
    }
};
