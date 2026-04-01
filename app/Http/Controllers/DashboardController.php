<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\RecorderStatusService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(RecorderStatusService $recorderStatusService): View
    {
        $recorderStatus = $recorderStatusService->snapshot();
        $totalCameras = Camera::query()->count();
        $activeCameras = Camera::query()->where('is_enabled', true)->count();
        $onvifCapable = Camera::query()->where('supports_onvif', true)->count();
        $rtspCapable = Camera::query()->where('supports_rtsp', true)->count();
        $recentlySeen = Camera::query()->whereNotNull('last_seen_at')->where('last_seen_at', '>=', now()->subMinutes(10))->count();
        $offline = Camera::query()->whereNotNull('last_seen_at')->where('last_seen_at', '<', now()->subMinutes(10))->count();
        $neverSeen = Camera::query()->whereNull('last_seen_at')->count();
        $recentCameras = Camera::query()->latest('updated_at')->limit(5)->get();

        $metricCards = [
            [
                'value' => $activeCameras,
                'label' => 'Active cameras',
                'detail' => $totalCameras === 0 ? 'No cameras have been saved yet.' : $totalCameras.' cameras in the fleet database.',
                'icon' => 'AC',
                'tone' => 'blue',
            ],
            [
                'value' => $onvifCapable,
                'label' => 'ONVIF ready',
                'detail' => 'Devices flagged as available for ONVIF control.',
                'icon' => 'ON',
                'tone' => 'violet',
            ],
            [
                'value' => $rtspCapable,
                'label' => 'RTSP ready',
                'detail' => 'Camera records with stream connectivity enabled.',
                'icon' => 'RT',
                'tone' => 'amber',
            ],
            [
                'value' => $recorderStatus['is_ready'] ? 'Ready' : 'Issue',
                'label' => 'Recorder stack',
                'detail' => $recorderStatus['is_ready'] ? 'ffmpeg, ffprobe, and temp storage are available.' : 'Check binary paths or temp directory permissions.',
                'icon' => 'REC',
                'tone' => $recorderStatus['is_ready'] ? 'green' : 'amber',
            ],
        ];

        return view('dashboard.index', [
            'metricCards' => $metricCards,
            'recorderStatus' => $recorderStatus,
            'recentCameras' => $recentCameras,
            'healthBreakdown' => [
                'recently_seen' => $recentlySeen,
                'offline' => $offline,
                'never_seen' => $neverSeen,
            ],
        ]);
    }
}