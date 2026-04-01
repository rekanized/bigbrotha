@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | Operations Overview')

@section('body_class', 'page-welcome')

@section('content')
    <section class="page-card split-panel">
        <div class="split-panel__content">
            <div class="stack-lg">
                <div class="stack-md welcome-grid">
                    <span class="eyebrow">Composer only</span>
                    <h1 class="hero-title">Camera operations, without a frontend build chain.</h1>
                    <p class="hero-lead">
                        This Laravel baseline is ready for ONVIF and RTSP camera management, live monitoring,
                        recordings, and operator-facing layouts using Blade and directly linked public assets.
                    </p>
                </div>

                <div class="actions-row">
                    <a class="button button--primary" href="https://laravel.com/docs" target="_blank" rel="noreferrer">Laravel documentation</a>
                    <a class="button button--soft" href="{{ url('/up') }}">Application health</a>
                </div>

                <div class="welcome-metrics">
                    <div class="welcome-metric">
                        <p class="welcome-metric__label">Frontend model</p>
                        <p class="welcome-metric__value">Blade + public CSS</p>
                    </div>
                    <div class="welcome-metric">
                        <p class="welcome-metric__label">Primary use case</p>
                        <p class="welcome-metric__value">Live camera monitoring</p>
                    </div>
                </div>

                <div class="meta-row">
                    <span>Laravel {{ app()->version() }}</span>
                    <span>No npm, no Vite, no Tailwind.</span>
                </div>
            </div>
        </div>

        <aside class="split-panel__aside">
            <div class="stack-md">
                <div class="info-card">
                    <p class="info-card__label">Base layout</p>
                    <p class="info-card__value">Shared page chrome now lives in a reusable Blade layout for future operator screens.</p>
                </div>

                <div class="info-card">
                    <p class="info-card__label">CSS structure</p>
                    <p class="info-card__value">Styles are split into public base, layout, component, and page files with direct browser loading.</p>
                </div>

                <div class="info-card">
                    <p class="info-card__label">Next screens</p>
                    <p class="info-card__value">Feed grids, camera inventory, recording controls, and playback timelines can now sit on the same foundation.</p>
                </div>
            </div>
        </aside>
    </section>
@endsection
