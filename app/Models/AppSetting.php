<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\AuthenticationSettingsService;
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
        $attributes = $this->getAttributes();

        $isSecret = ($attributes['key'] ?? null) === AuthenticationSettingsService::SETTING_GOOGLE_CLIENT_SECRET;

        return $this->auditFilterPayload([
            'id' => $this->getKey(),
            'key' => $attributes['key'] ?? null,
            'value' => $isSecret ? null : ($attributes['value'] ?? null),
            'network_storage_enabled' => (bool) ($attributes['network_storage_enabled'] ?? false),
            'network_storage_path' => $attributes['network_storage_path'] ?? null,
            'network_storage_username' => $attributes['network_storage_username'] ?? null,
        ]);
    }
}
