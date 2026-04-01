<?php

namespace Tests\Feature\Relay;

use App\Models\User;
use App\Services\Relay\MediaMtxAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaMtxAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_mediamtx_auth_callback_accepts_a_valid_signed_read_token(): void
    {
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.token_secret', 'stream-secret');

        $user = User::factory()->create();
        $token = app(MediaMtxAccessTokenService::class)->issueReadToken($user, 'camera-7-live');

        $this->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
            'path' => 'camera-7-live',
            'action' => 'read',
            'protocol' => 'webrtc',
            'token' => $token,
        ])->assertNoContent();
    }

    public function test_mediamtx_auth_callback_rejects_an_invalid_token(): void
    {
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.token_secret', 'stream-secret');

        $this->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
            'path' => 'camera-7-live',
            'action' => 'read',
            'protocol' => 'webrtc',
            'token' => 'invalid-token',
        ])->assertUnauthorized();
    }

    public function test_mediamtx_auth_callback_accepts_the_internal_local_rtsp_publisher(): void
    {
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.publisher_user', 'publisher');
        config()->set('mediamtx.auth.publisher_pass', 'publisher-pass');

        $this->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
            'user' => 'publisher',
            'password' => 'publisher-pass',
            'ip' => '127.0.0.1',
            'path' => 'camera-7-live',
            'action' => 'publish',
            'protocol' => 'rtsp',
            'token' => '',
        ])->assertNoContent();
    }
}