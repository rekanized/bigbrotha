<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['email', 'added_by_user_id'])]
class AllowedLoginEmail extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        static::saving(function (self $allowedLoginEmail): void {
            $allowedLoginEmail->email = self::normalizeEmail((string) $allowedLoginEmail->email);
        });
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public static function isAllowed(string $email): bool
    {
        return static::query()
            ->where('email', static::normalizeEmail($email))
            ->exists();
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}