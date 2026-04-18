<section class="screen-card screen-card--spacious queue-monitor" wire:poll.5s="refreshQueueSnapshot">
    <div class="panel-heading">
        <div>
            <h2 class="panel-title">Job queue monitor</h2>
            <p class="panel-copy">Watch live queue backlog, Docker-managed worker capacity, and recent failures from the admin dashboard. This panel reads the Laravel database queue tables and refreshes automatically every 5 seconds.</p>
        </div>

        <span class="status-pill status-pill--{{ $worker['status_tone'] ?? 'neutral' }}">{{ $worker['status_label'] ?? 'Unavailable' }}</span>
    </div>

    <div class="notice-stack" aria-live="polite">
        @if ($statusMessage)
            <div class="notice notice--{{ $statusTone === 'good' ? 'success' : 'warning' }}">{{ $statusMessage }}</div>
        @endif

        @if ($workerPressureMessage)
            <div class="notice notice--{{ $workerPressureTone }}">{{ $workerPressureMessage }}</div>
        @endif

        @if (! $usesDatabaseQueue)
            <div class="notice notice--warning">The active queue connection uses the <strong>{{ $queueDriver !== '' ? $queueDriver : 'unknown' }}</strong> driver. Pending job inspection in this panel is only available when the Laravel queue driver is set to <strong>database</strong>.</div>
        @elseif (! $jobsTableAvailable)
            <div class="notice notice--warning">The <strong>jobs</strong> table is missing, so pending queue state cannot be inspected yet.</div>
        @endif
    </div>

    <div class="detail-grid queue-monitor__summary-grid">
        <article class="detail-card">
            <span class="detail-card__label">Queue connection</span>
            <strong>{{ $queueConnection }}</strong>
            <span class="queue-monitor__detail-copy">Driver: {{ $queueDriver !== '' ? $queueDriver : 'unknown' }}</span>
        </article>

        <article class="detail-card">
            <span class="detail-card__label">Worker capacity</span>
            <strong>{{ $worker['running_workers'] ?? 0 }} / {{ $worker['desired_workers'] ?? 0 }} running</strong>
            <span class="queue-monitor__detail-copy">Docker targets {{ $worker['minimum_workers'] ?? 1 }} worker container{{ ($worker['minimum_workers'] ?? 1) === 1 ? '' : 's' }} for this stack. Increase CAMERA_RECORDING_WORKER_PROCESSES only when you also add matching worker service replicas.</span>
        </article>

        <article class="detail-card">
            <span class="detail-card__label">Backlog</span>
            <strong>{{ $pendingJobTotal }} pending jobs</strong>
            <span class="queue-monitor__detail-copy">Failed jobs: {{ $failedJobTotal }}.</span>
        </article>

        <article class="detail-card">
            <span class="detail-card__label">Worker targets</span>
            <strong>{{ implode(', ', $worker['queue_names'] ?? []) ?: 'No queue names configured' }}</strong>
            <span class="queue-monitor__detail-copy">{{ $worker['enabled_recording_cameras'] ?? 0 }} recording cameras, {{ $worker['jobs_per_process'] ?? 0 }} jobs per worker target.</span>
        </article>

        <article class="detail-card">
            <span class="detail-card__label">Failed-job policy</span>
            <strong>{{ $autoRetryEnabled && $autoRetryMaxRetries > 0 ? 'Auto retry '.$autoRetryMaxRetries.' time'.($autoRetryMaxRetries === 1 ? '' : 's') : 'Manual retry only' }}</strong>
            <span class="queue-monitor__detail-copy">
                @if ($autoRetryEnabled && $autoRetryMaxRetries > 0)
                    Scheduler retries up to {{ $autoRetryBatchSize }} failed job{{ $autoRetryBatchSize === 1 ? '' : 's' }} per minute after {{ $autoRetryCooldownSeconds }} second{{ $autoRetryCooldownSeconds === 1 ? '' : 's' }}.
                @else
                    Failed jobs stay in the failed jobs table until an operator retries or deletes them.
                @endif
            </span>
        </article>
    </div>

    <div class="queue-monitor__panel-grid">
        <article class="dashboard-panel dashboard-panel--wide queue-monitor__panel-card">
            <div class="panel-heading panel-heading--compact">
                <div>
                    <h3 class="panel-title">Queue summary</h3>
                    <p class="panel-copy">Pending and failed job counts grouped by queue, with the next pending job shown for triage.</p>
                </div>

                <button class="button button--soft queue-monitor__action-button" type="button" wire:click="refreshQueueSnapshot" wire:loading.attr="disabled" wire:target="refreshQueueSnapshot">
                    <span class="button__content">
                        <span class="button__icon-slot" aria-hidden="true">
                            <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 0 1 15.3-6.36L21 8"></path>
                                <path d="M21 3v5h-5"></path>
                                <path d="M21 12a9 9 0 0 1-15.3 6.36L3 16"></path>
                                <path d="M3 21v-5h5"></path>
                            </svg>
                            <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                <path d="M20 12a8 8 0 0 0-8-8"></path>
                            </svg>
                        </span>
                        <span>Refresh now</span>
                    </span>
                </button>
            </div>

            @if ($queueSummary === [])
                <div class="empty-state empty-state--compact">
                    <strong>No queue rows are available.</strong>
                    <p>Once pending or failed jobs exist, each queue will appear here automatically.</p>
                </div>
            @else
                <div class="data-table queue-monitor__table queue-monitor__table--summary">
                    <div class="data-table__head queue-monitor__summary-head">
                        <span>Queue</span>
                        <span>Pending</span>
                        <span>Failed</span>
                        <span>Next job</span>
                        <span>Status</span>
                    </div>

                    @foreach ($queueSummary as $row)
                        <div class="data-table__row queue-monitor__summary-row" wire:key="queue-summary-{{ $row['queue'] }}">
                            <div>
                                <strong>{{ $row['queue'] }}</strong>
                            </div>
                            <div>{{ $row['pending_count'] }}</div>
                            <div>{{ $row['failed_count'] }}</div>
                            <div>
                                @if ($row['next_job_label'])
                                    <strong>{{ $row['next_job_label'] }}</strong>
                                    <p>{{ $row['next_available_label'] }}</p>
                                @else
                                    <span class="queue-monitor__muted">No pending jobs</span>
                                @endif
                            </div>
                            <div>
                                <span class="status-pill status-pill--{{ $row['status_tone'] }}">{{ $row['status_label'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>

        <article class="dashboard-panel dashboard-panel--wide queue-monitor__panel-card">
            <div class="panel-heading panel-heading--compact">
                <div>
                    <h3 class="panel-title">Upcoming jobs</h3>
                    <p class="panel-copy">The next {{ $jobLimit }} queued jobs ordered by the timestamp Laravel will release them to workers.</p>
                </div>
            </div>

            @if ($upcomingJobs === [])
                <div class="empty-state empty-state--compact">
                    <strong>No pending jobs right now.</strong>
                    <p>The queue is clear, or this environment is not using the database queue driver.</p>
                </div>
            @else
                <div class="data-table queue-monitor__table queue-monitor__table--jobs">
                    <div class="data-table__head queue-monitor__jobs-head">
                        <span>Queue</span>
                        <span>Job</span>
                        <span>Attempts</span>
                        <span>Release time</span>
                        <span>State</span>
                    </div>

                    @foreach ($upcomingJobs as $job)
                        <div class="data-table__row queue-monitor__jobs-row" wire:key="queue-job-{{ $job['id'] }}">
                            <div>
                                <strong>{{ $job['queue'] }}</strong>
                            </div>
                            <div>
                                <strong>{{ $job['job_label'] }}</strong>
                                <p>{{ $job['job_class'] }}</p>
                            </div>
                            <div>{{ $job['attempts'] }}</div>
                            <div>{{ $job['available_at_label'] }}</div>
                            <div>
                                <span class="status-pill status-pill--{{ $job['status_tone'] }}">{{ $job['status_label'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>

        <article class="dashboard-panel queue-monitor__panel-card">
            <div class="panel-heading panel-heading--compact">
                <div>
                    <h3 class="panel-title">Failed jobs</h3>
                    <p class="panel-copy">Recent failures are shown here so an admin can retry or delete them without leaving the dashboard.</p>
                </div>

                @if ($failedJobTotal > 0)
                    <button class="button button--soft queue-monitor__action-button" type="button" wire:click="clearFailedJobs" wire:loading.attr="disabled" wire:target="clearFailedJobs">
                        <span class="button__content">
                            <span class="button__icon-slot" aria-hidden="true">
                                <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 7h16"></path>
                                    <path d="M10 11v6"></path>
                                    <path d="M14 11v6"></path>
                                    <path d="M6 7l1 12h10l1-12"></path>
                                    <path d="M9 7V5h6v2"></path>
                                </svg>
                                <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                    <path d="M20 12a8 8 0 0 0-8-8"></path>
                                </svg>
                            </span>
                            <span>Delete all</span>
                        </span>
                    </button>
                @endif
            </div>

            @if ($failedJobs === [])
                <div class="empty-state empty-state--compact">
                    <strong>No failed jobs.</strong>
                    <p>Retry and delete actions appear here only when the failed jobs table contains recent entries.</p>
                </div>
            @else
                <div class="data-table queue-monitor__table queue-monitor__table--failed">
                    <div class="data-table__head queue-monitor__failed-head">
                        <span>Queue</span>
                        <span>Job</span>
                        <span>Failure</span>
                        <span>Retry state</span>
                        <span>Actions</span>
                    </div>

                    @foreach ($failedJobs as $job)
                        <div class="data-table__row queue-monitor__failed-row" wire:key="failed-job-{{ $job['id'] }}">
                            <div>
                                <strong>{{ $job['queue'] }}</strong>
                            </div>
                            <div>
                                <strong>{{ $job['job_label'] }}</strong>
                                <p>{{ $job['job_class'] }}</p>
                                <p class="queue-monitor__muted">Connection: {{ $job['connection'] }}</p>
                            </div>
                            <div>
                                <strong>{{ $job['exception_excerpt'] }}</strong>
                                <p class="queue-monitor__muted">Failed at {{ $job['failed_at_label'] }}</p>

                                @if ($job['exception_trace'] !== '')
                                    <details class="queue-monitor__failure-details">
                                        <summary>Trace preview</summary>
                                        <pre>{{ $job['exception_trace'] }}</pre>
                                    </details>
                                @endif
                            </div>
                            <div>
                                <span class="status-pill status-pill--{{ $job['retry_status_tone'] }}">{{ $job['retry_status_label'] }}</span>
                                <p>{{ $job['retry_summary'] }}</p>

                                @if ($job['next_retry_label'])
                                    <p class="queue-monitor__muted">Next automatic retry after {{ $job['next_retry_label'] }}</p>
                                @endif
                            </div>
                            <div>
                                <div class="queue-monitor__action-stack">
                                    <button class="button button--soft queue-monitor__action-button" type="button" wire:click="retryFailedJob({{ $job['id'] }})" wire:loading.attr="disabled" wire:target="retryFailedJob({{ $job['id'] }})">
                                        <span class="button__content">
                                            <span class="button__icon-slot" aria-hidden="true">
                                                <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 12a9 9 0 0 1 15.3-6.36L21 8"></path>
                                                    <path d="M21 3v5h-5"></path>
                                                </svg>
                                                <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                    <path d="M20 12a8 8 0 0 0-8-8"></path>
                                                </svg>
                                            </span>
                                            <span>Retry</span>
                                        </span>
                                    </button>
                                    <button class="button button--soft queue-monitor__action-button" type="button" wire:click="deleteFailedJob({{ $job['id'] }})" wire:loading.attr="disabled" wire:target="deleteFailedJob({{ $job['id'] }})">
                                        <span class="button__content">
                                            <span class="button__icon-slot" aria-hidden="true">
                                                <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M4 7h16"></path>
                                                    <path d="M10 11v6"></path>
                                                    <path d="M14 11v6"></path>
                                                    <path d="M6 7l1 12h10l1-12"></path>
                                                    <path d="M9 7V5h6v2"></path>
                                                </svg>
                                                <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                    <path d="M20 12a8 8 0 0 0-8-8"></path>
                                                </svg>
                                            </span>
                                            <span>Delete</span>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>
    </div>
</section>