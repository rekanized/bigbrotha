<?php

namespace Tests\Feature;

use App\Models\AllowedLoginEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}