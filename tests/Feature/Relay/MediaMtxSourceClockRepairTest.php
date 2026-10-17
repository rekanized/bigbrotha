<?php

namespace Tests\Feature\Relay;

use App\Models\Camera;
use App\Services\Relay\MediaMtxConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaMtxSourceClockRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ffmpeg.ffmpeg.binaries', ['/bin/true']);
        config()->set('ffmpeg.relay_source.use_wallclock_timestamps', false);
        config()->set('ffmpeg.relay_source.wallclock_camera_ids', []);
        config()->set('ffmpeg.live.use_wallclock_timestamps', false);
    }

    public function test_source_profiles_preserve_media_timestamps_by_default(): void
    {
        $camera = $this->camera('Default Clock');
        $config = app(MediaMtxConfigService::class)->buildConfig();

        foreach ([0, 1] as $index) {
            $this->assertClockMode($config, "camera-{$camera->id}-source-profile-{$index}", false);
        }
    }

    public function test_selection_repairs_every_source_profile_without_rewriting_other_cameras_or_live_hops(): void
    {
        $selected = $this->camera('Selected Clock');
        $unselected = $this->camera('Healthy Clock');
        config()->set('ffmpeg.relay_source.wallclock_camera_ids', [$selected->id]);
        $service = app(MediaMtxConfigService::class);
        $config = $service->buildConfig();

        $this->assertTrue($service->cameraRecordingRelayDefinition($selected)['use_wallclock_timestamps']);
        $this->assertFalse($service->cameraRecordingRelayDefinition($unselected)['use_wallclock_timestamps']);

        foreach ([0, 1] as $index) {
            $this->assertClockMode($config, "camera-{$selected->id}-source-profile-{$index}", true);
            $this->assertClockMode($config, "camera-{$unselected->id}-source-profile-{$index}", false);
            $this->assertClockMode($config, "camera-{$selected->id}-live-profile-{$index}", false);
        }

        $this->assertClockMode($config, "camera-{$selected->id}-live", false);
        $this->assertClockMode($config, "camera-{$unselected->id}-live", false);
    }

    public function test_selected_b_frame_and_forced_compatibility_sources_preserve_their_media_ordering(): void
    {
        $reordered = $this->camera('Reordered Clock', bFrames: 2);
        $forced = $this->camera('Forced Compatibility Clock', forceTranscode: true);
        config()->set('ffmpeg.relay_source.wallclock_camera_ids', [$reordered->id, $forced->id]);
        $config = app(MediaMtxConfigService::class)->buildConfig();

        foreach ([$reordered, $forced] as $camera) {
            foreach ([0, 1] as $index) {
                $this->assertClockMode($config, "camera-{$camera->id}-source-profile-{$index}", false);
            }
        }
    }

    public function test_the_existing_global_source_override_still_applies_without_camera_selection(): void
    {
        $camera = $this->camera('Global Override');
        config()->set('ffmpeg.relay_source.use_wallclock_timestamps', true);
        $config = app(MediaMtxConfigService::class)->buildConfig();

        foreach ([0, 1] as $index) {
            $this->assertClockMode($config, "camera-{$camera->id}-source-profile-{$index}", true);
        }
    }

    private function camera(string $name, int $bFrames = 0, bool $forceTranscode = false): Camera
    {
        return Camera::query()->create([
            'name' => $name,
            'local_ip' => '192.0.2.10',
            'rtsp_port' => 554,
            'rtsp_path' => '/main',
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'live_transcode' => ['force_video_transcode' => $forceTranscode],
                'rtsp_profiles' => array_map(static fn (string $path): array => [
                    'uri' => 'rtsp://192.0.2.10:554/'.$path,
                    'video_codec' => 'h264',
                    'video_has_b_frames' => $bFrames,
                ], ['main', 'sub']),
            ],
        ]);
    }

    private function assertClockMode(string $config, string $path, bool $enabled): void
    {
        preg_match('/^  '.preg_quote($path, '/').":\n(?:    .*\n)*/m", $config, $matches);
        $this->assertNotEmpty($matches, 'Expected the '.$path.' relay path.');
        $this->assertStringContainsString("-use_wallclock_as_timestamps '".($enabled ? '1' : '0')."'", $matches[0]);
    }
}
