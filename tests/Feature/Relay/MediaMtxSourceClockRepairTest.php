<?php

namespace Tests\Feature\Relay;

use App\Models\Camera;
use App\Services\Relay\MediaMtxConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
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
        config()->set('ffmpeg.relay_source.clock_repair_camera_ids', []);
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

    public function test_duration_repair_rebuilds_both_tracks_only_on_selected_sources(): void
    {
        $selected = $this->camera('Damaged Clock');
        $healthy = $this->camera('Healthy Clock');
        $reordered = $this->camera('Reordered Clock', bFrames: 2);
        $forced = $this->camera('Forced Compatibility Clock', forceTranscode: true);
        config()->set('ffmpeg.relay_source.clock_repair_camera_ids', [$selected->id, $reordered->id, $forced->id]);
        $config = app(MediaMtxConfigService::class)->buildConfig();

        foreach ([$selected] as $camera) {
            foreach ([0, 1] as $index) {
                $path = "camera-{$camera->id}-source-profile-{$index}";
                $this->assertClockMode($config, $path, true);
                $block = $this->pathBlock($config, $path);
                $this->assertStringContainsString('-bsf:v', $block);
                $this->assertStringContainsString('-bsf:a', $block);
                $this->assertStringContainsString('PREV_OUTPTS+PREV_OUTDURATION', $block);
                $this->assertStringContainsString('-c copy', $block);
                $this->assertStringNotContainsString('-bsf:', $this->pathBlock($config, "camera-{$camera->id}-live-profile-{$index}"));
            }
        }

        foreach ([$healthy, $reordered, $forced] as $camera) {
            foreach ([0, 1] as $index) {
                $path = "camera-{$camera->id}-source-profile-{$index}";
                $this->assertClockMode($config, $path, false);
                $this->assertStringNotContainsString('-bsf:', $this->pathBlock($config, $path));
            }
        }
    }

    public function test_packet_duration_repair_removes_clock_jumps_without_reencoding_or_assuming_fps(): void
    {
        if (! is_executable('/usr/bin/ffmpeg') || ! is_executable('/usr/bin/ffprobe')) {
            $this->markTestSkipped('The Docker runtime supplies FFmpeg and ffprobe.');
        }

        $camera = $this->camera('Repair Fixture');
        config()->set('ffmpeg.relay_source.clock_repair_camera_ids', [$camera->id]);
        $service = app(MediaMtxConfigService::class);
        $command = (new \ReflectionMethod($service, 'buildRunOnDemandCommand'))->invoke(
            $service, '/usr/bin/ffmpeg', $service->cameraSourceRelayDefinition($camera, 0),
        );
        $this->assertSame(1, preg_match('/ -bsf:v (.*?) -bsf:a /', $command, $matches));
        // Decode escapeshellarg's single-quote escaping, then run the actual
        // generated filter through FFmpeg using a private synthetic fixture.
        $clock = str_replace("'\\''", "'", substr($matches[1], 1, -1));

        foreach ([10, 15, 25] as $fps) {
            $input = storage_path("app/private/clock-jumps-{$fps}.mkv");
            $output = storage_path("app/private/clock-repaired-{$fps}.mkv");

            try {
                (new Process([
                    '/usr/bin/ffmpeg', '-nostdin', '-v', 'error',
                    '-f', 'lavfi', '-i', "testsrc2=size=160x90:rate={$fps}:duration=2",
                    '-f', 'lavfi', '-i', 'sine=sample_rate=8000:duration=2',
                    '-c:v', 'libx264', '-threads:v', '1', '-preset', 'ultrafast', '-tune', 'zerolatency',
                    '-c:a', 'pcm_s16le',
                    '-bsf:v', "setts=ts=TS+floor(N/{$fps})*0.4/TB",
                    '-bsf:a', 'setts=ts=TS+floor(TS*TB)*0.4/TB', $input,
                ]))->setTimeout(20)->mustRun();

                (new Process([
                    '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-i', $input,
                    '-map', '0', '-c', 'copy', '-bsf:v', $clock, '-bsf:a', $clock, $output,
                ]))->setTimeout(20)->mustRun();

                $probe = static function (string $file): array {
                    $process = (new Process([
                        '/usr/bin/ffprobe', '-v', 'error', '-show_packets', '-show_data_hash', 'sha256',
                        '-show_entries', 'packet=stream_index,pts_time,duration_time,data_hash', '-of', 'json', $file,
                    ]))->setTimeout(20)->mustRun();

                    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['packets'];
                };
                $before = $probe($input);
                $after = $probe($output);

                foreach ([0, 1] as $stream) {
                    $original = array_values(array_filter($before, static fn (array $packet): bool => $packet['stream_index'] === $stream));
                    $repaired = array_values(array_filter($after, static fn (array $packet): bool => $packet['stream_index'] === $stream));
                    $this->assertSame(array_column($original, 'data_hash'), array_column($repaired, 'data_hash'), 'Every encoded packet must remain unchanged.');
                    $maxOriginalGap = 0.0;

                    foreach ($repaired as $index => $packet) {
                        if ($index === 0) {
                            continue;
                        }

                        $maxOriginalGap = max($maxOriginalGap, (float) $original[$index]['pts_time'] - (float) $original[$index - 1]['pts_time']);
                        $gap = (float) $packet['pts_time'] - (float) $repaired[$index - 1]['pts_time'];
                        $expected = $stream === 0 ? 1 / $fps : (float) $repaired[$index - 1]['duration_time'];
                        $this->assertEqualsWithDelta($expected, $gap, 0.002, 'Presentation spacing must follow frame/sample duration.');
                    }

                    $this->assertGreaterThan(0.3, $maxOriginalGap, 'The fixture must reproduce clock jumps.');
                }
            } finally {
                @unlink($input);
                @unlink($output);
            }
        }
    }

    private function pathBlock(string $config, string $path): string
    {
        preg_match('/^  '.preg_quote($path, '/').":\n(?:    .*\n)*/m", $config, $matches);
        $this->assertNotEmpty($matches, 'Expected the '.$path.' relay path.');

        return $matches[0];
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
