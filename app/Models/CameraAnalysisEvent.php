<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CameraAnalysisEvent extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function labels(): HasMany
    {
        return $this->hasMany(CameraAnalysisLabel::class, 'event_id');
    }
}
