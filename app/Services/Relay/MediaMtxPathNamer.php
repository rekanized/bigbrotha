<?php

namespace App\Services\Relay;

use App\Models\Camera;

class MediaMtxPathNamer
{
    public function livePathName(Camera $camera): string
    {
        return 'camera-'.$camera->getKey().'-live';
    }

    public function liveProfilePathName(Camera $camera, ?int $profileIndex = null): string
    {
        return $profileIndex === null
            ? $this->livePathName($camera)
            : 'camera-'.$camera->getKey().'-live-profile-'.$profileIndex;
    }

    public function sourcePathName(Camera $camera, ?int $profileIndex = null): string
    {
        return $profileIndex === null
            ? 'camera-'.$camera->getKey().'-source'
            : 'camera-'.$camera->getKey().'-source-profile-'.$profileIndex;
    }
}
