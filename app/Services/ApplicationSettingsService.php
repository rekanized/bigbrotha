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

    /**
     * @var array<string, string>|null
     */
    private ?array $loadedSettings = null;

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
                ->pluck('value', 'key')
                ->map(fn (mixed $value): string => (string) $value)
                ->all();
        } catch (Throwable) {
            return $this->loadedSettings = [];
        }
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