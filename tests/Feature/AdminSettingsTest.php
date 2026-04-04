<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\CameraRecording;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_users_are_redirected_away_from_admin_settings(): void
    {
        $this->get(route('admin.settings.index'))
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_users_cannot_open_admin_settings(): void
    {
        User::factory()->admin()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_admin_can_update_the_operator_display_timezone(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'app_timezone' => 'Europe/Amsterdam',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_timezone',
            'value' => 'Europe/Amsterdam',
        ]);
    }

    public function test_admin_can_open_the_current_users_page_and_see_navigation_links(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin Operator',
            'email' => 'admin@example.com',
            'google_id' => 'google-admin',
        ]);

        User::factory()->create([
            'name' => 'Standard Operator',
            'email' => 'operator@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Current users')
            ->assertSee('Admin Operator')
            ->assertSee('Standard Operator')
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.settings.index'), false)
            ->assertSee('Application settings');
    }

    public function test_first_authenticated_operator_sees_admin_navigation_when_no_admin_exists_yet(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.settings.index'), false)
            ->assertSee('Admin');
    }

    public function test_non_admin_operator_does_not_see_admin_navigation_when_an_admin_already_exists(): void
    {
        User::factory()->admin()->create();
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.settings.index'), false);
    }

    public function test_non_admin_users_cannot_open_the_current_users_page(): void
    {
        User::factory()->admin()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_promote_a_standard_operator_to_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)
            ->put(route('admin.users.admin-role', ['user' => $operator]), [
                'is_admin' => '1',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($operator->fresh()->is_admin);
    }

    public function test_admin_can_demote_another_admin_when_multiple_admins_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.users.admin-role', ['user' => $otherAdmin]), [
                'is_admin' => '0',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse($otherAdmin->fresh()->is_admin);
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.users.admin-role', ['user' => $admin]), [
                'is_admin' => '0',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_recording_pages_use_the_configured_operator_timezone(): void
    {
        app(ApplicationSettingsService::class)->saveAppTimezone('Europe/Amsterdam');

        $operator = User::factory()->create();
        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.122',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 1),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/north-gate.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Clip saved.',
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.index'))
            ->assertOk()
            ->assertSee('2026-04-04 14:00');

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.show', ['recording' => $recording]))
            ->assertOk()
            ->assertSee('2026-04-04 14:00:00');
    }
}