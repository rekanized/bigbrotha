<?php

namespace Tests\Feature;

use App\Services\ApplicationSettingsService;
use App\Services\CameraStorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class CameraSmbTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_smb_upload_never_replaces_the_final_file(): void
    {
        $this->withFakeSmbClient(function (string $remoteRoot): void {
            File::put($remoteRoot.'/truncate-upload', '1');
            $localPath = $this->stagedFile('partial.mkv', 'complete-recording');

            $this->assertFalse($this->upload($localPath, '7/recordings/2026/09/29/partial.mkv'));
            $this->assertFileDoesNotExist($remoteRoot.'/cameras/7/recordings/2026/09/29/partial.mkv');
            $this->assertFileExists($localPath);
            $this->assertSame([], glob($remoteRoot.'/cameras/7/recordings/2026/09/29/*.uploading-*'));
        });
    }

    public function test_failed_replacement_restores_the_previous_smb_file(): void
    {
        $this->withFakeSmbClient(function (string $remoteRoot): void {
            $target = $remoteRoot.'/cameras/7/recordings/2026/09/29/replacement.mkv';
            File::ensureDirectoryExists(dirname($target));
            File::put($target, 'old');
            File::put($remoteRoot.'/fail-promotion', '1');
            $localPath = $this->stagedFile('replacement.mkv', 'new-recording');

            $this->assertFalse($this->upload($localPath, '7/recordings/2026/09/29/replacement.mkv'));
            $this->assertSame('old', File::get($target));
            $this->assertFileExists($localPath);
            $this->assertSame([], glob(dirname($target).'/*.replacing-*'));
        });
    }

    public function test_complete_smb_upload_is_promoted(): void
    {
        $this->withFakeSmbClient(function (string $remoteRoot): void {
            $localPath = $this->stagedFile('complete.mkv', 'complete-recording');

            $uploaded = $this->upload($localPath, '7/recordings/2026/09/29/complete.mkv');
            $this->assertTrue($uploaded, File::get($remoteRoot.'/commands.log'));
            $this->assertSame(
                'complete-recording',
                File::get($remoteRoot.'/cameras/7/recordings/2026/09/29/complete.mkv'),
            );
        });
    }

    public function test_recording_cleanup_preserves_files_even_when_the_adapter_reports_an_empty_directory(): void
    {
        $this->withFakeSmbClient(function (string $remoteRoot): void {
            $directory = $remoteRoot.'/cameras/7/recordings/2026/09/29';
            File::put($directory.'/source.mkv', 'source');
            File::put($directory.'/playback.mp4', 'published-recording');

            $disk = \Mockery::mock(Filesystem::class);
            $disk->shouldReceive('files')->andReturn([]);
            $disk->shouldReceive('directories')->andReturn([]);
            $disk->shouldReceive('deleteDirectory')->andReturnUsing(
                fn (string $path): bool => File::deleteDirectory($remoteRoot.'/cameras/'.$path),
            );
            $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $storage->shouldReceive('cameraDisk')->andReturn($disk);

            $this->assertTrue($storage->deleteRecordingFile('cameras/7/recordings/2026/09/29/source.mkv'));
            $this->assertFileDoesNotExist($directory.'/source.mkv');
            $this->assertSame('published-recording', File::get($directory.'/playback.mp4'));
            $this->assertStringNotContainsString(
                'rmdir "cameras/7/recordings/2026/09"',
                File::get($remoteRoot.'/commands.log'),
            );
        });
    }

    public function test_recording_cleanup_preserves_a_concurrent_upload(): void
    {
        $this->withFakeSmbClient(function (string $remoteRoot): void {
            $directory = $remoteRoot.'/cameras/7/recordings/2026/09/29';
            File::put($directory.'/source.mkv', 'source');
            File::put($remoteRoot.'/upload-during-cleanup', '1');

            $this->assertTrue(app(CameraStorageService::class)->deleteRecordingFile('cameras/7/recordings/2026/09/29/source.mkv'));
            $this->assertSame('concurrent-recording', File::get($directory.'/new.mp4'));
        });
    }

    public function test_recording_cleanup_removes_empty_date_directories_but_keeps_the_recordings_root(): void
    {
        $this->withFakeSmbClient(function (string $remoteRoot): void {
            File::put($remoteRoot.'/cameras/7/recordings/2026/09/29/source.mkv', 'source');

            $this->assertTrue(app(CameraStorageService::class)->deleteRecordingFile('cameras/7/recordings/2026/09/29/source.mkv'));
            $this->assertDirectoryDoesNotExist($remoteRoot.'/cameras/7/recordings/2026');
            $this->assertDirectoryExists($remoteRoot.'/cameras/7/recordings');
        });
    }

    private function upload(string $localPath, string $diskPath): bool
    {
        $method = new ReflectionMethod(app(CameraStorageService::class), 'writeCameraDiskPathWithSmbClient');

        return $method->invoke(app(CameraStorageService::class), $diskPath, $localPath);
    }

    private function stagedFile(string $name, string $contents): string
    {
        $path = storage_path('app/private/ffmpeg-temp/camera-network-staging/cameras/7/recordings/2026/09/29/'.$name);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        return $path;
    }

    private function withFakeSmbClient(callable $run): void
    {
        $settings = app(ApplicationSettingsService::class);
        $settings->saveNetworkStorageSettings(true, '//fileserver/share/cameras', 'operator', 'test-secret');
        config(['filesystems.disks.camera_private' => [
            ...$settings->networkStorageDiskConfig(),
            'driver' => 'smb',
        ]]);

        $binaryDirectory = storage_path('app/private/fake-smb-bin');
        $remoteRoot = storage_path('app/private/fake-smb-share');
        File::ensureDirectoryExists($binaryDirectory);
        File::ensureDirectoryExists($remoteRoot);
        File::ensureDirectoryExists($remoteRoot.'/cameras/7/recordings/2026/09/29');
        $binary = $binaryDirectory.'/smbclient';
        File::put($binary, str_replace('__ROOT__', $remoteRoot, <<<'PHP'
#!/usr/bin/env php
<?php
$command = $argv[array_search('-c', $argv, true) + 1] ?? '';
preg_match_all('/"([^"]*)"/', $command, $matches);
$paths = $matches[1];
$root = '__ROOT__';
file_put_contents($root.'/commands.log', $command.PHP_EOL, FILE_APPEND);
$target = static fn (string $path): string => $root.'/'.ltrim($path, '/');
$action = strtok($command, ' ');
if ($action === 'allinfo') {
    $file = $target($paths[0]);
    if (!is_file($file)) { fwrite(STDERR, "NT_STATUS_OBJECT_NAME_NOT_FOUND\n"); exit(1); }
    echo 'size: '.filesize($file)."\n";
    exit(0);
}
if ($action === 'put') {
    $destination = $target($paths[1]);
    if (is_file($root.'/truncate-upload')) {
        file_put_contents($destination, substr(file_get_contents($paths[0]), 0, 1));
    } else {
        copy($paths[0], $destination);
    }
    exit(0);
}
if ($action === 'rename') {
    $source = $target($paths[0]);
    $destination = $target($paths[1]);
    if (is_file($root.'/fail-promotion') && str_contains($source, '.uploading-')
        && glob(dirname($destination).'/*.replacing-*') !== []) { exit(1); }
    if (!is_file($source) || is_file($destination)) { exit(1); }
    exit(rename($source, $destination) ? 0 : 1);
}
if ($action === 'del') {
    $file = $target($paths[0]);
    if (!is_file($file)) { fwrite(STDERR, "NT_STATUS_OBJECT_NAME_NOT_FOUND\n"); exit(1); }
    exit(unlink($file) ? 0 : 1);
}
if ($action === 'rmdir') {
    $directory = $target($paths[0]);
    if (is_file($root.'/upload-during-cleanup')) {
        file_put_contents($directory.'/new.mp4', 'concurrent-recording');
        unlink($root.'/upload-during-cleanup');
    }
    if (!@rmdir($directory)) {
        echo "NT_STATUS_DIRECTORY_NOT_EMPTY removing remote directory\n";
    }
    exit(0);
}
exit(1);
PHP));
        chmod($binary, 0755);

        $oldPath = getenv('PATH');
        putenv('PATH='.$binaryDirectory.':'.$oldPath);

        try {
            $resolved = new ReflectionMethod(app(CameraStorageService::class), 'smbClientBinary');
            $this->assertSame($binary, $resolved->invoke(app(CameraStorageService::class)));
            $run($remoteRoot);
        } finally {
            putenv('PATH='.$oldPath);
        }
    }
}
