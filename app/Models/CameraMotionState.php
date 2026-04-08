<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CameraMotionState extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_processed_segment_at' => 'datetime',
            'event_started_at' => 'datetime',
            'last_motion_at' => 'datetime',
            'finalize_after' => 'datetime',
            'source_profile_index' => 'integer',
        ];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function activeRecording(): BelongsTo
    {
        return $this->belongsTo(CameraRecording::class, 'active_recording_id');
    }
}