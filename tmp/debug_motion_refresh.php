<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

config()->set('ffmpeg.ffmpeg.binaries', [storage_path('app/private/test-binaries/debug-fake-motion-refresh.sh')]);
config()->set('recording.motion.grid_width', 4);
config()->set('recording.motion.grid_height', 4);
config()->set('recording.motion.persistence_window_frames', 2);

$binaryPath = storage_path('app/private/test-binaries/debug-fake-motion-refresh.sh');
Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($binaryPath));
Illuminate\Support\Facades\File::put($binaryPath, <<<'BASH'
#!/usr/bin/env bash
set -e
arg_has() {
  local needle="$1"
  shift
  for argument in "$@"; do
    if [[ "$argument" == "$needle" ]]; then
      return 0
    fi
  done
  return 1
}
if arg_has rawvideo "$@"; then
  printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\377\377\377\377\377\377\377\377\377\377\377\377\377\377\377\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
  exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH);
chmod($binaryPath, 0755);

$segmentPath = storage_path('app/private/test-binaries/debug-refresh-segment.mkv');
Illuminate\Support\Facades\File::put($segmentPath, 'refresh-glitch-4');

$camera = new App\Models\Camera([
    'motion_sensitivity' => 25,
    'recording_motion_mask' => [
        'version' => 1,
        'grid_width' => 4,
        'grid_height' => 4,
        'selected_pixels' => 4,
        'runs' => [
            [0, 1],
            [4, 5],
        ],
    ],
]);

$result = app(App\Services\RecordingMotionDetectorService::class)->detectClip($camera, $segmentPath);
var_export($result);
echo PHP_EOL;
