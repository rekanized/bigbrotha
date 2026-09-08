<header class="global-header" data-global-header>
    <div class="global-header__inner">
        <a class="global-header__brand" href="{{ route('camera-fleet.index') }}" wire:navigate aria-label="{{ config('app.name', 'Bigbrotha') }} camera fleet">
            <span class="global-header__mark" aria-hidden="true">
                <img src="{{ $assetBase }}/img/bigbrotha-logo.svg?v={{ filemtime(public_path('img/bigbrotha-logo.svg')) }}" width="64" height="64" alt="">
            </span>
            <span class="global-header__brand-copy">
                <strong>{{ config('app.name', 'Bigbrotha') }}</strong>
                <small>{{ $pageTitle !== '' ? $pageTitle : 'Operator workspace' }}</small>
            </span>
        </a>

        <div class="global-header__desktop-navigation">
            @include('layouts.partials.primary-navigation', [
                'navigationIdSuffix' => 'desktop',
                'navigationAriaLabel' => 'Primary navigation',
            ])
        </div>

        <div class="global-header__account global-header__account--desktop">
            <div class="global-header__operator" aria-label="Current operator">
                <span class="global-header__avatar">{{ $currentUserInitials }}</span>
                <span class="global-header__operator-name">{{ $currentUser->name }}</span>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="global-header__sign-out" type="submit">Sign out</button>
            </form>
        </div>

        <details class="global-header__drawer" data-global-navigation>
            <summary class="global-header__drawer-toggle" aria-label="Open primary navigation">
                <span class="global-header__drawer-toggle-lines" aria-hidden="true">
                    <span></span><span></span><span></span>
                </span>
                <span class="global-header__drawer-toggle-label">Menu</span>
            </summary>

            <div class="global-header__drawer-panel">
                @include('layouts.partials.primary-navigation', [
                    'navigationIdSuffix' => 'mobile',
                    'navigationAriaLabel' => 'Mobile primary navigation',
                    'expandActiveGroup' => true,
                ])

                <div class="global-header__account global-header__account--mobile">
                    <div class="global-header__operator" aria-label="Current operator">
                        <span class="global-header__avatar">{{ $currentUserInitials }}</span>
                        <span class="global-header__operator-name">{{ $currentUser->name }}</span>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="global-header__sign-out" type="submit">Sign out</button>
                    </form>
                </div>
            </div>
        </details>
    </div>
</header>
