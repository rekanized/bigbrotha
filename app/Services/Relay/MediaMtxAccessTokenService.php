<?php

namespace App\Services\Relay;

use App\Models\User;
use InvalidArgumentException;

class MediaMtxAccessTokenService
{
    public function issueReadToken(User $user, string $path): string
    {
        $googleId = trim((string) $user->google_id);

        if ($googleId === '') {
            throw new InvalidArgumentException('Media access tokens require a Google-authenticated operator account.');
        }

        $payload = [
            'sub' => $user->getKey(),
            'email' => $user->email,
            'google_id' => $googleId,
            'path' => $path,
            'action' => 'read',
            'protocol' => 'webrtc',
            'iat' => now()->timestamp,
            'exp' => now()->addSeconds($this->ttl())->timestamp,
        ];

        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, $this->secret(), true);

        return $encodedPayload.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validate(string $token, string $path, string $action, string $protocol): ?array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $encodedSignature] = $parts;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret(), true));

        if (!hash_equals($expectedSignature, $encodedSignature)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (!is_array($payload)) {
            return null;
        }

        if (($payload['exp'] ?? 0) < now()->timestamp) {
            return null;
        }

        $expectedAction = $this->normalizeAction($action);
        $expectedProtocol = $this->normalizeProtocol($protocol, $expectedAction);
        $payloadAction = $this->normalizeAction((string) ($payload['action'] ?? ''));
        $payloadProtocol = $this->normalizeProtocol((string) ($payload['protocol'] ?? ''), $payloadAction);

        if (($payload['path'] ?? null) !== $path || $payloadAction !== $expectedAction || $payloadProtocol !== $expectedProtocol) {
            return null;
        }

        $userId = (int) ($payload['sub'] ?? 0);

        if ($userId < 1) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null
            || trim((string) $user->google_id) === ''
            || $user->email !== ($payload['email'] ?? null)
            || $user->google_id !== ($payload['google_id'] ?? null)) {
            return null;
        }

        return $payload;
    }

    public function ttl(): int
    {
        return max(30, (int) config('mediamtx.auth.token_ttl', 180));
    }

    private function secret(): string
    {
        return (string) config('mediamtx.auth.token_secret', config('app.key'));
    }

    private function normalizeAction(string $action): string
    {
        return strtolower(trim($action));
    }

    private function normalizeProtocol(string $protocol, string $action): string
    {
        $normalized = strtolower(trim($protocol));

        if ($this->normalizeAction($action) === 'read' && in_array($normalized, ['webrtc', 'whep', 'http', 'https'], true)) {
            return 'webrtc';
        }

        return $normalized;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;

        return (string) base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);
    }
}