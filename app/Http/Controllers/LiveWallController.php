<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\LiveWall;
use App\Models\LiveWallTile;
use App\Models\User;
use App\Services\CameraLiveStreamService;
use App\Services\Relay\MediaMtxAccessTokenService;
use App\Services\Relay\MediaMtxConfigService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LiveWallController extends Controller
{
    public function __invoke(
        Request $request,
        CameraLiveStreamService $streamService,
        MediaMtxConfigService $relayConfig,
        MediaMtxAccessTokenService $accessTokenService,
        MediaMtxProcessService $relayProcess,
    ): View
    {
        $relayStatus = $relayProcess->ensureRunning();
        $operator = $request->user();
        $canBootstrapSessions = $operator instanceof User && ($relayStatus['running'] ?? false);

        $availableWalls = LiveWall::query()
            ->where('is_active', true)
            ->withCount([
                'tiles as configured_tiles_count' => fn ($query) => $query->where('is_enabled', true),
            ])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $selectedWall = $this->resolveSelectedWall(
            $availableWalls,
            $request->string('wall')->toString(),
        );

        $tiles = $selectedWall?->tiles()
            ->where('is_enabled', true)
            ->whereHas('camera', fn ($query) => $query->where('is_enabled', true))
            ->with('camera')
            ->get()
            ->map(function (LiveWallTile $tile) use ($accessTokenService, $canBootstrapSessions, $operator, $request, $relayConfig, $selectedWall, $streamService): array {
                $camera = $tile->camera;

                if (!$camera instanceof Camera || !$selectedWall instanceof LiveWall) {
                    return [];
                }

                $liveSelection = $streamService->selectWallProfile($camera);
                $liveDefinition = $relayConfig->cameraLivePlaybackDefinition($camera);
                $whepUrl = $liveDefinition !== null
                    ? $relayConfig->browserWhepUrlForPath($liveDefinition['path'], $request)
                    : null;
                $path = $liveDefinition['path'] ?? null;
                $readerUrl = is_string($whepUrl) && $whepUrl !== ''
                    ? $relayConfig->browserReaderUrlForPath((string) $path, $request)
                    : null;

                $sessionBootstrap = null;
                $sessionUrl = null;

                if ($canBootstrapSessions && is_array($liveSelection) && is_string($whepUrl) && $whepUrl !== '' && is_string($readerUrl) && $readerUrl !== '') {
                    $sessionUrl = route('live-wall.session', ['camera' => $camera]);
                    $sessionBootstrap = [
                        'whep_url' => $whepUrl,
                        'reader_url' => $readerUrl,
                        'access_token' => $accessTokenService->issueReadToken($operator, $path),
                        'expires_in' => $accessTokenService->ttl(),
                        'issued_at' => now()->timestamp,
                    ];
                }

                return [
                    'tile' => $tile,
                    'camera' => $camera,
                    'orientation' => $tile->orientation,
                    'columnSpan' => max(1, min($selectedWall->grid_columns, $tile->column_span)),
                    'rowSpan' => max(1, $tile->row_span),
                    'liveSelection' => $liveSelection,
                    'playerPageUrl' => route('live-wall.player', ['camera' => $camera]),
                    'sessionUrl' => $sessionUrl,
                    'webrtcWhepUrl' => $whepUrl,
                    'readerUrl' => $readerUrl,
                    'sessionBootstrap' => $sessionBootstrap,
                    'webrtcPath' => $path,
                ];
            })
            ->filter() ?? collect();

        return view('live-wall.index', [
            'availableWalls' => $availableWalls,
            'selectedWall' => $selectedWall,
            'tiles' => $tiles,
            'relayStatus' => $relayStatus,
        ]);
    }

    /**
     * @param  Collection<int, LiveWall>  $availableWalls
     */
    private function resolveSelectedWall(Collection $availableWalls, string $requestedSlug): ?LiveWall
    {
        if ($requestedSlug !== '') {
            $matchingWall = $availableWalls->firstWhere('slug', $requestedSlug);

            if ($matchingWall instanceof LiveWall) {
                return $matchingWall;
            }
        }

        $defaultWall = $availableWalls->firstWhere('is_default', true);

        if ($defaultWall instanceof LiveWall) {
            return $defaultWall;
        }

        $firstWall = $availableWalls->first();

        return $firstWall instanceof LiveWall ? $firstWall : null;
    }
}