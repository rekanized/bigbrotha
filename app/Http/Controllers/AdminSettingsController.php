<?php

namespace App\Http\Controllers;

use App\Services\ApplicationSettingsService;
use App\Services\RecorderStatusService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSettingsController extends Controller
{
    public function index(ApplicationSettingsService $settings, RecorderStatusService $recorderStatusService): View
    {
        return view('admin.settings', [
            'timezoneOptions' => $settings->timezoneOptions(),
            'currentTimezone' => $settings->appTimezone(),
            'currentTimeLabel' => $settings->formatDateTime(now(), 'Y-m-d H:i:s') ?? 'Unavailable',
            'serverTimeLabel' => now()->format('Y-m-d H:i:s T'),
            'recorderStatus' => $recorderStatusService->snapshot(),
        ]);
    }

    public function update(Request $request, ApplicationSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'app_timezone' => ['required', 'string', Rule::in(array_keys($settings->timezoneOptions()))],
        ]);

        $settings->saveAppTimezone($validated['app_timezone']);

        return redirect()
            ->route('admin.settings.index')
            ->with('status', 'Admin settings updated. Operator-facing timestamps now use '.$validated['app_timezone'].'.');
    }
}