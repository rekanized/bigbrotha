<aside class="desktop-sidebar" aria-label="Workspace sidebar">
    <a class="global-header__brand desktop-sidebar__brand" href="{{ route('camera-fleet.index') }}" wire:navigate aria-label="{{ config('app.name', 'Bigbrotha') }} camera fleet">
        <span class="global-header__mark" aria-hidden="true">
            <img src="{{ $assetBase }}/img/bigbrotha-logo.svg?v={{ filemtime(public_path('img/bigbrotha-logo.svg')) }}" width="64" height="64" alt="">
        </span>
        <span class="global-header__brand-copy">
            <strong>{{ config('app.name', 'Bigbrotha') }}</strong>
            <small>Camera workspace</small>
        </span>
    </a>

    <div class="desktop-sidebar__navigation">
        @include('layouts.partials.primary-navigation', [
            'navigationIdSuffix' => 'desktop',
            'navigationAriaLabel' => 'Primary navigation',
            'expandActiveGroup' => true,
        ])
    </div>

    <div class="desktop-sidebar__account">
        <div class="global-header__operator">
            <span class="global-header__avatar">{{ $currentUserInitials }}</span>
            <span class="desktop-sidebar__operator-copy">
                <strong>{{ $currentUser->name }}</strong>
                <small>{{ $currentUser->isAdmin() ? 'Administrator' : 'Operator' }}</small>
            </span>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="global-header__sign-out" type="submit">Sign out</button>
        </form>
    </div>
</aside>
