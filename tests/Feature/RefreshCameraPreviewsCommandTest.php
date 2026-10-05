<?php

namespace Tests\Feature;

use App\Models\Camera;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RefreshCameraPreviewsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refreshes_saved_previews_for_eligible_cameras(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.0.2.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'username' => 'operator',
            'password' => 'secret',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MainStream',
                        'token' => 'profile_main',
                        'uri' => 'rtsp://192.0.2.67:554/stream1',
                        'path' => '/stream1',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-refresh-command.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
printf '%s' '{"streams":[{"codec_name":"h264","width":1280,"height":720}]}'
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-refresh-command.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
output="${!#}"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        Artisan::call('camera-fleet:refresh-previews');

        $camera->refresh();

        $profile = $camera->rtspProfiles()[0];

        $this->assertSame('Healthy', $profile['probe_status']);
        $this->assertNotNull($profile['preview_path']);
        $this->assertFileExists(storage_path('app/private/'.$profile['preview_path']));
        $this->assertSame(0, $camera->metadata['preview_maintenance']['last_profile_index'] ?? null);
    }

    public function test_it_schedules_preview_refresh_every_thirty_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'camera-fleet:refresh-previews'));

        $this->assertNotNull($event);
        $this->assertSame('*/30 * * * *', $event->expression);
    }
}
