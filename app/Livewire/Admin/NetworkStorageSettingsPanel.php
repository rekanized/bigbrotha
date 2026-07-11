<?php

namespace App\Livewire\Admin;

use App\Services\ApplicationSettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class NetworkStorageSettingsPanel extends Component
{
    public string $networkStorageEnabled = '0';

    public string $networkStoragePath = '';

    public string $networkStorageUsername = '';

    public string $networkStoragePassword = '';

    public bool $hasStoredPassword = false;

    public ?string $statusMessage = null;

    public function mount(ApplicationSettingsService $settings): void
    {
        $state = $settings->networkStorageSettings();

        $this->networkStorageEnabled = $state['enabled'] ? '1' : '0';
        $this->networkStoragePath = $state['path'];
        $this->networkStorageUsername = $state['username'];
        $this->hasStoredPassword = $state['has_password'];
    }

    public function save(ApplicationSettingsService $settings): void
    {
        $this->resetErrorBag();
        $this->statusMessage = null;

        $validated = $this->validate([
            'networkStorageEnabled' => ['required', 'in:0,1'],
            'networkStoragePath' => ['nullable', 'string', 'max:255'],
            'networkStorageUsername' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'networkStoragePassword' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
        ]);

        $enabled = $validated['networkStorageEnabled'] === '1';
        $path = trim((string) ($validated['networkStoragePath'] ?? ''));
        $username = trim((string) ($validated['networkStorageUsername'] ?? ''));
        $password = trim((string) ($validated['networkStoragePassword'] ?? ''));

        if ($enabled) {
            if ($path === '') {
                $this->addError('networkStoragePath', 'Enter an SMB path when network storage is enabled.');
            }

            if ($path !== '' && $settings->parseNetworkStoragePath($path) === null) {
                $this->addError('networkStoragePath', 'Use //host/share/path, \\host\\share\\path, or smb://host/share/path.');
            }

            if ($username === '') {
                $this->addError('networkStorageUsername', 'Enter the SMB username when network storage is enabled.');
            }

            if (!$this->hasStoredPassword && $password === '') {
                $this->addError('networkStoragePassword', 'Enter the SMB password when network storage is enabled.');
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $settings->saveNetworkStorageSettings(
            $enabled,
            $validated['networkStoragePath'],
            $validated['networkStorageUsername'],
            $validated['networkStoragePassword'],
            preserveExistingPassword: $password === '' && $this->hasStoredPassword,
        );

        $state = $settings->networkStorageSettings();
        $this->networkStorageEnabled = $state['enabled'] ? '1' : '0';
        $this->hasStoredPassword = $state['has_password'];
        $this->networkStoragePassword = '';
        $this->statusMessage = $enabled
            ? 'Network storage settings saved. New camera storage requests will use the configured SMB path when the connection is valid.'
            : 'Network storage disabled. Camera storage remains on the local private disk.';
    }

    public function render(): View
    {
        return view('livewire.admin.network-storage-settings-panel');
    }
}
