<?php

use App\Services\Relay\MediaMtxConfigService;
use Illuminate\Contracts\Console\Kernel;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('mediamtx.auth.enabled', false);
config()->set('mediamtx.rtsp.publish_base_url', 'rtsp://127.0.0.1:28554');
config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:28554');
config()->set('ffmpeg.live.input_analyze_duration', 1000000);
$service = app(MediaMtxConfigService::class);
$source = new ReflectionMethod($service, 'buildSourceRunOnDemandCommand');
$live = new ReflectionMethod($service, 'buildLiveRunOnDemandCommand');
$result = [];
foreach (['copy' => 0, 'transcode' => 2] as $name => $bframes) {
    $result['source-'.$name] = $source->invoke($service, '/usr/bin/ffmpeg', 'rtsp://127.0.0.1:28554/fixture-'.$name, 'tcp', $bframes > 0);
    $result['live-'.$name] = $live->invoke($service, '/usr/bin/ffmpeg', ['video_codec' => 'h264', 'video_has_b_frames' => $bframes], 'rtsp://127.0.0.1:28554/source-'.$name, 'tcp');
}
echo json_encode($result);
