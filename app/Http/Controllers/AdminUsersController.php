<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminUsersController extends Controller
{
    public function index(): View
    {
        return view('admin.users', [
            'users' => User::query()
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->orderBy('email')
                ->get(),
        ]);
    }

    public function updateAdminRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        $shouldBeAdmin = (bool) $validated['is_admin'];

        if (!$shouldBeAdmin && $user->isAdmin() && User::query()->where('is_admin', true)->count() <= 1) {
            return redirect()
                ->route('admin.users.index')
                ->with('status_error', 'At least one admin must remain assigned. Promote another operator before removing the last admin.');
        }

        if ($user->isAdmin() === $shouldBeAdmin) {
            return redirect()
                ->route('admin.users.index')
                ->with('status', 'No admin role change was needed for '.$user->name.'.');
        }

        $user->forceFill([
            'is_admin' => $shouldBeAdmin,
        ])->save();

        return redirect()
            ->route('admin.users.index')
            ->with('status', ($shouldBeAdmin ? 'Promoted ' : 'Removed admin access for ').$user->name.'.');
    }
}