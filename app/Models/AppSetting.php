<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use Auditable, HasFactory;

    protected $guarded = [];

    protected array $auditExclude = [
        'network_storage_password',
    ];

    protected function casts(): array
    {
        return [
            'network_storage_enabled' => 'boolean',
            'network_storage_password' => 'encrypted',
        ];
    }
}