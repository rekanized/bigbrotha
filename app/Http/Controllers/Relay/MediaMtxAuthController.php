<?php

namespace App\Http\Controllers\Relay;

use App\Http\Controllers\Controller;
use App\Services\Relay\MediaMtxAccessTokenService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class MediaMtxAuthController extends Controller
{
    public function __invoke(Request $request, MediaMtxAccessTokenService $accessTokenService): Response
    {
        $this->assertTrustedCaller($request);

        $path = trim((string) $request->input('path', ''));
        $action = trim((string) $request->input('action', ''));
        $protocol = trim((string) $request->input('protocol', ''));
        $token = trim((string) $request->input('token', ''));

        if ($this->isInternalPublisher($request, $path, $action, $protocol)) {
            return response()->noContent();
        }

        if ($this->isInternalReader($request, $path, $action, $protocol)) {
            return response()->noContent();
        }

        abort_if($path === '' || $action === '' || $protocol === '' || $token === '', Response::HTTP_UNAUTHORIZED);
        abort_if($accessTokenService->validate($token, $path, $action, $protocol) === null, Response::HTTP_UNAUTHORIZED);

        return response()->noContent();
    }

    private function assertTrustedCaller(Request $request): void
    {
        $expectedSecret = trim((string) config('mediamtx.auth.callback_secret', ''));

        if ($expectedSecret !== '') {
            abort_unless(hash_equals($expectedSecret, (string) $request->query('secret')), Response::HTTP_FORBIDDEN);

            return;
        }

        abort_unless(IpUtils::checkIp((string) $request->ip(), ['127.0.0.1', '::1']), Response::HTTP_FORBIDDEN);
    }

    private function isInternalPublisher(Request $request, string $path, string $action, string $protocol): bool
    {
        if ($action !== 'publish' || $protocol !== 'rtsp' || !preg_match('/^camera-\d+-(live|recording(?:-profile-\d+)?)$/', $path)) {
            return false;
        }

        $expectedUser = trim((string) config('mediamtx.auth.publisher_user', ''));
        $expectedPass = trim((string) config('mediamtx.auth.publisher_pass', ''));
        $user = trim((string) $request->input('user', ''));
        $password = trim((string) $request->input('password', ''));
        $publisherIp = trim((string) $request->input('ip', ''));

        if ($expectedUser === '' || $expectedPass === '' || $user === '' || $password === '') {
            return false;
        }

        return hash_equals($expectedUser, $user)
            && hash_equals($expectedPass, $password)
            && IpUtils::checkIp($publisherIp, ['127.0.0.1', '::1']);
    }

    private function isInternalReader(Request $request, string $path, string $action, string $protocol): bool
    {
        if ($action !== 'read' || $protocol !== 'rtsp' || !preg_match('/^camera-\d+-recording(?:-profile-\d+)?$/', $path)) {
            return false;
        }

        $expectedUser = trim((string) config('mediamtx.auth.reader_user', ''));
        $expectedPass = trim((string) config('mediamtx.auth.reader_pass', ''));
        $user = trim((string) $request->input('user', ''));
        $password = trim((string) $request->input('password', ''));
        $readerIp = trim((string) $request->input('ip', ''));

        if ($expectedUser === '' || $expectedPass === '' || $user === '' || $password === '') {
            return false;
        }

        return hash_equals($expectedUser, $user)
            && hash_equals($expectedPass, $password)
            && IpUtils::checkIp($readerIp, ['127.0.0.1', '::1']);
    }
}