<?php

namespace Tests\Unit;

use App\Support\Logging\SensitiveDataRedactor;
use PHPUnit\Framework\TestCase;

class SensitiveDataRedactorTest extends TestCase
{
    public function test_it_redacts_uri_credentials_and_sensitive_query_values(): void
    {
        $message = SensitiveDataRedactor::message('ffmpeg rtsp://operator:s%40cret@camera:554/stream?token=private-token&profile=1 https://app/relay?secret=callback-secret');
        $this->assertStringNotContainsString('operator', $message);
        $this->assertStringNotContainsString('s%40cret', $message);
        $this->assertStringNotContainsString('private-token', $message);
        $this->assertStringNotContainsString('callback-secret', $message);
        $this->assertStringContainsString('camera:554/stream', $message);
        $this->assertStringContainsString('profile=1', $message);
    }

    public function test_it_redacts_nested_context_and_exception_messages(): void
    {
        $context = SensitiveDataRedactor::context([
            'password' => 'secret-password',
            'nested' => ['client_secret' => 'google-secret', 'uri' => 'rtsp://user:password@camera/live'],
            'exception' => new \RuntimeException('Failed: https://app/callback?code=oauth-code'),
            'camera_id' => 12,
        ]);
        $encoded = json_encode($context, JSON_THROW_ON_ERROR);
        foreach (['secret-password', 'google-secret', 'user:password', 'oauth-code'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertSame(12, $context['camera_id']);
    }
}
