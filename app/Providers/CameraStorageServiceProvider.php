<?php

namespace App\Providers;

use App\Services\ApplicationSettingsService;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\ServerFactory;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use RobGridley\Flysystem\Smb\SmbAdapter;

class CameraStorageServiceProvider extends ServiceProvider
{
    public function boot(ApplicationSettingsService $settings): void
    {
        $parseUsername = fn (string $username): array => $this->parseUsername($username);

        Storage::extend('smb', function ($app, array $config) use ($parseUsername): LaravelFilesystemAdapter {
            $factory = new ServerFactory();
            [$workgroup, $username] = $parseUsername((string) ($config['username'] ?? ''));
            $server = $factory->createServer(
                (string) $config['host'],
                new BasicAuth($username, $workgroup, (string) ($config['password'] ?? '')),
            );
            $share = $server->getShare((string) $config['share']);
            $adapter = new SmbAdapter($share, (string) ($config['root'] ?? ''));

            return new LaravelFilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });

        $diskConfig = [
            'driver' => 'local',
            'root' => storage_path('app/private/cameras'),
            'throw' => false,
            'report' => false,
        ];

        $networkConfig = $settings->networkStorageDiskConfig();

        if (is_array($networkConfig)) {
            $diskConfig = [
                'driver' => 'smb',
                'host' => $networkConfig['host'],
                'share' => $networkConfig['share'],
                'root' => $networkConfig['root'],
                'username' => $networkConfig['username'],
                'password' => $networkConfig['password'],
                'throw' => false,
                'report' => false,
            ];
        }

        config(['filesystems.disks.camera_private' => $diskConfig]);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseUsername(string $username): array
    {
        $trimmed = trim($username);

        if (preg_match('/^([^\\\\\/]+)[\\\\\/](.+)$/', $trimmed, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [null, $trimmed];
    }
}