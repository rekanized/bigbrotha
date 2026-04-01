<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_redirect_starts_the_oauth_flow(): void
    {
        config()->set('services.google.client_id', 'client-id');
        config()->set('services.google.client_secret', 'client-secret');
        config()->set('services.google.redirect', 'https://monitor.schollinetz.com/auth/google/callback');

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->once()->with(['openid', 'profile', 'email'])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->get(route('auth.google.redirect'))
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_google_callback_creates_or_updates_the_user_and_logs_them_in(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($this->fakeGoogleUser());

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'operator@example.com',
            'google_id' => 'google-user-123',
            'avatar_url' => 'https://example.com/avatar.jpg',
        ]);
    }

    public function test_google_callback_links_an_existing_email_address(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'google_id' => null,
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($this->fakeGoogleUser());

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-user-123', $user->fresh()->google_id);
    }

    private function fakeGoogleUser(): SocialiteUser
    {
        $user = new SocialiteUser();
        $user->map([
            'id' => 'google-user-123',
            'name' => 'Control Shift',
            'email' => 'operator@example.com',
            'avatar' => 'https://example.com/avatar.jpg',
        ]);

        return $user;
    }
}