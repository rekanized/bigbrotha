<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->unsignedInteger('recording_motion_trigger_pixels')->nullable()->after('motion_sensitivity');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn('recording_motion_trigger_pixels');
        });
    }
};
