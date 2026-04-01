<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_from_the_allowed_ip_are_served(): void
    {
        $server = ['REMOTE_ADDR' => '192.168.1.1'];

        $this->withServerVariables($server)->get('/')->assertOk();
        $this->withServerVariables($server)->get('/camera-fleet')->assertOk();
        $this->withServerVariables($server)->get('/live-wall')->assertOk();
        $this->withServerVariables($server)->get('/discovery/onvif-sweep')->assertOk();
    }

    public function test_requests_from_other_ips_are_forbidden(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/');

        $response->assertForbidden();
    }
}
