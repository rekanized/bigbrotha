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

    /**
     * Avoid touching excluded encrypted attributes while audit snapshots are built.
     * Legacy plaintext SMB passwords can exist in older rows and should not break saves.
     *
     * @return array<string, mixed>
     */
    protected function auditCurrentValues(): array
    {
        return $this->auditFilterPayload([
            'id' => $this->getKey(),
            'key' => $this->getRawOriginal('key'),
            'value' => $this->getRawOriginal('value'),
            'network_storage_enabled' => (bool) $this->getRawOriginal('network_storage_enabled'),
            'network_storage_path' => $this->getRawOriginal('network_storage_path'),
            'network_storage_username' => $this->getRawOriginal('network_storage_username'),
        ]);
    }
}