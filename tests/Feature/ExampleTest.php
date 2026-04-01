<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_requests_from_the_allowed_ip_are_served(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '78.68.180.104'])
            ->get('/');

        $response->assertStatus(200);
    }

    public function test_requests_from_other_ips_are_forbidden(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/');

        $response->assertForbidden();
    }
}
