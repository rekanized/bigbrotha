<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->unsignedTinyInteger('recording_motion_pre_roll_seconds')->nullable()->after('motion_sensitivity');
            $table->unsignedTinyInteger('recording_motion_post_trigger_seconds')->nullable()->after('recording_motion_pre_roll_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn([
                'recording_motion_pre_roll_seconds',
                'recording_motion_post_trigger_seconds',
            ]);
        });
    }
};