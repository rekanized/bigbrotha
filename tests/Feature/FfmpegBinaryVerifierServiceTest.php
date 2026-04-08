<?php

namespace Tests\Feature;

use App\Services\FfmpegBinaryVerifierService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FfmpegBinaryVerifierServiceTest extends TestCase
{
    public function test_it_verifies_configured_ffmpeg_and_ffprobe_binaries(): void
    {
        $directory = storage_path('framework/testing/ffmpeg-binaries');

        File::ensureDirectoryExists($directory);

        $ffmpegBinary = $directory.'/ffmpeg';
        $ffprobeBinary = $directory.'/ffprobe';

        File::put($ffmpegBinary, "#!/bin/sh\necho 'ffmpeg version 7.1-static'\n");
        File::put($ffprobeBinary, "#!/bin/sh\necho 'ffprobe version 7.1-static'\n");

        chmod($ffmpegBinary, 0755);
        chmod($ffprobeBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);

        $snapshot = app(FfmpegBinaryVerifierService::class)->verify();

        $this->assertSame($ffmpegBinary, $snapshot['ffmpeg']['configured']);
        $this->assertSame($ffmpegBinary, $snapshot['ffmpeg']['resolved']);
        $this->assertTrue($snapshot['ffmpeg']['executable']);
        $this->assertTrue($snapshot['ffmpeg']['successful']);
        $this->assertSame('ffmpeg version 7.1-static', $snapshot['ffmpeg']['output']);

        $this->assertSame($ffprobeBinary, $snapshot['ffprobe']['configured']);
        $this->assertSame($ffprobeBinary, $snapshot['ffprobe']['resolved']);
        $this->assertTrue($snapshot['ffprobe']['executable']);
        $this->assertTrue($snapshot['ffprobe']['successful']);
        $this->assertSame('ffprobe version 7.1-static', $snapshot['ffprobe']['output']);
    }

    public function test_it_reports_missing_or_non_executable_binaries_without_running_them(): void
    {
        $missingDirectory = storage_path('framework/testing/ffmpeg-binaries-missing');
        $missingFfmpegBinary = $missingDirectory.'/ffmpeg';
        $missingFfprobeBinary = $missingDirectory.'/ffprobe';

        config()->set('ffmpeg.ffmpeg.binaries', [$missingFfmpegBinary]);
        config()->set('ffmpeg.ffprobe.binaries', [$missingFfprobeBinary]);

        $snapshot = app(FfmpegBinaryVerifierService::class)->verify();

        $this->assertSame($missingFfmpegBinary, $snapshot['ffmpeg']['configured']);
        $this->assertNull($snapshot['ffmpeg']['resolved']);
        $this->assertFalse($snapshot['ffmpeg']['executable']);
        $this->assertFalse($snapshot['ffmpeg']['successful']);
        $this->assertNull($snapshot['ffmpeg']['output']);

        $this->assertSame($missingFfprobeBinary, $snapshot['ffprobe']['configured']);
        $this->assertNull($snapshot['ffprobe']['resolved']);
        $this->assertFalse($snapshot['ffprobe']['executable']);
        $this->assertFalse($snapshot['ffprobe']['successful']);
        $this->assertNull($snapshot['ffprobe']['output']);
    }

    public function test_it_reports_a_process_failure_without_throwing(): void
    {
        $directory = storage_path('framework/testing/ffmpeg-binaries-crash');

        File::ensureDirectoryExists($directory);

        $ffmpegBinary = $directory.'/ffmpeg';
        $ffprobeBinary = $directory.'/ffprobe';

        File::put($ffmpegBinary, "#!/bin/sh\nkill -SEGV $$\n");
        File::put($ffprobeBinary, "#!/bin/sh\necho 'ffprobe version 7.1-static'\n");

        chmod($ffmpegBinary, 0755);
        chmod($ffprobeBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);

        $snapshot = app(FfmpegBinaryVerifierService::class)->verify();

        $this->assertSame($ffmpegBinary, $snapshot['ffmpeg']['configured']);
        $this->assertSame($ffmpegBinary, $snapshot['ffmpeg']['resolved']);
        $this->assertTrue($snapshot['ffmpeg']['executable']);
        $this->assertFalse($snapshot['ffmpeg']['successful']);
        $this->assertNotNull($snapshot['ffmpeg']['output']);

        $this->assertSame($ffprobeBinary, $snapshot['ffprobe']['configured']);
        $this->assertSame($ffprobeBinary, $snapshot['ffprobe']['resolved']);
        $this->assertTrue($snapshot['ffprobe']['executable']);
        $this->assertTrue($snapshot['ffprobe']['successful']);
    }
}
