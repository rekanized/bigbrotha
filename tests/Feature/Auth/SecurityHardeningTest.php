<?php

namespace Tests\Feature\Auth;

use App\Livewire\Admin\AdminJobQueue;
use App\Livewire\Admin\AuthSettingsPanel;
use App\Livewire\Admin\NetworkStorageSettingsPanel;
use App\Livewire\Auth\UnifiedLoginScreen;
use App\Livewire\Setup\SetupWizard;
use App\Models\AllowedLoginEmail;
use App\Models\AppSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\AuthenticationSettingsService;
use App\Services\GoogleOAuthTestService;
use App\Services\LocalAuthenticationService;
use App\Services\Relay\MediaMtxAccessTokenService;
use App\Services\SetupAccessService;
use App\Support\Logging\RedactSensitiveLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_login_throttles_normalized_accounts_even_from_different_addresses(): void
    {
        User::factory()->localOnly()->create(['email' => 'operator@example.com', 'password' => 'valid-password']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::test(UnifiedLoginScreen::class)
                ->set('email', 'OPERATOR@example.com')
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors('email')
                ->assertSet('password', '');
        }

        Livewire::withHeaders(['X-Forwarded-For' => '203.0.113.77'])->test(UnifiedLoginScreen::class)
            ->set('email', 'operator@example.com')
            ->set('password', 'valid-password')
            ->call('login')
            ->assertSee('Too many sign-in attempts');

        $this->assertGuest();
        $this->travel(61)->seconds();

        Livewire::test(UnifiedLoginScreen::class)
            ->set('email', 'operator@example.com')
            ->set('password', 'valid-password')
            ->call('login')
            ->assertRedirect(route('live-wall.index'));
    }

    public function test_ip_limit_prevents_spraying_unknown_accounts(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            RateLimiter::hit('login:ip:'.hash('sha256', '127.0.0.1'), 60);
        }

        Livewire::test(UnifiedLoginScreen::class)
            ->set('email', 'unknown@example.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertSee('Too many sign-in attempts');
    }

    public function test_stale_setup_snapshot_cannot_create_another_admin(): void
    {
        $this->clearAuthenticationSetupState();
        $snapshot = $this->snapshot($this->get(route('setup.index'))->getContent(), 'setup.setup-wizard');
        app(AuthenticationSettingsService::class)->saveConfiguration(true, false);

        $this->updateSnapshot($snapshot, 'save', [
            'setupToken' => app(SetupAccessService::class)->token(),
            'adminName' => 'Attacker',
            'adminEmail' => 'attacker@example.com',
            'adminPassword' => 'attacker-password',
            'adminPasswordConfirmation' => 'attacker-password',
        ])->assertForbidden();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_requires_the_server_token_before_mutating_settings(): void
    {
        $this->clearAuthenticationSetupState();
        Livewire::test(SetupWizard::class)->call('save')->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(app(AuthenticationSettingsService::class)->isSetupComplete());
    }

    public function test_setup_replay_cannot_start_an_oauth_test(): void
    {
        $this->clearAuthenticationSetupState();
        $component = Livewire::test(SetupWizard::class)->set('setupToken', app(SetupAccessService::class)->token());
        User::factory()->admin()->create();
        $component->call('beginGoogleTest')->assertForbidden();
    }

    public function test_admin_components_reject_guests_and_operators(): void
    {
        foreach ([AuthSettingsPanel::class, NetworkStorageSettingsPanel::class, AdminJobQueue::class] as $component) {
            Livewire::test($component)->assertForbidden();
            $this->actingAs(User::factory()->localOnly()->create());
            Livewire::test($component)->assertForbidden();
            Auth::forgetGuards();
        }
    }

    public function test_demoted_admin_cannot_replay_a_real_settings_snapshot(): void
    {
        $admin = User::factory()->admin()->localOnly()->create();
        $this->actingAs($admin);
        $snapshot = $this->snapshot($this->get(route('admin.settings.index'))->getContent(), 'admin.auth-settings-panel');
        $admin->forceFill(['is_admin' => false])->save();

        $this->updateSnapshot($snapshot, 'save', ['manualAuthEnabled' => '0'])->assertForbidden();
        $this->assertTrue(app(AuthenticationSettingsService::class)->manualAuthEnabled());
    }

    public function test_revoked_admin_cannot_clear_failed_jobs_with_an_existing_component(): void
    {
        $admin = User::factory()->admin()->localOnly()->create();
        $this->actingAs($admin);
        $component = Livewire::test(AdminJobQueue::class);
        $admin->forceFill(['is_admin' => false])->save();
        $component->call('clearFailedJobs')->assertForbidden();
    }

    public function test_saved_google_secret_is_absent_from_component_html_and_snapshot(): void
    {
        $this->configureGoogle();
        $this->actingAs(User::factory()->admin()->localOnly()->create());
        $this->get(route('admin.settings.index'))->assertOk()->assertDontSee('private-google-secret');
        Livewire::test(AuthSettingsPanel::class)->assertSet('googleClientSecret', '')->call('save')->assertHasNoErrors();
        $this->assertSame('private-google-secret', app(AuthenticationSettingsService::class)->googleConfiguration()['client_secret']);
    }

    public function test_google_requires_a_verified_email_and_nonempty_subject(): void
    {
        $this->configureGoogle();
        $google = $this->googleUser();
        $google->setRaw(['email_verified' => false, 'hd' => 'example.com']);
        $this->mockGoogle($google);
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_cannot_bootstrap_an_unconfigured_identity(): void
    {
        $this->configureGoogle();
        $this->mockGoogle($this->googleUser('attacker@example.com', 'attacker-id'));
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_bootstrap_matches_subject_as_well_as_email(): void
    {
        $this->configureGoogle();
        $this->mockGoogle($this->googleUser('operator@example.com', 'different-google-id'));
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_google_allowlisted_operator_is_not_promoted_when_admins_are_missing(): void
    {
        $this->configureGoogle();
        $user = User::factory()->localOnly()->create(['email' => 'operator@example.com']);
        AllowedLoginEmail::query()->create(['email' => $user->email]);
        $this->mockGoogle($this->googleUser());
        $this->get(route('auth.google.callback'))->assertRedirect(route('live-wall.index'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_google_cannot_replace_an_existing_subject_by_email(): void
    {
        $this->configureGoogle();
        $admin = User::factory()->admin()->create(['email' => 'operator@example.com', 'google_id' => 'existing-google-id']);
        AllowedLoginEmail::query()->create(['email' => $admin->email]);
        $this->mockGoogle($this->googleUser());
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame('existing-google-id', $admin->fresh()->google_id);
    }

    public function test_google_cannot_merge_two_distinct_accounts(): void
    {
        $this->configureGoogle();
        User::factory()->admin()->create(['email' => 'other@example.com', 'google_id' => 'google-user-123']);
        $victim = User::factory()->localOnly()->create(['email' => 'operator@example.com']);
        AllowedLoginEmail::query()->create(['email' => $victim->email]);
        $this->mockGoogle($this->googleUser());
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($victim->fresh()->google_id);
    }

    public function test_third_party_email_identity_cannot_claim_an_unlinked_local_account(): void
    {
        $this->configureGoogle();
        $victim = User::factory()->admin()->localOnly()->create(['email' => 'operator@example.com']);
        AllowedLoginEmail::query()->create(['email' => $victim->email]);
        $google = $this->googleUser()->setRaw(['email_verified' => true]);
        $this->mockGoogle($google);
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($victim->fresh()->google_id);
    }

    public function test_google_callback_without_oauth_state_cannot_authenticate(): void
    {
        $this->configureGoogle();
        $this->get(route('auth.google.callback', ['code' => 'forged-code']))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_pending_admin_oauth_test_requires_current_admin_access(): void
    {
        $admin = User::factory()->admin()->localOnly()->create();
        $this->actingAs($admin)->get(route('admin.settings.index'));
        $tester = app(GoogleOAuthTestService::class);
        $tester->begin($this->app['request'], GoogleOAuthTestService::CONTEXT_ADMIN, $this->googleConfiguration());
        $admin->forceFill(['is_admin' => false])->save();
        $this->get(route('auth.google.test.redirect'))->assertForbidden();
    }

    public function test_oauth_test_expires_after_ten_minutes(): void
    {
        $this->clearAuthenticationSetupState();
        $this->get(route('setup.index'));
        app(GoogleOAuthTestService::class)->begin($this->app['request'], GoogleOAuthTestService::CONTEXT_SETUP, $this->googleConfiguration());
        $this->travel(11)->minutes();
        $this->get(route('auth.google.test.redirect'))->assertRedirect(route('setup.index'));
    }

    public function test_password_reset_invalidates_the_previous_session_hash_and_remember_token(): void
    {
        $user = User::factory()->localOnly()->create(['password' => 'old-password']);
        $oldHash = $user->getAuthPassword();
        $oldToken = $user->remember_token;
        app(LocalAuthenticationService::class)->updateLocalPassword($user, 'new-valid-password');
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash, 'auth_method' => 'local']);
        $this->get(route('camera-fleet.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_disabled_local_auth_invalidates_active_local_sessions(): void
    {
        $user = User::factory()->localOnly()->create();
        app(AuthenticationSettingsService::class)->saveConfiguration(false, false);
        $this->actingAs($user)->withSession(['auth_method' => 'local']);
        $this->get(route('camera-fleet.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_removed_google_allowlist_entry_invalidates_active_google_sessions(): void
    {
        $this->configureGoogle();
        $user = User::factory()->googleOnly()->create(['email' => 'operator@example.com']);
        $this->actingAs($user)->withSession(['auth_method' => 'google']);
        $this->get(route('camera-fleet.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_read_tokens_stop_working_after_a_password_reset_or_access_revocation(): void
    {
        $user = User::factory()->localOnly()->create();
        $tokens = app(MediaMtxAccessTokenService::class);
        $token = $tokens->issueReadToken($user, 'camera-1-live');
        $this->assertNotNull($tokens->validate($token, 'camera-1-live', 'read', 'webrtc'));
        app(LocalAuthenticationService::class)->updateLocalPassword($user, 'new-valid-password');
        $this->assertNull($tokens->validate($token, 'camera-1-live', 'read', 'webrtc'));
        $token = $tokens->issueReadToken($user->fresh(), 'camera-1-live');
        $user->forceFill(['local_auth_enabled' => false, 'google_id' => null])->save();
        $this->assertNull($tokens->validate($token, 'camera-1-live', 'read', 'webrtc'));
    }

    public function test_login_security_headers_and_cookie_settings(): void
    {
        $response = $this->get(str_replace('http://', 'https://', route('login')));
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_untrusted_forwarded_headers_cannot_bypass_the_ip_allowlist(): void
    {
        config()->set('network.trusted_proxies', []);
        config()->set('network.website_allowed_ips', ['192.0.2.55']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-Forwarded-For' => '192.0.2.55', 'X-Forwarded-Proto' => 'https'])
            ->get(route('login'))->assertForbidden();
    }

    public function test_csrf_is_required_for_logout_and_livewire_mutations(): void
    {
        $this->app['env'] = 'production';
        $this->actingAs(User::factory()->localOnly()->create());
        $this->post(route('logout'))->assertStatus(419);
        $this->postJson(Livewire::getUpdateUri(), ['components' => []])->assertStatus(419);
    }

    public function test_multibyte_passwords_cannot_exceed_bcrypt_byte_limit(): void
    {
        $this->actingAs(User::factory()->admin()->localOnly()->create());
        $password = str_repeat('é', 40);
        $this->post(route('admin.users.local-accounts.store'), [
            'name' => 'Operator', 'email' => 'new@example.com', 'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors('password', null, 'localUser');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_host_header_cannot_control_public_redirects(): void
    {
        $this->get('http://attacker.invalid/login')->assertStatus(400);
    }

    public function test_relay_loopback_fallback_cannot_be_spoofed_with_forwarded_headers(): void
    {
        config()->set('network.trusted_proxies', ['*']);
        config()->set('mediamtx.auth.callback_secret', '');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->withHeaders(['X-Forwarded-For' => '127.0.0.1'])
            ->post(route('relay.auth.mediamtx'), [])->assertForbidden();
    }

    public function test_media_tokens_reject_tampering_scope_changes_and_exact_expiry(): void
    {
        $user = User::factory()->localOnly()->create();
        $tokens = app(MediaMtxAccessTokenService::class);
        $token = $tokens->issueReadToken($user, 'camera-1-live');
        $this->assertNull($tokens->validate($token.'tampered', 'camera-1-live', 'read', 'webrtc'));
        $this->assertNull($tokens->validate($token, 'camera-2-live', 'read', 'webrtc'));
        $this->assertNull($tokens->validate($token, 'camera-1-live', 'publish', 'webrtc'));
        $this->assertNull($tokens->validate($token, 'camera-1-live', 'read', 'rtsp'));
        $this->travel($tokens->ttl())->seconds();
        $this->assertNull($tokens->validate($token, 'camera-1-live', 'read', 'webrtc'));
    }

    public function test_media_tokens_require_a_private_signing_key(): void
    {
        $user = User::factory()->localOnly()->create();
        config()->set('mediamtx.auth.token_secret', '');
        $this->expectException(\RuntimeException::class);
        app(MediaMtxAccessTokenService::class)->issueReadToken($user, 'camera-1-live');
    }

    public function test_google_secret_ciphertext_is_excluded_from_audit_snapshots(): void
    {
        $this->configureGoogle();
        $setting = AppSetting::query()->where('key', AuthenticationSettingsService::SETTING_GOOGLE_CLIENT_SECRET)->firstOrFail();
        $ciphertext = $setting->value;
        $setting->update(['value' => Crypt::encryptString('replacement-secret')]);
        $logs = $setting->auditLogs()->get()->toJson();
        $this->assertStringNotContainsString($ciphertext, $logs);
        $this->assertStringNotContainsString($setting->fresh()->value, $logs);
        $this->assertStringNotContainsString('private-google-secret', $logs);
    }

    public function test_configured_logging_redacts_diagnostics_before_writing_them(): void
    {
        $path = $this->testStoragePath.'/redaction.log';
        config()->set('logging.channels.security-review', [
            'driver' => 'single', 'path' => $path,
            'tap' => [RedactSensitiveLogs::class],
        ]);
        $logger = Log::channel('security-review');
        $logger->warning('Failed rtsp://operator:camera-password@camera/live', [
            'client_secret' => 'google-secret',
            'exception' => new \RuntimeException('Failed https://app/relay?secret=callback-secret'),
        ]);
        $contents = file_get_contents($path);
        foreach (['camera-password', 'google-secret', 'callback-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }
        $this->assertStringContainsString('camera/live', $contents);
    }

    public function test_https_origin_generates_secure_links_without_trusting_proxy_headers(): void
    {
        config()->set('app.url', 'https://monitor.example.com');
        config()->set('network.trusted_proxies', []);
        app(AppServiceProvider::class, ['app' => $this->app])->boot();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('http://monitor.example.com/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->assertSame('https://monitor.example.com/css/app.css', asset('css/app.css'));
        $this->get('http://monitor.example.com/admin/settings')->assertRedirect('https://monitor.example.com/login');
    }

    public function test_http_origin_preserves_the_public_port_for_assets_and_redirects(): void
    {
        config()->set('app.url', 'http://monitor.example.com:8082');
        config()->set('network.trusted_proxies', []);
        app(AppServiceProvider::class, ['app' => $this->app])->boot();

        $this->get('http://monitor.example.com/login')
            ->assertOk()
            ->assertSee('http://monitor.example.com:8082/livewire-', false);
        $this->assertSame('http://monitor.example.com:8082/css/app.css', asset('css/app.css'));
        $this->get('http://monitor.example.com/admin/settings')
            ->assertRedirect('http://monitor.example.com:8082/login');
    }

    public function test_unknown_account_returns_generic_failure_with_production_hash_verification(): void
    {
        config()->set('hashing.bcrypt.verify', true);
        Hash::forgetDrivers();
        Livewire::test(UnifiedLoginScreen::class)
            ->set('email', 'unknown@example.com')
            ->set('password', 'invalid-password')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('not accepted');
        $this->assertGuest();
    }

    public function test_last_viable_admin_cannot_be_demoted_even_when_other_admins_exist(): void
    {
        $this->configureGoogle();
        $settings = app(AuthenticationSettingsService::class);
        $settings->saveConfiguration(false, true);
        $admin = User::factory()->admin()->googleOnly()->create(['email' => 'operator@example.com']);
        User::factory()->admin()->localOnly()->create();
        AllowedLoginEmail::query()->create(['email' => $admin->email]);
        $this->actingAs($admin)->withSession(['auth_method' => 'google']);
        $this->put(route('admin.users.admin-role', $admin), ['is_admin' => false])
            ->assertRedirect(route('admin.users.index'))->assertSessionHas('status_error');
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_last_viable_google_admin_approval_cannot_be_removed(): void
    {
        $this->configureGoogle();
        app(AuthenticationSettingsService::class)->saveConfiguration(false, true);
        $admin = User::factory()->admin()->googleOnly()->create(['email' => 'operator@example.com']);
        User::factory()->admin()->localOnly()->create();
        $approval = AllowedLoginEmail::query()->create(['email' => $admin->email]);
        $this->actingAs($admin)->withSession(['auth_method' => 'google']);
        $this->delete(route('admin.users.allowed-emails.destroy', $approval))
            ->assertRedirect(route('admin.users.index'))->assertSessionHas('status_error');
        $this->assertDatabaseHas('allowed_login_emails', ['id' => $approval->id]);
    }

    private function googleConfiguration(): array
    {
        return ['client_id' => 'client-id', 'client_secret' => 'private-google-secret', 'redirect_uri' => route('auth.google.callback')];
    }

    private function configureGoogle(): void
    {
        $settings = app(AuthenticationSettingsService::class);
        $configuration = $this->googleConfiguration();
        $settings->saveConfiguration(true, true, $configuration, [
            'fingerprint' => $settings->googleConfigurationFingerprint(...array_values($configuration)),
            'tested_at' => now()->toIso8601String(), 'tested_email' => 'operator@example.com', 'tested_google_id' => 'google-user-123',
        ]);
    }

    private function googleUser(string $email = 'operator@example.com', string $id = 'google-user-123'): SocialiteUser
    {
        return (new SocialiteUser)->setRaw(['email_verified' => true, 'hd' => 'example.com'])
            ->map(['id' => $id, 'name' => 'Operator', 'email' => $email]);
    }

    private function mockGoogle(SocialiteUser $user): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($user);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
    }

    private function snapshot(string $html, string $component): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            if (json_decode($snapshot, true)['memo']['name'] === $component) {
                return $snapshot;
            }
        }

        $this->fail('Component snapshot not found: '.$component);
    }

    private function updateSnapshot(string $snapshot, string $method, array $updates = []): TestResponse
    {
        return $this->withHeaders(['X-Livewire' => 'true'])->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => []]]]],
        ]);
    }
}
