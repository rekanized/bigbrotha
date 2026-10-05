<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'description',
    'grid_columns',
    'default_tile_orientation',
    'is_default',
    'is_active',
])]
class LiveWall extends Model
{
    public function tiles(): HasMany
    {
        return $this->hasMany(LiveWallTile::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function enabledTiles(): HasMany
    {
        return $this->tiles()->where('is_enabled', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grid_columns' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
