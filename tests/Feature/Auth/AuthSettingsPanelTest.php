<?php

namespace Tests\Feature\Auth;

use App\Livewire\Admin\AuthSettingsPanel;
use App\Models\User;
use App\Services\AuthenticationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthSettingsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_enable_google_with_a_forged_component_fingerprint(): void
    {
        $admin = User::factory()->admin()->localOnly()->create();
        $this->actingAs($admin);

        $settings = app(AuthenticationSettingsService::class);
        $fingerprint = $settings->googleConfigurationFingerprint('client-id', 'client-secret', route('auth.google.callback'));

        Livewire::test(AuthSettingsPanel::class)
            ->set('googleAuthEnabled', '1')
            ->set('googleClientId', 'client-id')
            ->set('googleClientSecret', 'client-secret')
            ->set('googleRedirectUri', route('auth.google.callback'))
            ->set('googleVerifiedFingerprint', $fingerprint)
            ->call('save')
            ->assertHasErrors(['googleClientId']);

        $this->assertFalse($settings->googleAuthEnabled());
    }

    public function test_admin_can_keep_saved_verified_google_configuration_enabled(): void
    {
        $admin = User::factory()->admin()->localOnly()->create();
        $this->actingAs($admin);

        $settings = app(AuthenticationSettingsService::class);
        $googleConfiguration = [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => route('auth.google.callback'),
        ];

        $settings->saveConfiguration(true, true, $googleConfiguration, [
            'fingerprint' => $settings->googleConfigurationFingerprint(...array_values($googleConfiguration)),
            'tested_at' => now()->toIso8601String(),
            'tested_email' => 'admin@example.com',
        ]);

        Livewire::test(AuthSettingsPanel::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($settings->googleAuthEnabled());
    }
}
