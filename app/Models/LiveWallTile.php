<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'live_wall_id',
    'camera_id',
    'position',
    'orientation',
    'column_span',
    'row_span',
    'is_enabled',
])]
class LiveWallTile extends Model
{
    public function wall(): BelongsTo
    {
        return $this->belongsTo(LiveWall::class, 'live_wall_id');
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'column_span' => 'integer',
            'row_span' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}