<?php

namespace App\Services;

use App\Models\AppSetting;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class ApplicationSettingsService
{
    public const SETTING_APP_TIMEZONE = 'app_timezone';

    public const SETTING_NETWORK_STORAGE = '__network_storage__';

    /**
     * @var array<string, string>|null
     */
    private ?array $loadedSettings = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $loadedNetworkStorage = null;

    public function apply(): void
    {
        config(['app.operator_timezone' => $this->appTimezone()]);
    }

    public function appTimezone(): string
    {
        $fallbackTimezone = (string) config('app.default_timezone', config('app.timezone', 'Europe/Stockholm'));
        $configuredTimezone = (string) ($this->setting(self::SETTING_APP_TIMEZONE) ?? $fallbackTimezone);

        return in_array($configuredTimezone, timezone_identifiers_list(), true)
            ? $configuredTimezone
            : $fallbackTimezone;
    }

    /**
     * @return array<string, string>
     */
    public function timezoneOptions(): array
    {
        return [
            'Europe/Stockholm' => 'Europe/Stockholm (Stockholm, CET/CEST)',
            'Europe/Amsterdam' => 'Europe/Amsterdam (Amsterdam, CET/CEST)',
            'Europe/Berlin' => 'Europe/Berlin (Berlin, CET/CEST)',
            'Europe/Copenhagen' => 'Europe/Copenhagen (Copenhagen, CET/CEST)',
            'Europe/Helsinki' => 'Europe/Helsinki (Helsinki, EET/EEST)',
            'Europe/London' => 'Europe/London (London, GMT/BST)',
            'UTC' => 'UTC',
        ];
    }

    public function saveAppTimezone(string $timezone): void
    {
        if (!array_key_exists($timezone, $this->timezoneOptions())) {
            throw new InvalidArgumentException('The selected timezone is not supported by the admin settings page.');
        }

        AppSetting::query()->updateOrCreate(
            ['key' => self::SETTING_APP_TIMEZONE],
            ['value' => $timezone],
        );

        $this->loadedSettings = null;
        $this->apply();
    }

    /**
     * @return array{enabled: bool, path: string, username: string, has_password: bool, configured: bool, path_valid: bool}
     */
    public function networkStorageSettings(): array
    {
        $record = $this->networkStorageRecord();
        $path = $this->nullableString($record['path'] ?? null) ?? '';
        $username = $this->nullableString($record['username'] ?? null) ?? '';
        $hasPassword = is_string($record['password'] ?? null) && trim((string) $record['password']) !== '';
        $configured = $path !== '' && $username !== '' && $hasPassword;

        return [
            'enabled' => (bool) ($record['enabled'] ?? false),
            'path' => $path,
            'username' => $username,
            'has_password' => $hasPassword,
            'configured' => $configured,
            'path_valid' => $path === '' ? false : $this->parseNetworkStoragePath($path) !== null,
        ];
    }

    /**
     * @return array{host: string, share: string, root: string, username: string, password: string}|null
     */
    public function networkStorageDiskConfig(): ?array
    {
        $record = $this->networkStorageRecord();

        if (!(bool) ($record['enabled'] ?? false)) {
            return null;
        }

        $path = $this->nullableString($record['path'] ?? null);
        $username = $this->nullableString($record['username'] ?? null);
        $password = $this->nullableString($record['password'] ?? null);

        if ($path === null || $username === null || $password === null) {
            return null;
        }

        $connection = $this->parseNetworkStoragePath($path);

        if ($connection === null) {
            return null;
        }

        return array_merge($connection, [
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function networkStorageEnabled(): bool
    {
        return $this->networkStorageDiskConfig() !== null;
    }

    public function saveNetworkStorageSettings(bool $enabled, ?string $path, ?string $username, ?string $password, bool $preserveExistingPassword = false): void
    {
        $setting = AppSetting::query()->firstOrNew(['key' => self::SETTING_NETWORK_STORAGE]);
        $normalizedPassword = $this->nullableString($password);

        if (!$setting->exists) {
            $setting->value = null;
        }

        $setting->network_storage_enabled = $enabled;
        $setting->network_storage_path = $this->nullableString($path);
        $setting->network_storage_username = $this->nullableString($username);

        if ($normalizedPassword !== null) {
            $setting->network_storage_password = $normalizedPassword;
        } elseif (!$preserveExistingPassword) {
            $setting->network_storage_password = null;
        }

        $setting->save();

        $this->loadedNetworkStorage = null;
    }

    public function toDisplayTimezone(DateTimeInterface $value): Carbon
    {
        return Carbon::instance($value)->setTimezone($this->appTimezone());
    }

    public function formatDateTime(?DateTimeInterface $value, string $format = 'Y-m-d H:i:s', bool $includeTimezone = true): ?string
    {
        if (!$value instanceof DateTimeInterface) {
            return null;
        }

        $localized = $this->toDisplayTimezone($value);
        $label = $localized->format($format);

        return $includeTimezone ? $label.' '.$localized->format('T') : $label;
    }

    public function formatTimeRange(?DateTimeInterface $start, ?DateTimeInterface $end, string $format = 'H:i:s', bool $includeTimezone = true): ?string
    {
        if (!$start instanceof DateTimeInterface || !$end instanceof DateTimeInterface) {
            return null;
        }

        $localizedStart = $this->toDisplayTimezone($start);
        $localizedEnd = $this->toDisplayTimezone($end);
        $label = $localizedStart->format($format).' - '.$localizedEnd->format($format);

        return $includeTimezone ? $label.' '.$localizedEnd->format('T') : $label;
    }

    public function formatStoredTimestamp(?string $value, string $format = 'Y-m-d H:i:s', bool $includeTimezone = true): ?string
    {
        $timestamp = $this->parseStoredTimestamp($value);

        return $timestamp instanceof Carbon
            ? $this->formatDateTime($timestamp, $format, $includeTimezone)
            : null;
    }

    public function parseStoredTimestamp(?string $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmedValue = trim($value);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$/', $trimmedValue) === 1) {
                return Carbon::createFromFormat('Y-m-d H:i:s T', $trimmedValue, 'UTC')->utc();
            }

            return Carbon::parse($trimmedValue, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public function startOfDisplayDayUtc(string $value): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', trim($value), $this->appTimezone())
            ->startOfDay()
            ->utc();
    }

    public function parseDisplayDateTimeToUtc(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $trimmedValue = trim($value);

            if (preg_match('/(Z|[+-]\d{2}:?\d{2}| UTC)$/', $trimmedValue) === 1) {
                return Carbon::parse($trimmedValue)->utc();
            }

            return Carbon::parse($trimmedValue, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public function javascriptTimezone(): string
    {
        return $this->appTimezone();
    }

    /**
     * @return array{host: string, share: string, root: string}|null
     */
    public function parseNetworkStoragePath(?string $path): ?array
    {
        $normalizedPath = $this->nullableString($path);

        if ($normalizedPath === null) {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', $normalizedPath);

        if (str_starts_with(strtolower($normalizedPath), 'smb://')) {
            $normalizedPath = substr($normalizedPath, 6);
        }

        $normalizedPath = ltrim($normalizedPath, '/');
        $segments = array_values(array_filter(explode('/', $normalizedPath), static fn (string $segment): bool => trim($segment) !== ''));

        if (count($segments) < 2) {
            return null;
        }

        return [
            'host' => $segments[0],
            'share' => $segments[1],
            'root' => implode('/', array_slice($segments, 2)),
        ];
    }

    private function setting(string $key): ?string
    {
        return $this->settings()[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        if (is_array($this->loadedSettings)) {
            return $this->loadedSettings;
        }

        if (!$this->settingsTableExists()) {
            return $this->loadedSettings = [];
        }

        try {
            return $this->loadedSettings = AppSetting::query()
                ->where('key', '!=', self::SETTING_NETWORK_STORAGE)
                ->pluck('value', 'key')
                ->map(fn (mixed $value): string => (string) $value)
                ->all();
        } catch (Throwable) {
            return $this->loadedSettings = [];
        }
    }

    /**
     * @return array{enabled: bool, path: ?string, username: ?string, password: ?string}
     */
    private function networkStorageRecord(): array
    {
        if (is_array($this->loadedNetworkStorage)) {
            return $this->loadedNetworkStorage;
        }

        if (!$this->settingsTableExists()) {
            return $this->loadedNetworkStorage = [
                'enabled' => false,
                'path' => null,
                'username' => null,
                'password' => null,
            ];
        }

        try {
            $setting = AppSetting::query()
                ->where('key', self::SETTING_NETWORK_STORAGE)
                ->first();

            return $this->loadedNetworkStorage = [
                'enabled' => (bool) ($setting?->network_storage_enabled ?? false),
                'path' => $setting?->network_storage_path,
                'username' => $setting?->network_storage_username,
                'password' => $setting?->network_storage_password,
            ];
        } catch (Throwable) {
            return $this->loadedNetworkStorage = [
                'enabled' => false,
                'path' => null,
                'username' => null,
                'password' => null,
            ];
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('app_settings');
        } catch (Throwable) {
            return false;
        }
    }
}