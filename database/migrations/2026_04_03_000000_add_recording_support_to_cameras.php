<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->string('recording_mode')->default('off')->after('is_enabled')->index();
            $table->unsignedSmallInteger('recording_profile_index')->nullable()->after('recording_mode');
            $table->unsignedSmallInteger('recording_retention_days')->default(1)->after('recording_profile_index');
            $table->unsignedTinyInteger('motion_sensitivity')->default(35)->after('recording_retention_days');
            $table->json('recording_motion_area')->nullable()->after('motion_sensitivity');
            $table->timestamp('recording_last_motion_at')->nullable()->after('recording_motion_area');
            $table->timestamp('recording_last_recorded_at')->nullable()->after('recording_last_motion_at');
        });

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('camera_recordings');

        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn([
                'recording_mode',
                'recording_profile_index',
                'recording_retention_days',
                'motion_sensitivity',
                'recording_motion_area',
                'recording_last_motion_at',
                'recording_last_recorded_at',
            ]);
        });
    }
};