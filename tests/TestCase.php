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
        File::ensureDirectoryExists($this->testStoragePath.'/app/public');

        $this->app->useStoragePath($this->testStoragePath);

        config()->set('filesystems.disks.local.root', $this->testStoragePath.'/app/private');
        config()->set('filesystems.disks.public.root', $this->testStoragePath.'/app/public');
    }

    protected function tearDown(): void
    {
        if (isset($this->testStoragePath)) {
            File::deleteDirectory($this->testStoragePath);
        }

        parent::tearDown();
    }
}
