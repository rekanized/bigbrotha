<?php

namespace Tests\Feature\Relay;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class LiveRelayBufferingTest extends TestCase
{
    public function test_relay_hops_preserve_frame_spacing_when_packets_arrive_in_a_burst(): void
    {
        if (! is_executable('/usr/bin/ffmpeg') || ! is_executable('/usr/bin/ffprobe')) {
            $this->markTestSkipped('The Docker runtime supplies FFmpeg and ffprobe.');
        }

        $input = storage_path('app/private/paced-input.mkv');
        (new Process([
            '/usr/bin/ffmpeg', '-nostdin', '-v', 'error',
            '-f', 'lavfi', '-i', 'testsrc2=size=160x90:rate=15',
            '-f', 'lavfi', '-i', 'sine=sample_rate=48000',
            '-t', '2', '-c:v', 'libx264', '-threads:v', '1',
            '-preset', 'ultrafast', '-tune', 'zerolatency', '-c:a', 'aac', $input,
        ]))->setTimeout(20)->mustRun();

        foreach (['relay_source', 'live'] as $stage) {
            $output = storage_path('app/private/'.$stage.'-paced.mkv');
            // Deliberately omit -re: a queued burst must retain its media clock,
            // regardless of how quickly the relay can read the packets.
            (new Process([
                '/usr/bin/ffmpeg', '-nostdin', '-v', 'error',
                '-use_wallclock_as_timestamps', config('ffmpeg.'.$stage.'.use_wallclock_timestamps') ? '1' : '0',
                '-i', $input, '-map', '0', '-c:v', 'copy',
                '-c:a', $stage === 'live' ? 'libopus' : 'copy',
                '-max_interleave_delta', (string) config('ffmpeg.'.$stage.'.max_interleave_delta'),
                '-avoid_negative_ts', 'make_zero', $output,
            ]))->setTimeout(20)->mustRun();
            $probe = (new Process([
                '/usr/bin/ffprobe', '-v', 'error', '-select_streams', 'v:0',
                '-show_entries', 'packet=pts_time', '-of', 'json', $output,
            ]))->setTimeout(20)->mustRun();
            $packets = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR)['packets'];
            $this->assertCount(30, $packets, $stage.' must retain every frame');
            for ($index = 1; $index < count($packets); $index++) {
                $interval = (float) $packets[$index]['pts_time'] - (float) $packets[$index - 1]['pts_time'];
                $this->assertEqualsWithDelta(1 / 15, $interval, 0.002, $stage.' must retain 15 fps presentation timing');
            }
            $input = $output;
        }
    }

    public function test_sparse_camera_audio_does_not_hold_back_live_video_for_seconds(): void
    {
        if (! is_executable('/usr/bin/ffmpeg')) {
            $this->markTestSkipped('The Docker runtime supplies ffmpeg for this integration test.');
        }

        // Build a camera-like stream with continuous video and three seconds of
        // missing audio. Generate it first so input-filter scheduling cannot
        // obscure the output muxer's interleaving behavior under test.
        $fixture = storage_path('app/private/sparse-audio.mkv');
        $generate = new Process([
            '/usr/bin/ffmpeg', '-nostdin', '-v', 'error',
            '-f', 'lavfi', '-i', 'testsrc2=size=160x90:rate=10',
            '-f', 'lavfi', '-i', 'sine=sample_rate=48000',
            '-t', '6', '-map', '0:v', '-map', '1:a',
            '-c:v', 'libx264', '-threads:v', '1', '-preset', 'ultrafast', '-tune', 'zerolatency',
            '-af', 'aselect=not(between(t\,1\,4))', '-c:a', 'aac', $fixture,
        ]);
        $generate->setTimeout(20);
        $generate->mustRun();

        foreach (['relay_source', 'live'] as $stage) {
            $lastOutput = null;
            $maxGap = 0.0;
            $chunks = 0;
            $relay = new Process([
                '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-re', '-i', $fixture,
                '-map', '0', '-c', 'copy',
                '-max_interleave_delta', (string) config('ffmpeg.'.$stage.'.max_interleave_delta'),
                '-flush_packets', '1', '-f', 'matroska', '-cluster_time_limit', '100', 'pipe:1',
            ]);
            $relay->setTimeout(20);
            $relay->run(function (string $type, string $data) use (&$lastOutput, &$maxGap, &$chunks): void {
                if ($type !== Process::OUT || $data === '') {
                    return;
                }
                $now = hrtime(true) / 1e9;
                if ($lastOutput !== null) {
                    $maxGap = max($maxGap, $now - $lastOutput);
                }
                $lastOutput = $now;
                $chunks++;
            });
            $this->assertTrue($relay->isSuccessful(), $relay->getErrorOutput());
            $this->assertGreaterThan(20, $chunks, $stage.' must deliver video continuously');
            $this->assertLessThan(1.5, $maxGap, $stage.' must not buffer the three-second audio gap');
        }
    }
}
