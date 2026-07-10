<?php

namespace Tests\Feature\Relay;

use App\Models\User;
use App\Services\Relay\MediaMtxAccessTokenService;
use InvalidArgumentException;
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

    public function test_mediamtx_auth_callback_accepts_whep_protocol_for_a_valid_signed_read_token(): void
    {
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.token_secret', 'stream-secret');

        $user = User::factory()->create();
        $token = app(MediaMtxAccessTokenService::class)->issueReadToken($user, 'camera-7-live');

        $this->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
            'path' => 'camera-7-live',
            'action' => 'read',
            'protocol' => 'whep',
            'token' => $token,
        ])->assertNoContent();
    }

    public function test_mediamtx_auth_callback_accepts_http_protocol_for_a_valid_signed_read_token(): void
    {
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.token_secret', 'stream-secret');

        $user = User::factory()->create();
        $token = app(MediaMtxAccessTokenService::class)->issueReadToken($user, 'camera-7-live');

        $this->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
            'path' => 'camera-7-live',
            'action' => 'read',
            'protocol' => 'http',
            'token' => $token,
        ])->assertNoContent();
    }

    public function test_mediamtx_auth_callback_ignores_website_ip_restrictions_for_the_relay_callback(): void
    {
        config()->set('network.website_allowed_ips', ['10.0.0.1']);
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.token_secret', 'stream-secret');

        $user = User::factory()->create();
        $token = app(MediaMtxAccessTokenService::class)->issueReadToken($user, 'camera-7-live');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
                'path' => 'camera-7-live',
                'action' => 'read',
                'protocol' => 'webrtc',
                'token' => $token,
            ])
            ->assertNoContent();
    }

    public function test_mediamtx_auth_callback_accepts_a_valid_read_token_from_the_callback_query_payload(): void
    {
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.token_secret', 'stream-secret');

        $user = User::factory()->create();
        $token = app(MediaMtxAccessTokenService::class)->issueReadToken($user, 'camera-7-live');

        $this->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
            'path' => 'camera-7-live',
            'action' => 'read',
            'protocol' => 'webrtc',
            'query' => http_build_query(['token' => $token]),
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

    public function test_media_tokens_require_a_stored_operator(): void
    {
        $user = User::factory()->make();

        $this->expectException(InvalidArgumentException::class);

        app(MediaMtxAccessTokenService::class)->issueReadToken($user, 'camera-7-live');
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

    public function test_mediamtx_auth_callback_accepts_the_internal_local_rtsp_reader_for_live_paths(): void
    {
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.reader_user', 'internal-reader');
        config()->set('mediamtx.auth.reader_pass', 'reader-pass');

        $this->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
            'user' => 'internal-reader',
            'password' => 'reader-pass',
            'ip' => '127.0.0.1',
            'path' => 'camera-7-live',
            'action' => 'read',
            'protocol' => 'rtsp',
            'token' => '',
        ])->assertNoContent();
    }

    public function test_mediamtx_auth_callback_accepts_internal_rtsp_reader_from_a_configured_docker_bridge_ip(): void
    {
        config()->set('mediamtx.auth.callback_secret', 'relay-secret');
        config()->set('mediamtx.auth.reader_user', 'internal-reader');
        config()->set('mediamtx.auth.reader_pass', 'reader-pass');
        config()->set('mediamtx.auth.reader_allowed_ips', ['127.0.0.1', '::1', '172.16.0.0/12']);

        $this->post(route('relay.auth.mediamtx', ['secret' => 'relay-secret']), [
            'user' => 'internal-reader',
            'password' => 'reader-pass',
            'ip' => '172.23.0.5',
            'path' => 'camera-7-source',
            'action' => 'read',
            'protocol' => 'rtsp',
            'token' => '',
        ])->assertNoContent();
    }
}
