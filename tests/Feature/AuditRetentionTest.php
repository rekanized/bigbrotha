<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_defaults_to_thirty_days_and_is_shown_in_settings_and_history(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame(30, app(ApplicationSettingsService::class)->auditRetentionDays());
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()
            ->assertSee('Keep audit entries for (days)')->assertViewHas('auditRetentionDays', 30);
        $this->get(route('admin.audit-logs.index'))->assertOk()->assertSee('30-day retention');
    }

    public function test_admin_can_save_retention_without_changing_timezone_and_the_change_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $settings = app(ApplicationSettingsService::class);
        $settings->saveAuditRetentionDays(30);
        $timezone = $settings->appTimezone();

        $this->actingAs($admin)->put(route('admin.settings.audit-retention.update'), ['audit_retention_days' => 7])
            ->assertRedirect(route('admin.settings.index').'#audit-retention')
            ->assertSessionHas('audit_retention_status', 'Audit log retention saved: 7 days. Older entries will be deleted at the next daily cleanup.');

        $this->assertDatabaseHas('app_settings', ['key' => 'audit_retention_days', 'value' => '7']);
        $this->assertSame($timezone, app(ApplicationSettingsService::class)->appTimezone());
        $this->assertSame(7, app(ApplicationSettingsService::class)->auditRetentionDays());
        $audit = AuditLog::query()->where('auditable_type', AppSetting::class)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['value' => '30'], $audit->old_values);
        $this->assertSame(['value' => '7'], $audit->new_values);
        $this->assertSame($admin->id, $audit->user_id);
        $this->get(route('admin.audit-logs.index'))->assertOk()->assertSee('7-day retention')->assertSee('Audit log retention');
    }

    public function test_retention_save_does_not_prune_history_in_the_web_request(): void
    {
        $admin = User::factory()->admin()->create();
        $old = $this->createAuditAt(now()->utc()->subDays(20));
        $this->actingAs($admin)->put(route('admin.settings.audit-retention.update'), ['audit_retention_days' => 1])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['id' => $old->id]);
    }

    #[DataProvider('invalidDays')]
    public function test_invalid_retention_is_rejected_without_replacing_the_saved_value(mixed $value): void
    {
        $admin = User::factory()->admin()->create();
        app(ApplicationSettingsService::class)->saveAuditRetentionDays(14);
        $this->actingAs($admin)->putJson(route('admin.settings.audit-retention.update'), ['audit_retention_days' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors('audit_retention_days');
        $this->assertDatabaseHas('app_settings', ['key' => 'audit_retention_days', 'value' => '14']);
    }

    public static function invalidDays(): array
    {
        return [[0], [-1], [366], [7.5], ['forever'], [null]];
    }

    public function test_only_admins_can_change_retention(): void
    {
        User::factory()->admin()->create();
        $this->put(route('admin.settings.audit-retention.update'), ['audit_retention_days' => 7])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->put(route('admin.settings.audit-retention.update'), ['audit_retention_days' => 7])->assertForbidden();
        $this->assertDatabaseMissing('app_settings', ['key' => 'audit_retention_days']);
    }

    #[DataProvider('retentionPeriods')]
    public function test_pruning_uses_the_configured_utc_cutoff_including_the_boundary(int $days): void
    {
        $this->freezeTime();
        app(ApplicationSettingsService::class)->saveAppTimezone('Europe/Amsterdam');
        app(ApplicationSettingsService::class)->saveAuditRetentionDays($days);
        $cutoff = now()->utc()->subDays($days);
        $expired = $this->createAuditAt($cutoff->copy()->subSecond());
        $boundary = $this->createAuditAt($cutoff);
        $retained = $this->createAuditAt($cutoff->copy()->addSecond());

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();
        $this->assertDatabaseMissing('audit_logs', ['id' => $expired->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $boundary->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $retained->id]);
    }

    public static function retentionPeriods(): array
    {
        return [[1], [7], [60], [365]];
    }

    public function test_invalid_stored_retention_falls_back_to_thirty_days(): void
    {
        AppSetting::query()->create(['key' => 'audit_retention_days', 'value' => '0']);
        $this->assertSame(30, app(ApplicationSettingsService::class)->auditRetentionDays());
    }

    public function test_new_retention_setting_audit_contains_its_name_and_saved_value(): void
    {
        app(ApplicationSettingsService::class)->saveAuditRetentionDays(14);
        $setting = AppSetting::query()->where('key', 'audit_retention_days')->firstOrFail();
        $audit = $setting->auditLogs()->where('event', 'created')->firstOrFail();
        $this->assertSame('audit_retention_days', $audit->new_values['key']);
        $this->assertSame('14', $audit->new_values['value']);
        $this->assertArrayNotHasKey('network_storage_password', $audit->new_values);
    }

    private function createAuditAt(Carbon $timestamp): AuditLog
    {
        return AuditLog::query()->create([
            'auditable_type' => User::class,
            'auditable_id' => 999,
            'event' => 'updated',
            'actor_type' => 'system',
            'actor_label' => 'System',
            'source' => 'console',
            'created_at' => $timestamp,
        ]);
    }
}
