<?php

namespace Tests\Feature\Relay;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class LiveRelayBufferingTest extends TestCase
{
    public function test_sparse_camera_audio_does_not_hold_back_live_video_for_seconds(): void
    {
        if (!is_executable('/usr/bin/ffmpeg')) {
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
