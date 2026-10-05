<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditContextResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $user = Auth::user();
        $request = $this->httpRequest();

        return [
            'user_id' => $user?->getAuthIdentifier(),
            'actor_type' => $user instanceof Authenticatable ? AuditLog::ACTOR_TYPE_USER : AuditLog::ACTOR_TYPE_SYSTEM,
            'actor_label' => $user instanceof Authenticatable ? $this->userLabel($user) : 'System',
            'source' => $this->resolveSource($request),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ];
    }

    protected function httpRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request instanceof Request) {
            return null;
        }

        $method = $request->server('REQUEST_METHOD');

        if (! is_string($method) || trim($method) === '') {
            return null;
        }

        if (app()->runningInConsole() && $request->route() === null) {
            return null;
        }

        return $request;
    }

    protected function resolveSource(?Request $request): string
    {
        if ($request instanceof Request) {
            return AuditLog::SOURCE_HTTP;
        }

        $queueJob = app()->bound('queue.job') ? app('queue.job') : null;

        if ($queueJob !== null) {
            return AuditLog::SOURCE_QUEUE;
        }

        if (app()->runningInConsole()) {
            return AuditLog::SOURCE_CONSOLE;
        }

        return AuditLog::SOURCE_SYSTEM;
    }

    protected function userLabel(Authenticatable $user): string
    {
        $name = $user->name ?? null;

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $email = $user->email ?? null;

        if (is_string($email) && trim($email) !== '') {
            return trim($email);
        }

        return 'User #'.$user->getAuthIdentifier();
    }
}
