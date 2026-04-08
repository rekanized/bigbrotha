<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camera_recordings', function (Blueprint $table): void {
            $table->index([
                'camera_id',
                'status',
                'scheduled_for',
            ], 'camera_recordings_timeline_scheduled_idx');

            $table->index([
                'camera_id',
                'status',
                'started_at',
                'ended_at',
            ], 'camera_recordings_timeline_overlap_idx');
        });
    }

    public function down(): void
    {
        Schema::table('camera_recordings', function (Blueprint $table): void {
            $table->dropIndex('camera_recordings_timeline_scheduled_idx');
            $table->dropIndex('camera_recordings_timeline_overlap_idx');
        });
    }
};