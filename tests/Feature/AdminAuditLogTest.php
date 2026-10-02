<?php

namespace Tests\Feature;

use App\Models\AllowedLoginEmail;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sidebar_includes_the_audit_log_link(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Operator',
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('Audit log');
    }

    public function test_admin_audit_log_page_lists_recent_admin_audits(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Operator',
            'email' => 'admin@example.com',
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->withHeader('User-Agent', 'Admin Audit Test')
            ->post('/admin/users/allowed-emails', [
                'email' => 'operator@example.com',
            ])
            ->assertRedirect('/admin/users');

        $this->assertTrue(AllowedLoginEmail::isAllowed('operator@example.com'));

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get('/admin/audit-log')
            ->assertOk()
            ->assertSee('operator@example.com')
            ->assertSee('Admin Operator')
            ->assertSee('HTTP')
            ->assertSee('Created');
    }

    public function test_admin_audit_log_page_renders_numbered_pagination_links(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Operator',
            'is_admin' => true,
        ]);

        foreach (range(1, 30) as $index) {
            AuditLog::query()->create([
                'auditable_type' => $admin->getMorphClass(),
                'auditable_id' => $admin->getKey(),
                'user_id' => $admin->getKey(),
                'event' => 'updated',
                'actor_type' => AuditLog::ACTOR_TYPE_USER,
                'actor_label' => 'Admin Operator',
                'source' => AuditLog::SOURCE_HTTP,
                'old_values' => ['index' => $index - 1],
                'new_values' => ['index' => $index],
                'metadata' => ['batch' => 'pagination'],
                'ip_address' => '192.168.1.1',
                'user_agent' => 'Pagination Test Agent',
                'created_at' => Carbon::create(2026, 5, 14, 12, 0, 0, 'UTC')->subMinutes($index),
            ]);
        }

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get('/admin/audit-log')
            ->assertOk()
            ->assertSee('Page 1 of 2')
            ->assertSee('page=2', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_search_finds_deleted_subject_names_and_is_case_insensitive(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->createEntry(['event' => 'deleted', 'old_values' => ['name' => 'Front Door'], 'new_values' => null]);
        $this->createEntry(['new_values' => ['name' => 'Unrelated camera']]);

        $this->actingAs($admin)->get('/admin/audit-log?search=front%20door')
            ->assertOk()->assertSee('Front Door')->assertDontSee('Unrelated camera');
    }

    public function test_recording_failure_view_displays_camera_status_and_reason(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $camera = Camera::query()->create(['name' => 'Loading Bay', 'local_ip' => '192.168.1.10']);
        $this->createEntry([
            'auditable_type' => CameraRecording::class,
            'event' => 'recording.state_transition',
            'old_values' => ['status' => 'processing'],
            'new_values' => ['status' => 'failed', 'message' => 'Camera did not respond'],
            'metadata' => ['camera_id' => $camera->id, 'from' => 'processing', 'to' => 'failed'],
        ]);
        $this->createEntry(['new_values' => ['name' => 'Unrelated action']]);

        $this->actingAs($admin)->get('/admin/audit-log?activity=failures')
            ->assertOk()->assertSee('Recording failed')->assertSee('Loading Bay')
            ->assertSee('Camera did not respond')->assertDontSee('Unrelated action');
        $this->actingAs($admin)->get('/admin/audit-log?search=loading%20bay')
            ->assertOk()->assertSee('Camera did not respond')->assertDontSee('Unrelated action');
    }

    public function test_camera_filter_keeps_deleted_recording_history_and_excludes_other_subjects(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->createEntry([
            'auditable_type' => CameraRecording::class,
            'event' => 'recording.transient_discarded',
            'old_values' => ['camera_id' => 123],
            'metadata' => ['reason' => 'No motion detected'],
        ]);
        $this->createEntry(['new_values' => ['name' => 'Another item', 'camera_id' => 123]]);

        $this->actingAs($admin)->get('/admin/audit-log?camera_id=123')
            ->assertOk()->assertSee('Temporary motion recording removed')
            ->assertSee('No motion detected')->assertSee('does not mean a saved video was deleted')
            ->assertDontSee('Another item');
    }

    public function test_user_view_and_time_range_can_be_combined(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->createEntry(['actor_type' => 'user', 'new_values' => ['name' => 'Recent user action']]);
        $this->createEntry(['actor_type' => 'user', 'created_at' => now()->subDays(2), 'new_values' => ['name' => 'Old user action']]);
        $this->createEntry(['new_values' => ['name' => 'System action']]);

        $this->actingAs($admin)->get('/admin/audit-log?activity=users&period=day')
            ->assertOk()->assertSee('Recent user action')->assertDontSee('Old user action')->assertDontSee('System action');
    }

    public function test_secret_setting_values_are_redacted_and_subject_content_is_escaped(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->createEntry([
            'auditable_type' => AppSetting::class,
            'old_values' => ['key' => 'auth_google_client_secret', 'value' => 'private-old-secret'],
            'new_values' => ['key' => 'auth_google_client_secret', 'value' => 'private-new-secret'],
        ]);
        $this->createEntry(['new_values' => ['name' => '<script>alert(1)</script>']]);

        $this->actingAs($admin)->get('/admin/audit-log')->assertOk()
            ->assertSee('Hidden for security')->assertDontSee('private-old-secret')->assertDontSee('private-new-secret')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_search_finds_the_displayed_subject_when_the_snapshot_only_contains_a_changed_field(): void
    {
        $admin = User::factory()->create(['name' => 'North Gate Operator', 'is_admin' => true]);
        $setting = AppSetting::query()->create(['key' => 'auth_google_enabled', 'value' => '1']);
        AuditLog::query()->delete();
        $this->createEntry(['auditable_type' => User::class, 'auditable_id' => $admin->id, 'new_values' => ['email_verified_at' => now()->toIso8601String()]]);
        $this->createEntry(['auditable_type' => AppSetting::class, 'auditable_id' => $setting->id, 'new_values' => ['value' => '1']]);

        $this->actingAs($admin)->get('/admin/audit-log?search=north%20gate')
            ->assertOk()->assertSee('North Gate Operator')->assertDontSee('Google sign-in');
        $this->actingAs($admin)->get('/admin/audit-log?search=Google%20sign-in')
            ->assertOk()->assertSee('Google sign-in')->assertSee('Enabled')->assertViewHas('auditLogs', fn ($logs) => $logs->total() === 1);
    }

    public function test_new_filters_are_validated(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->getJson('/admin/audit-log?period=forever&activity=unknown&camera_id=-1')
            ->assertUnprocessable()->assertJsonValidationErrors(['period', 'activity', 'camera_id']);
    }

    public function test_non_admin_cannot_read_audit_history(): void
    {
        User::factory()->create(['is_admin' => true]);
        $operator = User::factory()->create(['is_admin' => false]);
        $this->actingAs($operator)->get('/admin/audit-log')->assertForbidden();
    }

    private function createEntry(array $attributes = []): AuditLog
    {
        return AuditLog::query()->create(array_merge([
            'auditable_type' => Camera::class,
            'auditable_id' => 999,
            'event' => 'updated',
            'actor_type' => 'system',
            'actor_label' => 'System',
            'source' => 'queue',
            'old_values' => [],
            'new_values' => [],
            'created_at' => now()->utc(),
        ], $attributes));
    }

    public function test_audit_logs_older_than_thirty_days_are_pruned(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Operator',
            'is_admin' => true,
        ]);

        $expiredAudit = AuditLog::query()->create([
            'auditable_type' => $admin->getMorphClass(),
            'auditable_id' => $admin->getKey(),
            'user_id' => $admin->getKey(),
            'event' => 'updated',
            'actor_type' => AuditLog::ACTOR_TYPE_USER,
            'actor_label' => 'Admin Operator',
            'source' => AuditLog::SOURCE_HTTP,
            'old_values' => ['enabled' => false],
            'new_values' => ['enabled' => true],
            'metadata' => ['batch' => 'expired'],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Prune Test Agent',
            'created_at' => now()->utc()->subDays(31),
        ]);

        $retainedAudit = AuditLog::query()->create([
            'auditable_type' => $admin->getMorphClass(),
            'auditable_id' => $admin->getKey(),
            'user_id' => $admin->getKey(),
            'event' => 'updated',
            'actor_type' => AuditLog::ACTOR_TYPE_USER,
            'actor_label' => 'Admin Operator',
            'source' => AuditLog::SOURCE_HTTP,
            'old_values' => ['enabled' => true],
            'new_values' => ['enabled' => false],
            'metadata' => ['batch' => 'retained'],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Prune Test Agent',
            'created_at' => now()->utc()->subDays(29),
        ]);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])
            ->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $expiredAudit->getKey()]);
        $this->assertDatabaseHas('audit_logs', ['id' => $retainedAudit->getKey()]);
    }
}
