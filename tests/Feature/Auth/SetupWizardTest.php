<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\UnifiedLoginScreen;
use App\Livewire\Setup\SetupWizard;
use App\Models\User;
use App\Services\AuthenticationSettingsService;
use App\Services\GoogleOAuthTestService;
use App\Services\SetupAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Mockery;
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
            ->set('setupToken', app(SetupAccessService::class)->token())
            ->set('manualAuthEnabled', '1')
            ->set('googleAuthEnabled', '0')
            ->set('adminName', 'Initial Admin')
            ->set('adminEmail', 'admin@example.com')
            ->set('adminPassword', 'super-secret-password')
            ->set('adminPasswordConfirmation', 'super-secret-password')
            ->call('save')
            ->assertRedirect(route('live-wall.index'));

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->local_auth_enabled);
        $this->assertFalse(Cookie::hasQueued(Auth::guard('web')->getRecallerName()));
        $this->assertDatabaseHas('app_settings', [
            'key' => 'auth_setup_complete',
            'value' => '1',
        ]);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        Livewire::test(UnifiedLoginScreen::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'super-secret-password')
            ->call('login')
            ->assertRedirect(route('live-wall.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_setup_requires_a_verified_google_round_trip_before_enabling_google_sign_in(): void
    {
        $this->clearAuthenticationSetupState();

        Livewire::test(SetupWizard::class)
            ->set('setupToken', app(SetupAccessService::class)->token())
            ->set('manualAuthEnabled', '0')
            ->set('googleAuthEnabled', '1')
            ->set('googleClientId', 'client-id')
            ->set('googleClientSecret', 'client-secret')
            ->set('googleRedirectUri', 'https://monitor.example.com/auth/google/callback')
            ->call('save')
            ->assertHasErrors(['googleClientId']);
    }

    public function test_setup_rejects_disabling_both_authentication_methods(): void
    {
        $this->clearAuthenticationSetupState();

        Livewire::test(SetupWizard::class)
            ->set('setupToken', app(SetupAccessService::class)->token())
            ->set('manualAuthEnabled', '0')
            ->set('googleAuthEnabled', '0')
            ->call('save')
            ->assertHasErrors(['manualAuthEnabled']);

        $this->assertFalse(app(AuthenticationSettingsService::class)->isSetupComplete());
    }

    public function test_google_only_oauth_validation_and_first_sign_in_create_admin(): void
    {
        $this->clearAuthenticationSetupState();
        $this->get(route('setup.index'))->assertOk();

        $tester = app(GoogleOAuthTestService::class);
        $tester->begin($this->app['request'], GoogleOAuthTestService::CONTEXT_SETUP, [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => route('auth.google.callback'),
        ]);

        $googleUser = (new SocialiteUser)->setRaw(['email_verified' => true, 'hd' => 'example.com']);
        $googleUser->map([
            'id' => 'google-admin-123',
            'name' => 'Google Admin',
            'email' => 'google-admin@example.com',
        ]);

        $otherUser = (new SocialiteUser)->setRaw(['email_verified' => true, 'hd' => 'example.com']);
        $otherUser->map([
            'id' => 'another-google-user',
            'name' => 'Another User',
            'email' => 'another@example.com',
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->times(3)->andReturn($googleUser, $otherUser, $googleUser);
        Socialite::shouldReceive('driver')->times(3)->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))->assertRedirect(route('setup.index'));

        $verified = $tester->verified($this->app['request'], GoogleOAuthTestService::CONTEXT_SETUP);
        $this->assertSame('google-admin@example.com', $verified['tested_email']);

        app(AuthenticationSettingsService::class)->saveConfiguration(
            manualEnabled: false,
            googleEnabled: true,
            googleConfiguration: [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'redirect_uri' => route('auth.google.callback'),
            ],
            googleVerification: $verified,
        );

        $this->assertTrue(app(AuthenticationSettingsService::class)->isSetupComplete());
        $this->assertTrue(app(AuthenticationSettingsService::class)->googleAuthEnabled());
        $this->assertDatabaseMissing('users', ['email' => 'google-admin@example.com']);

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'another@example.com']);

        $this->get(route('auth.google.callback'))->assertRedirect(route('live-wall.index'));

        $admin = User::query()->where('email', 'google-admin@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($admin->is_admin);
    }

    public function test_public_component_state_cannot_fake_google_oauth_verification(): void
    {
        $this->clearAuthenticationSetupState();

        $settings = app(AuthenticationSettingsService::class);
        $fingerprint = $settings->googleConfigurationFingerprint('client-id', 'client-secret', route('auth.google.callback'));

        Livewire::test(SetupWizard::class)
            ->set('setupToken', app(SetupAccessService::class)->token())
            ->set('manualAuthEnabled', '0')
            ->set('googleAuthEnabled', '1')
            ->set('googleClientId', 'client-id')
            ->set('googleClientSecret', 'client-secret')
            ->set('googleRedirectUri', route('auth.google.callback'))
            ->set('googleVerifiedFingerprint', $fingerprint)
            ->set('googleVerifiedEmail', 'fake@example.com')
            ->call('save')
            ->assertHasErrors(['googleClientId']);

        $this->assertFalse($settings->isSetupComplete());
    }

    public function test_google_validation_rejects_accounts_without_an_email_address(): void
    {
        $this->clearAuthenticationSetupState();
        $this->get(route('setup.index'))->assertOk();

        $tester = app(GoogleOAuthTestService::class);
        $tester->begin($this->app['request'], GoogleOAuthTestService::CONTEXT_SETUP, [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => route('auth.google.callback'),
        ]);

        $googleUser = (new SocialiteUser)->setRaw(['email_verified' => true, 'hd' => 'example.com']);
        $googleUser->map(['id' => 'google-user-without-email', 'name' => 'No Email']);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))->assertRedirect(route('setup.index'));

        $this->assertNull($tester->verified($this->app['request'], GoogleOAuthTestService::CONTEXT_SETUP));
        $this->assertFalse(app(AuthenticationSettingsService::class)->isSetupComplete());
    }

    public function test_setup_cannot_be_reopened_after_completion(): void
    {
        $this->get(route('setup.index'))->assertRedirect(route('login'));
    }
}
