@extends('layouts.app')

@php
    $summaryCards = [
        ['value' => '24', 'label' => 'Active cameras', 'detail' => 'Three sites feeding the wall', 'icon' => 'AC', 'tone' => 'blue'],
        ['value' => '18', 'label' => 'RTSP streams locked', 'detail' => 'Low-latency transport on TCP', 'icon' => 'RT', 'tone' => 'violet'],
        ['value' => '07', 'label' => 'Recording jobs queued', 'detail' => 'Edge archive handoff in progress', 'icon' => 'RQ', 'tone' => 'amber'],
        ['value' => '02', 'label' => 'Open alerts', 'detail' => 'One packet-loss spike, one camera reboot', 'icon' => 'AL', 'tone' => 'green'],
    ];

    $zoneCoverage = [
        ['name' => 'North Gate', 'count' => '6 cameras', 'status' => 'Healthy', 'tone' => 'good'],
        ['name' => 'Loading Bay', 'count' => '5 cameras', 'status' => '1 warning', 'tone' => 'warn'],
        ['name' => 'Warehouse Floor', 'count' => '8 cameras', 'status' => 'Healthy', 'tone' => 'good'],
        ['name' => 'Parking Grid', 'count' => '5 cameras', 'status' => '1 offline', 'tone' => 'alert'],
    ];

    $incidentQueue = [
        ['time' => '08:14', 'title' => 'Recorder backlog increased', 'detail' => 'Archive node 02 is twelve minutes behind live ingest.'],
        ['time' => '07:52', 'title' => 'Camera P3 restarted', 'detail' => 'North Gate PTZ rejoined after a short power cycle.'],
        ['time' => '07:31', 'title' => 'Night preset completed', 'detail' => 'Warehouse domes switched to low-light profile set B.'],
    ];
@endphp

@section('title', config('app.name', 'Bigbrotha').' | Operations Dashboard')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Camera operations')

@section('page_title', 'Operations dashboard')

@section('page_lead', 'Keep an eye on live visibility, recorder throughput, and alert pressure before operators lose situational awareness.')

@section('page_actions')
    <a class="button button--soft" href="{{ url('/up') }}">System health</a>
    <button class="button button--primary" type="button">Launch live wall</button>
@endsection

@section('content')
    <section class="dashboard-frame page-card">
        <div class="dashboard-frame__header">
            <div class="dashboard-frame__headline">
                <span class="eyebrow">Live overview</span>
                <h2 class="dashboard-frame__title">Control room readiness</h2>
            </div>

            <div class="dashboard-frame__meta">
                <span class="status-pill status-pill--good">27 feeds healthy</span>
                <span class="status-pill">Last sync 08:12 UTC</span>
            </div>
        </div>

        <div class="dashboard-stats">
            @foreach ($summaryCards as $card)
                <article class="metric-card metric-card--{{ $card['tone'] }}">
                    <div class="metric-card__icon">{{ $card['icon'] }}</div>

                    <div class="metric-card__body">
                        <p class="metric-card__value">{{ $card['value'] }}</p>
                        <p class="metric-card__label">{{ $card['label'] }}</p>
                        <p class="metric-card__detail">{{ $card['detail'] }}</p>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="dashboard-panels">
            <article class="dashboard-panel dashboard-panel--wide">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Feed stability</h3>
                        <p class="panel-copy">Latency stayed inside the expected window until one short spike during the latest recorder rollover.</p>
                    </div>

                    <span class="panel-stat">98.4% uptime</span>
                </div>

                <svg class="line-chart" viewBox="0 0 640 220" aria-hidden="true" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="uptimeFill" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="rgba(49, 100, 244, 0.24)" />
                            <stop offset="100%" stop-color="rgba(49, 100, 244, 0.02)" />
                        </linearGradient>
                    </defs>

                    <g class="line-chart__grid">
                        <line x1="0" y1="24" x2="640" y2="24" />
                        <line x1="0" y1="84" x2="640" y2="84" />
                        <line x1="0" y1="144" x2="640" y2="144" />
                        <line x1="0" y1="204" x2="640" y2="204" />
                    </g>

                    <path class="line-chart__fill" d="M0 198 L64 198 L128 197 L192 196 L256 194 L320 188 L384 175 L448 166 L512 132 L576 116 L640 38 L640 220 L0 220 Z" />
                    <path class="line-chart__stroke" d="M0 198 L64 198 L128 197 L192 196 L256 194 L320 188 L384 175 L448 166 L512 132 L576 116 L640 38" />
                    <circle class="line-chart__point" cx="640" cy="38" r="7" />
                </svg>

                <div class="chart-footer">
                    <span>Mon 02</span>
                    <span>Mon 09</span>
                    <span>Mon 16</span>
                    <span>Mon 23</span>
                    <span>Today</span>
                </div>
            </article>

            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Recording pipeline</h3>
                        <p class="panel-copy">Background jobs are moving cleanly from ingest to archive with one queue needing attention.</p>
                    </div>

                    <span class="panel-stat">7 active jobs</span>
                </div>

                <div class="queue-bars">
                    <div class="queue-row">
                        <div class="queue-row__meta">
                            <strong>Clip segmentation</strong>
                            <span>92%</span>
                        </div>
                        <div class="queue-row__track"><span style="width: 92%"></span></div>
                    </div>

                    <div class="queue-row">
                        <div class="queue-row__meta">
                            <strong>Metadata indexing</strong>
                            <span>74%</span>
                        </div>
                        <div class="queue-row__track"><span style="width: 74%"></span></div>
                    </div>

                    <div class="queue-row">
                        <div class="queue-row__meta">
                            <strong>Cold archive handoff</strong>
                            <span>61%</span>
                        </div>
                        <div class="queue-row__track"><span style="width: 61%"></span></div>
                    </div>

                    <div class="queue-row">
                        <div class="queue-row__meta">
                            <strong>Motion digest export</strong>
                            <span>39%</span>
                        </div>
                        <div class="queue-row__track"><span style="width: 39%"></span></div>
                    </div>
                </div>
            </article>

            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Alert pressure</h3>
                        <p class="panel-copy">The last seven days show a steady control room, with two events requiring follow-up this morning.</p>
                    </div>

                    <span class="panel-stat">2 open</span>
                </div>

                <div class="alert-bars" aria-hidden="true">
                    <span class="alert-bars__column" style="--height: 18%"></span>
                    <span class="alert-bars__column" style="--height: 28%"></span>
                    <span class="alert-bars__column" style="--height: 24%"></span>
                    <span class="alert-bars__column" style="--height: 22%"></span>
                    <span class="alert-bars__column" style="--height: 36%"></span>
                    <span class="alert-bars__column" style="--height: 26%"></span>
                    <span class="alert-bars__column alert-bars__column--active" style="--height: 78%"></span>
                </div>

                <div class="chart-footer">
                    <span>Tue</span>
                    <span>Wed</span>
                    <span>Thu</span>
                    <span>Fri</span>
                    <span>Sat</span>
                    <span>Sun</span>
                    <span>Mon</span>
                </div>
            </article>
        </div>

        <div class="dashboard-secondary">
            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Zone coverage</h3>
                        <p class="panel-copy">Quick view of where camera availability is strongest and where the next dispatch should land.</p>
                    </div>
                </div>

                <div class="zone-list">
                    @foreach ($zoneCoverage as $zone)
                        <div class="zone-row">
                            <div>
                                <strong>{{ $zone['name'] }}</strong>
                                <p>{{ $zone['count'] }}</p>
                            </div>

                            <span class="status-pill status-pill--{{ $zone['tone'] }}">{{ $zone['status'] }}</span>
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Incident queue</h3>
                        <p class="panel-copy">Recent operator-visible events that should flow into playback, audit, or field follow-up.</p>
                    </div>
                </div>

                <div class="incident-list">
                    @foreach ($incidentQueue as $incident)
                        <article class="incident-item">
                            <span class="incident-item__time">{{ $incident['time'] }}</span>

                            <div class="incident-item__body">
                                <strong>{{ $incident['title'] }}</strong>
                                <p>{{ $incident['detail'] }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </article>
        </div>
    </section>
@endsection
