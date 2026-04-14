<?php

namespace Tests\Feature\Auth;

use App\Livewire\Setup\SetupWizard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_requests_are_redirected_to_setup_until_onboarding_is_complete(): void
    {
        $this->clearAuthenticationSetupState();

        $this->get(route('login'))
            ->assertRedirect(route('setup.index'));
    }

    public function test_setup_wizard_can_create_the_initial_local_admin(): void
    {
        $this->clearAuthenticationSetupState();

        Livewire::test(SetupWizard::class)
            ->set('manualAuthEnabled', '1')
            ->set('googleAuthEnabled', '0')
            ->set('adminName', 'Initial Admin')
            ->set('adminEmail', 'admin@example.com')
            ->set('adminPassword', 'super-secret-password')
            ->set('adminPasswordConfirmation', 'super-secret-password')
            ->call('save')
            ->assertRedirect(route('camera-fleet.index'));

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->local_auth_enabled);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'auth_setup_complete',
            'value' => '1',
        ]);
    }

    public function test_setup_requires_a_verified_google_round_trip_before_enabling_google_sign_in(): void
    {
        $this->clearAuthenticationSetupState();

        Livewire::test(SetupWizard::class)
            ->set('manualAuthEnabled', '0')
            ->set('googleAuthEnabled', '1')
            ->set('googleClientId', 'client-id')
            ->set('googleClientSecret', 'client-secret')
            ->set('googleRedirectUri', 'https://monitor.example.com/auth/google/callback')
            ->call('save')
            ->assertHasErrors(['googleClientId']);
    }
}