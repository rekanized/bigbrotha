<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CameraAnalysisLabel extends Model
{
    public const CLASSES = ['person', 'cat', 'dog', 'car', 'motorcycle', 'bus', 'truck'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['observed_at' => 'array', 'confidence' => 'float', 'was_associated' => 'boolean'];
    }

    public static function filterClasses(string $value): array
    {
        return match ($value) {
            'animals' => ['cat', 'dog'],
            'vehicles' => ['car', 'motorcycle', 'bus', 'truck'],
            default => in_array($value, self::CLASSES, true) ? [$value] : [],
        };
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CameraAnalysisEvent::class, 'event_id');
    }

    public function recordings(): BelongsToMany
    {
        return $this->belongsToMany(CameraRecording::class, 'camera_analysis_label_recording', 'label_id', 'recording_id');
    }
}
