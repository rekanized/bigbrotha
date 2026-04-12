<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStoragePath = base_path('storage/framework/testing/'.Str::random(20));

        File::deleteDirectory($this->testStoragePath);
        File::ensureDirectoryExists($this->testStoragePath.'/app/private');
        File::ensureDirectoryExists($this->testStoragePath.'/app/private/bootstrap');
        File::ensureDirectoryExists($this->testStoragePath.'/app/public');
        File::ensureDirectoryExists($this->testStoragePath.'/app/private/continuous-recorders');
        File::ensureDirectoryExists($this->testStoragePath.'/app/private/motion-recorders');
        File::ensureDirectoryExists($this->testStoragePath.'/app/private/ffmpeg-temp');

        $this->app->useStoragePath($this->testStoragePath);

        config()->set('filesystems.disks.local.root', $this->testStoragePath.'/app/private');
        config()->set('filesystems.disks.public.root', $this->testStoragePath.'/app/public');
        config()->set('recording.continuous.runtime_dir', $this->testStoragePath.'/app/private/continuous-recorders');
        config()->set('recording.motion.runtime_dir', $this->testStoragePath.'/app/private/motion-recorders');
        config()->set('recording.health.worker_heartbeat_path', $this->testStoragePath.'/app/private/bootstrap/recordings-worker.heartbeat');
        config()->set('recording.health.scheduler_heartbeat_path', $this->testStoragePath.'/app/private/bootstrap/recordings-scheduler.heartbeat');
        config()->set('recording.health.scheduler_tick_heartbeat_path', $this->testStoragePath.'/app/private/bootstrap/recordings-tick.heartbeat');
        config()->set('ffmpeg.temporary_directory', $this->testStoragePath.'/app/private/ffmpeg-temp');
    }

    protected function tearDown(): void
    {
        if (isset($this->testStoragePath)) {
            File::deleteDirectory($this->testStoragePath);
        }

        parent::tearDown();
    }
}
