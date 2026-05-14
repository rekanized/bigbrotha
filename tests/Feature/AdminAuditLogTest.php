<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AllowedLoginEmail;
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