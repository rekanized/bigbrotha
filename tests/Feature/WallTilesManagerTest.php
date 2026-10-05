<?php

namespace Tests\Feature;

use App\Livewire\LiveWall\TilesManager;
use App\Models\Camera;
use App\Models\LiveWall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WallTilesManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_a_named_wall_with_configured_camera_tiles(): void
    {
        $cameraOne = Camera::query()->create([
            'name' => 'Front Gate',
            'local_ip' => '192.168.1.21',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
        ]);

        $cameraTwo = Camera::query()->create([
            'name' => 'Loading Bay',
            'local_ip' => '192.168.1.22',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
        ]);

        Livewire::test(TilesManager::class)
            ->call('createWall')
            ->set('wallForm.name', 'Night Shift Wall')
            ->set('wallForm.slug', 'night-shift-wall')
            ->set('wallForm.description', 'Portrait-heavy wall for after-hours monitoring.')
            ->set('wallForm.grid_columns', 4)
            ->set('wallForm.default_tile_orientation', 'portrait')
            ->set('wallForm.is_default', true)
            ->call('addTile')
            ->set('tileForms.0.camera_id', $cameraOne->id)
            ->set('tileForms.0.orientation', 'portrait')
            ->set('tileForms.0.column_span', 2)
            ->set('tileForms.0.row_span', 1)
            ->call('addTile')
            ->set('tileForms.1.camera_id', $cameraTwo->id)
            ->set('tileForms.1.orientation', 'square')
            ->set('tileForms.1.column_span', 1)
            ->set('tileForms.1.row_span', 2)
            ->call('saveWall');

        $wall = LiveWall::query()->where('slug', 'night-shift-wall')->firstOrFail();

        $this->assertSame('Night Shift Wall', $wall->name);
        $this->assertSame(4, $wall->grid_columns);
        $this->assertSame('portrait', $wall->default_tile_orientation);
        $this->assertTrue($wall->is_default);
        $this->assertDatabaseCount('live_wall_tiles', 2);
        $this->assertDatabaseHas('live_wall_tiles', [
            'live_wall_id' => $wall->id,
            'camera_id' => $cameraOne->id,
            'position' => 1,
            'orientation' => 'portrait',
            'column_span' => 2,
            'row_span' => 1,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('live_wall_tiles', [
            'live_wall_id' => $wall->id,
            'camera_id' => $cameraTwo->id,
            'position' => 2,
            'orientation' => 'square',
            'column_span' => 1,
            'row_span' => 2,
            'is_enabled' => true,
        ]);
        $this->assertSame(1, LiveWall::query()->where('is_default', true)->count());
    }

    public function test_it_can_reorder_tiles_before_saving_the_wall(): void
    {
        $cameraOne = Camera::query()->create([
            'name' => 'Front Gate',
            'local_ip' => '192.168.1.31',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
        ]);

        $cameraTwo = Camera::query()->create([
            'name' => 'Driveway',
            'local_ip' => '192.168.1.32',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
        ]);

        Livewire::test(TilesManager::class)
            ->call('createWall')
            ->set('wallForm.name', 'Drag Sort Wall')
            ->call('addTile')
            ->set('tileForms.0.camera_id', $cameraOne->id)
            ->call('addTile')
            ->set('tileForms.1.camera_id', $cameraTwo->id)
            ->call('reorderTiles', 1, 0)
            ->assertSet('tileForms.0.camera_id', $cameraTwo->id)
            ->assertSet('tileForms.1.camera_id', $cameraOne->id)
            ->call('saveWall');

        $wall = LiveWall::query()->where('slug', 'drag-sort-wall')->firstOrFail();

        $this->assertDatabaseHas('live_wall_tiles', [
            'live_wall_id' => $wall->id,
            'camera_id' => $cameraTwo->id,
            'position' => 1,
        ]);
        $this->assertDatabaseHas('live_wall_tiles', [
            'live_wall_id' => $wall->id,
            'camera_id' => $cameraOne->id,
            'position' => 2,
        ]);
    }
}
