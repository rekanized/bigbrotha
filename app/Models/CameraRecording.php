<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CameraRecording extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    /**
     * @return array<int, string>
     */
    public static function pendingStatuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function filterableStatuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
            self::STATUS_RECORDED,
            self::STATUS_SKIPPED,
            self::STATUS_FAILED,
        ];
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::pendingStatuses(), true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'file_size_bytes' => 'integer',
            'source_profile_index' => 'integer',
            'motion_score' => 'decimal:4',
        ];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }
}