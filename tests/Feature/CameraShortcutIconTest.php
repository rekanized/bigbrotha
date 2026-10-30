<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Services\CameraShortcutIconService;
use App\Services\CameraStorageService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CameraShortcutIconTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_icons_preserve_the_whole_frame_and_have_the_declared_dimensions(): void
    {
        $camera = $this->cameraWithPreview();
        $icons = app(CameraShortcutIconService::class);
        $snapshot = $icons->forCamera($camera);
        $this->assertNotNull($snapshot['revision']);
        $this->assertSame('2026-10-30T10:00:00Z', $snapshot['captured_at']);

        foreach (CameraShortcutIconService::SIZES as $size) {
            $path = $icons->iconPath($camera, $snapshot['revision'], $size);
            $this->assertSame([$size, $size, IMAGETYPE_PNG], array_slice(getimagesize($path), 0, 3));
            $this->assertStringStartsWith(storage_path('app/private/cameras/'.$camera->id.'/shortcut-icons/'), $path);
        }

        $path = $icons->iconPath($camera, $snapshot['revision'], 512);
        $process = new Process(['/usr/bin/ffmpeg', '-v', 'error', '-i', $path, '-threads', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1']);
        $process->setTimeout(5)->mustRun();
        $rgb = $process->getOutput();
        $pixel = fn (int $x, int $y): array => array_values(unpack('C3', substr($rgb, ($y * 512 + $x) * 3, 3)));
        $this->assertSame([16, 20, 27], $pixel(10, 10), 'Square canvas has dark padding.');
        $this->assertSame([0, 255, 0], $pixel(72, 256), 'Left edge of the frame is retained.');
        $this->assertSame([255, 0, 0], $pixel(256, 256), 'Center of the frame is retained.');
        $this->assertSame([0, 0, 255], $pixel(440, 256), 'Right edge of the frame is retained.');
    }

    public function test_page_and_manifest_use_the_same_snapshot_without_changing_shortcut_identity(): void
    {
        $camera = $this->cameraWithPreview();
        $this->mock(MediaMtxProcessService::class)->shouldReceive('ensureRunning')->andReturn(['installed' => true, 'running' => false]);
        $this->actingAs(User::factory()->create());
        $snapshot = app(CameraShortcutIconService::class)->forCamera($camera);

        $this->get(route('cameras.live', $camera))->assertOk()
            ->assertSee('rel="apple-touch-icon" href="'.$snapshot['apple_url'].'"', false)
            ->assertSee('Latest saved camera preview')
            ->assertSee('Captured ')
            ->assertSee('The icon is a snapshot');

        $manifest = $this->get(route('cameras.manifest', $camera))->assertOk()
            ->assertJsonPath('id', route('cameras.live', $camera, false))
            ->assertJsonPath('start_url', route('cameras.live', $camera, false))->json();
        foreach ($manifest['icons'] as $icon) {
            $size = (int) $icon['sizes'];
            $url = route('cameras.shortcut-icon', ['camera' => $camera, 'revision' => $snapshot['revision'], 'size' => $size]);
            $this->assertSame($url, $icon['src']);
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->get($snapshot['apple_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('camera-fleet.index'))->assertOk()
            ->assertSee('/apple-touch-icon.png?v=', false)->assertDontSee('/shortcut-icon/', false);
    }

    public function test_icons_are_cached_and_a_new_capture_gets_a_new_revision(): void
    {
        $camera = $this->cameraWithPreview();
        $icons = app(CameraShortcutIconService::class);
        $first = $icons->forCamera($camera);
        $this->assertNotNull($first['revision']);
        config()->set('ffmpeg.ffmpeg.binaries', ['/missing-ffmpeg']);
        $this->assertSame($first, $icons->forCamera($camera), 'Cached snapshot does not need FFmpeg again.');

        config()->set('ffmpeg.ffmpeg.binaries', ['/usr/bin/ffmpeg']);
        $metadata = $camera->metadata;
        $metadata['rtsp_profiles'][0]['preview_generated_at'] = '2026-10-30T10:30:00Z';
        $camera->update(['metadata' => $metadata]);
        $next = $icons->forCamera($camera);
        $this->assertNotNull($next['revision']);
        $this->assertNotSame($first['revision'], $next['revision']);
        $this->assertFileExists($icons->iconPath($camera, $first['revision'], 180), 'Previous snapshots remain available while cached.');
    }

    public function test_the_newest_preview_is_selected_and_camera_caches_are_separate(): void
    {
        $camera = $this->cameraWithPreview();
        $metadata = $camera->metadata;
        $metadata['rtsp_profiles'][] = array_merge($metadata['rtsp_profiles'][0], ['preview_generated_at' => '2026-10-30T11:00:00Z']);
        $camera->update(['metadata' => $metadata]);
        $icons = app(CameraShortcutIconService::class);
        $snapshot = $icons->forCamera($camera);
        $this->assertSame('2026-10-30T11:00:00Z', $snapshot['captured_at']);
        $this->assertNotNull($snapshot['revision']);

        $other = $this->cameraWithPreview();
        $otherSnapshot = $icons->forCamera($other);
        $this->assertNotNull($otherSnapshot['revision']);
        $this->assertNotSame($snapshot['revision'], $otherSnapshot['revision']);
        $this->assertSame(public_path('apple-touch-icon.png'), $icons->iconPath($other, $snapshot['revision'], 180));
    }

    public function test_missing_corrupt_or_unconvertible_previews_keep_the_default_icon(): void
    {
        $camera = $this->cameraWithPreview();
        $icons = app(CameraShortcutIconService::class);
        config()->set('ffmpeg.ffmpeg.binaries', ['/missing-ffmpeg']);
        $this->assertNull($icons->forCamera($camera)['revision']);
        config()->set('ffmpeg.ffmpeg.binaries', ['/bin/false']);
        $this->assertNull($icons->forCamera($camera)['revision'], 'Conversion failure falls back safely.');
        $root = storage_path('app/private/cameras/'.$camera->id.'/shortcut-icons');
        $this->assertSame([], File::directories($root), 'Failed conversions leave no partial cache.');
        config()->set('ffmpeg.ffmpeg.binaries', ['/usr/bin/ffmpeg']);
        $path = storage_path('app/private/'.$camera->rtspProfiles()[0]['preview_path']);
        File::put($path, 'corrupt image');
        $this->assertNull($icons->forCamera($camera)['revision']);
        File::delete($path);
        $fallback = $icons->forCamera($camera);
        $this->assertNull($fallback['revision']);
        $this->assertStringContainsString('/apple-touch-icon.png', $fallback['apple_url']);
    }

    public function test_network_previews_are_resolved_once_and_temporary_downloads_are_removed(): void
    {
        $camera = $this->cameraWithPreview();
        $path = storage_path('app/private/'.$camera->rtspProfiles()[0]['preview_path']);
        $storage = $this->mock(CameraStorageService::class);
        $storage->shouldReceive('usingNetworkStorage')->andReturn(true);
        $storage->shouldReceive('resolvePreviewImage')->once()->with($camera->rtspProfiles()[0]['preview_path'])
            ->andReturn(['absolute_path' => $path, 'mime_type' => 'image/png']);
        $storage->shouldReceive('deleteTemporaryFile')->once()->with($path)->andReturnUsing(fn () => File::delete($path));

        $icons = app(CameraShortcutIconService::class);
        $snapshot = $icons->forCamera($camera);
        $this->assertNotNull($snapshot['revision']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame($snapshot, $icons->forCamera($camera), 'Cache hit does not download the remote preview again.');
    }

    public function test_unreachable_network_previews_keep_the_default_icon(): void
    {
        $camera = $this->cameraWithPreview();
        $storage = $this->mock(CameraStorageService::class);
        $storage->shouldReceive('usingNetworkStorage')->andReturn(true);
        $storage->shouldReceive('resolvePreviewImage')->once()->andReturnNull();
        $storage->shouldReceive('deleteTemporaryFile')->once()->with(null);
        $this->assertNull(app(CameraShortcutIconService::class)->forCamera($camera)['revision']);
    }

    public function test_icon_requests_require_login_without_overwriting_the_camera_destination(): void
    {
        $camera = $this->cameraWithPreview();
        $snapshot = app(CameraShortcutIconService::class)->forCamera($camera);
        $this->assertNotNull($snapshot['revision']);
        $this->get(route('cameras.live', $camera))->assertRedirect(route('login'));
        $this->get($snapshot['apple_url'], ['Accept' => 'image/png'])->assertForbidden()
            ->assertSessionHas('url.intended', route('cameras.live', $camera));
    }

    public function test_icon_routes_validate_camera_revision_and_size_and_fall_back_for_evicted_icons(): void
    {
        $camera = $this->cameraWithPreview();
        $this->actingAs(User::factory()->create());
        $revision = str_repeat('a', 64);
        $url = fn (int $size, string $hash = ''): string => route('cameras.shortcut-icon', ['camera' => $camera, 'revision' => $hash ?: $revision, 'size' => $size]);
        $this->get($url(180))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(public_path('apple-touch-icon.png'), app(CameraShortcutIconService::class)->iconPath($camera, $revision, 180));
        $this->get($url(999))->assertNotFound();
        $this->get($url(180, 'not-a-hash'))->assertNotFound();
        $camera->update(['is_enabled' => false]);
        $this->get($url(180))->assertNotFound();
        $camera->update(['is_enabled' => true, 'supports_rtsp' => false]);
        $this->get($url(180))->assertNotFound();
        $camera->delete();
        $this->get($url(180))->assertNotFound();
    }

    public function test_only_three_snapshot_revisions_are_retained(): void
    {
        $camera = $this->cameraWithPreview();
        $icons = app(CameraShortcutIconService::class);
        for ($i = 0; $i < 5; $i++) {
            $metadata = $camera->metadata;
            $metadata['rtsp_profiles'][0]['preview_generated_at'] = '2026-10-30T10:0'.$i.':00Z';
            $camera->update(['metadata' => $metadata]);
            $snapshot = $icons->forCamera($camera);
            $this->assertNotNull($snapshot['revision']);
        }
        $directories = File::directories(storage_path('app/private/cameras/'.$camera->id.'/shortcut-icons'));
        $this->assertCount(3, $directories);
        $this->assertContains(dirname($icons->iconPath($camera, $snapshot['revision'], 180)), $directories);
    }

    private function cameraWithPreview(): Camera
    {
        $camera = Camera::query()->create([
            'name' => 'Front entrance', 'local_ip' => '192.0.2.44',
            'supports_rtsp' => true, 'is_enabled' => true, 'rtsp_path' => '/live',
        ]);
        $path = 'cameras/'.$camera->id.'/previews/snapshot.png';
        File::ensureDirectoryExists(dirname(storage_path('app/private/'.$path)));
        // Green and blue edges expose accidental cropping; the middle is red.
        $row = str_repeat("\x00\xff\x00", 20).str_repeat("\xff\x00\x00", 280).str_repeat("\x00\x00\xff", 20);
        $source = storage_path('app/private/'.$path).'.ppm';
        File::put($source, "P6\n320 180\n255\n".str_repeat($row, 180));
        (new Process(['/usr/bin/ffmpeg', '-v', 'error', '-i', $source, '-threads', '1', storage_path('app/private/'.$path)]))
            ->setTimeout(5)->mustRun();
        File::delete($source);
        $camera->update(['metadata' => ['rtsp_profiles' => [[
            'uri' => 'rtsp://192.0.2.44/live', 'preview_path' => $path,
            'preview_generated_at' => '2026-10-30T10:00:00Z',
        ]]]]);

        return $camera;
    }
}
