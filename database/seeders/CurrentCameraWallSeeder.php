<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CurrentCameraWallSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $seed = require __DIR__.'/data/CurrentCameraWallSeed.php';

        DB::transaction(function () use ($seed): void {
            $this->seedCameras($seed['cameras'] ?? []);
            $this->seedWalls($seed['walls'] ?? []);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $cameras
     */
    private function seedCameras(array $cameras): void
    {
        if ($cameras === []) {
            return;
        }

        $rows = array_map(function (array $camera): array {
            return [
                'uuid' => $camera['uuid'],
                'name' => $camera['name'],
                'local_ip' => $camera['local_ip'],
                'hostname' => $camera['hostname'],
                'manufacturer' => $camera['manufacturer'],
                'model' => $camera['model'],
                'serial_number' => $camera['serial_number'],
                'mac_address' => $camera['mac_address'],
                'http_port' => $camera['http_port'],
                'onvif_port' => $camera['onvif_port'],
                'rtsp_port' => $camera['rtsp_port'],
                'onvif_path' => $camera['onvif_path'],
                'rtsp_path' => $camera['rtsp_path'],
                'rtsp_transport' => $camera['rtsp_transport'],
                'username' => $camera['username'],
                'password' => $camera['password'],
                'supports_onvif' => $camera['supports_onvif'],
                'supports_rtsp' => $camera['supports_rtsp'],
                'is_enabled' => $camera['is_enabled'],
                'recording_mode' => $camera['recording_mode'],
                'recording_profile_index' => $camera['recording_profile_index'],
                'recording_retention_days' => $camera['recording_retention_days'],
                'motion_sensitivity' => $camera['motion_sensitivity'],
                'recording_motion_pre_roll_seconds' => $camera['recording_motion_pre_roll_seconds'],
                'recording_motion_post_trigger_seconds' => $camera['recording_motion_post_trigger_seconds'],
                'recording_motion_area' => $this->encodeJson($camera['recording_motion_area'] ?? null),
                'recording_motion_mask' => $this->encodeJson($camera['recording_motion_mask'] ?? null),
                'recording_last_motion_at' => $camera['recording_last_motion_at'],
                'recording_last_recorded_at' => $camera['recording_last_recorded_at'],
                'last_seen_at' => $camera['last_seen_at'],
                'metadata' => $this->encodeJson($camera['metadata'] ?? null),
                'created_at' => $camera['created_at'],
                'updated_at' => $camera['updated_at'],
            ];
        }, $cameras);

        DB::table('cameras')->upsert(
            $rows,
            ['uuid'],
            [
                'name',
                'local_ip',
                'hostname',
                'manufacturer',
                'model',
                'serial_number',
                'mac_address',
                'http_port',
                'onvif_port',
                'rtsp_port',
                'onvif_path',
                'rtsp_path',
                'rtsp_transport',
                'username',
                'password',
                'supports_onvif',
                'supports_rtsp',
                'is_enabled',
                'recording_mode',
                'recording_profile_index',
                'recording_retention_days',
                'motion_sensitivity',
                'recording_motion_pre_roll_seconds',
                'recording_motion_post_trigger_seconds',
                'recording_motion_area',
                'recording_motion_mask',
                'recording_last_motion_at',
                'recording_last_recorded_at',
                'last_seen_at',
                'metadata',
                'updated_at',
            ]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $walls
     */
    private function seedWalls(array $walls): void
    {
        if ($walls === []) {
            return;
        }

        if ($this->hasDefaultWall($walls)) {
            DB::table('live_walls')->update(['is_default' => false]);
        }

        $wallRows = array_map(function (array $wall): array {
            return [
                'name' => $wall['name'],
                'slug' => $wall['slug'],
                'description' => $wall['description'],
                'grid_columns' => $wall['grid_columns'],
                'default_tile_orientation' => $wall['default_tile_orientation'],
                'is_default' => $wall['is_default'],
                'is_active' => $wall['is_active'],
                'created_at' => $wall['created_at'],
                'updated_at' => $wall['updated_at'],
            ];
        }, $walls);

        DB::table('live_walls')->upsert(
            $wallRows,
            ['slug'],
            [
                'name',
                'description',
                'grid_columns',
                'default_tile_orientation',
                'is_default',
                'is_active',
                'updated_at',
            ]
        );

        $cameraIdsByUuid = DB::table('cameras')
            ->pluck('id', 'uuid')
            ->all();

        $wallIdsBySlug = DB::table('live_walls')
            ->pluck('id', 'slug')
            ->all();

        foreach ($walls as $wall) {
            $wallId = $wallIdsBySlug[$wall['slug']] ?? null;

            if ($wallId === null) {
                throw new RuntimeException(sprintf('Unable to resolve live wall [%s] after upsert.', $wall['slug']));
            }

            DB::table('live_wall_tiles')
                ->where('live_wall_id', $wallId)
                ->delete();

            $tileRows = [];

            foreach ($wall['tiles'] ?? [] as $tile) {
                $cameraUuid = $tile['camera_uuid'] ?? null;
                $cameraId = is_string($cameraUuid) ? ($cameraIdsByUuid[$cameraUuid] ?? null) : null;

                if ($cameraId === null) {
                    throw new RuntimeException(sprintf('Unable to resolve camera UUID [%s] for live wall [%s].', (string) $cameraUuid, $wall['slug']));
                }

                $tileRows[] = [
                    'live_wall_id' => $wallId,
                    'camera_id' => $cameraId,
                    'position' => $tile['position'],
                    'orientation' => $tile['orientation'],
                    'column_span' => $tile['column_span'],
                    'row_span' => $tile['row_span'],
                    'is_enabled' => $tile['is_enabled'],
                    'created_at' => $tile['created_at'],
                    'updated_at' => $tile['updated_at'],
                ];
            }

            if ($tileRows !== []) {
                DB::table('live_wall_tiles')->insert($tileRows);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $walls
     */
    private function hasDefaultWall(array $walls): bool
    {
        foreach ($walls as $wall) {
            if (($wall['is_default'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}