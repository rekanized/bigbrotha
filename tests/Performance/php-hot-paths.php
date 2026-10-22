<?php

// Synthetic CPU benchmarks: no application boot, database, cameras or storage.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\Camera;
use App\Services\ApplicationSettingsService;
use App\Services\ApplicationSettingStore;
use App\Services\RecordingMotionDetectorService;
use App\Services\RecordingMotionMaskService;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

$container = new Application(dirname(__DIR__, 2));
$container->instance('config', new Repository([
    'recording' => require dirname(__DIR__, 2).'/config/recording.php',
]));
$masks = new RecordingMotionMaskService;
$detector = new RecordingMotionDetectorService($masks);
$camera = new Camera([
    'recording_motion_mask' => $masks->fullFrameMask(160, 90),
    'recording_motion_trigger_pixels' => 20,
]);
$quiet = str_repeat(chr(40), 160 * 90);
$moving = $quiet;
for ($y = 30; $y < 40; $y++) {
    for ($x = 60; $x < 70; $x++) {
        $moving[$y * 160 + $x] = chr(160);
    }
}
$quietClip = str_repeat($quiet, 180);
$movingClip = str_repeat($quiet.$moving.$moving, 60);
$mask = $masks->fullFrameMask(640, 640);
$settings = new ApplicationSettingsService(new class extends ApplicationSettingStore
{
    public function plainValues(): array
    {
        return ['app_timezone' => 'Europe/Stockholm'];
    }
});
$timestamp = new DateTimeImmutable('2026-07-01 12:00:00 UTC');
$cases = [
    'timezone_format' => [1000, fn () => $settings->formatDateTime($timestamp)],
    'mask_bounds_640x640' => [100, fn () => $masks->bounds($mask)],
    'motion_quiet_180_frames' => [10, fn () => $detector->analyzeFrames($camera, $quietClip, false)],
    'motion_local_180_frames' => [5, fn () => $detector->analyzeFrames($camera, $movingClip, false)],
    'motion_latest_180_frames' => [100, fn () => $detector->analyzeFrames($camera, $movingClip, true, true)],
];

printf("PHP %s; OPcache CLI=%s; JIT=%s\n", PHP_VERSION, ini_get('opcache.enable_cli'), ini_get('opcache.jit'));
foreach ($cases as $name => [$iterations, $run]) {
    if (isset($argv[1]) && $argv[1] !== $name) {
        continue;
    }

    $run();
    $samples = [];
    memory_reset_peak_usage();
    $memoryBefore = memory_get_usage();
    for ($round = 0; $round < 5; $round++) {
        $start = hrtime(true);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $result = $run();
        }
        $samples[] = (hrtime(true) - $start) / $iterations / 1e6;
    }
    sort($samples);
    printf("%s median_ms=%.3f peak_extra_bytes=%d result_sha256=%s\n", $name, $samples[2], memory_get_peak_usage() - $memoryBefore, hash('sha256', serialize($result)));
}
