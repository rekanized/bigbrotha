<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Collection;
use Throwable;

class ApplicationSettingStore
{
    /**
     * @var Collection<string, AppSetting>|null
     */
    private ?Collection $settings = null;

    private ?bool $available = null;

    /**
     * @return Collection<string, AppSetting>
     */
    public function all(): Collection
    {
        if ($this->settings instanceof Collection && $this->available !== false) {
            return $this->settings;
        }

        try {
            $this->settings = AppSetting::query()
                ->get()
                ->keyBy(static fn (AppSetting $setting): string => (string) $setting->getRawOriginal('key'));
            $this->available = true;
        } catch (Throwable) {
            $this->settings = collect();
            $this->available = false;
        }

        return $this->settings;
    }

    public function find(string $key): ?AppSetting
    {
        $setting = $this->all()->get($key);

        return $setting instanceof AppSetting ? $setting : null;
    }

    /**
     * @return array<string, string>
     */
    public function plainValues(): array
    {
        return $this->all()
            ->mapWithKeys(static fn (AppSetting $setting, string $key): array => [
                $key => is_string($setting->getRawOriginal('value'))
                    ? $setting->getRawOriginal('value')
                    : '',
            ])
            ->all();
    }

    public function available(): bool
    {
        $this->all();

        return $this->available === true;
    }

    public function forget(): void
    {
        $this->settings = null;
        $this->available = null;
    }
}
