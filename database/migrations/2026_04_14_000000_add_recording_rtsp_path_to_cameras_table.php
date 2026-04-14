<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->string('recording_rtsp_path')->nullable()->after('rtsp_path');
        });

        DB::table('cameras')
            ->orderBy('id')
            ->select(['id', 'rtsp_path', 'recording_profile_index', 'metadata'])
            ->chunkById(100, function ($cameras): void {
                foreach ($cameras as $camera) {
                    $metadata = $camera->metadata;

                    if (is_string($metadata)) {
                        $decoded = json_decode($metadata, true);
                        $metadata = is_array($decoded) ? $decoded : [];
                    }

                    if (!is_array($metadata)) {
                        $metadata = [];
                    }

                    $recordingPath = is_string($camera->rtsp_path) && trim($camera->rtsp_path) !== ''
                        ? trim($camera->rtsp_path)
                        : null;

                    $profileIndex = is_numeric($camera->recording_profile_index)
                        ? (int) $camera->recording_profile_index
                        : null;

                    $profiles = $metadata['rtsp_profiles'] ?? [];

                    if ($profileIndex !== null && is_array($profiles[$profileIndex] ?? null)) {
                        $profile = $profiles[$profileIndex];
                        $profilePath = $profile['path'] ?? null;

                        if (is_string($profilePath) && trim($profilePath) !== '') {
                            $recordingPath = trim($profilePath);
                        }
                    }

                    DB::table('cameras')
                        ->where('id', $camera->id)
                        ->update(['recording_rtsp_path' => $recordingPath]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn('recording_rtsp_path');
        });
    }
};