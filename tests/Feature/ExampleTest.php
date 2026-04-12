<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_requests_from_the_allowed_ip_are_served(): void
    {
        $operator = User::factory()->create();
        $server = ['REMOTE_ADDR' => '192.168.1.1'];

        config()->set('network.website_allowed_ips', ['192.168.1.1']);

        $this->actingAs($operator)->withServerVariables($server)->get('/')->assertRedirect('/camera-fleet');
        $this->actingAs($operator)->withServerVariables($server)->get('/camera-fleet')->assertOk();
        $this->actingAs($operator)->withServerVariables($server)->get('/live-wall')->assertOk();
    }

    public function test_authenticated_requests_from_other_ips_are_forbidden(): void
    {
        $operator = User::factory()->create();

        config()->set('network.website_allowed_ips', ['192.168.1.1']);

        $response = $this
            ->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/');

        $response->assertForbidden();
    }
}
