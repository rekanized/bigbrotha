<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\UnifiedLoginScreen;
use App\Models\User;
use App\Services\AuthenticationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ManualAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_login_authenticates_a_local_operator(): void
    {
        $operator = User::factory()->localOnly()->create([
            'email' => 'operator@example.com',
            'password' => 'password',
            'local_auth_enabled' => true,
        ]);

        Livewire::test(UnifiedLoginScreen::class)
            ->set('email', 'operator@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('live-wall.index'));

        $this->assertAuthenticatedAs($operator->fresh());
    }

    public function test_local_login_is_rejected_when_local_authentication_is_disabled(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => AuthenticationSettingsService::SETTING_MANUAL_AUTH_ENABLED],
            ['value' => '0', 'updated_at' => now(), 'created_at' => now()]
        );

        User::factory()->localOnly()->create([
            'email' => 'operator@example.com',
            'password' => 'password',
            'local_auth_enabled' => true,
        ]);

        Livewire::test(UnifiedLoginScreen::class)
            ->set('email', 'operator@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    }
}
