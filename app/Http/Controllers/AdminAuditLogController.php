<?php

namespace App\Http\Controllers;

use App\Models\AllowedLoginEmail;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Models\LiveWall;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Support\Audit\AuditLogPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'subject_type' => ['nullable', 'string', 'max:255'],
            'actor_type' => ['nullable', 'in:user,system'],
            'source' => ['nullable', 'in:http,queue,console,system'],
            'event' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:200'],
            'camera_id' => ['nullable', 'integer', 'min:1'],
            'period' => ['nullable', 'in:all,day,week,month'],
            'activity' => ['nullable', 'in:all,users,recordings,failures'],
        ]);
        $filters = array_merge(array_fill_keys(['subject_type', 'actor_type', 'source', 'event', 'search', 'camera_id'], ''), [
            'period' => 'all',
            'activity' => 'all',
        ], array_map(static fn ($value): string => trim((string) $value), array_filter($validated, static fn ($value): bool => $value !== null)));

        $query = AuditLog::query()
            ->with(['auditable', 'user'])
            ->when($filters['subject_type'] !== '', fn (Builder $query) => $query->where('auditable_type', $filters['subject_type']))
            ->when($filters['actor_type'] !== '', fn (Builder $query) => $query->where('actor_type', $filters['actor_type']))
            ->when($filters['source'] !== '', fn (Builder $query) => $query->where('source', $filters['source']))
            ->when($filters['event'] !== '', fn (Builder $query) => $query->where('event', $filters['event']))
            ->when($filters['activity'] === 'users', fn (Builder $query) => $query->where('actor_type', AuditLog::ACTOR_TYPE_USER))
            ->when(in_array($filters['activity'], ['recordings', 'failures'], true), fn (Builder $query) => $query->where('auditable_type', CameraRecording::class))
            ->when($filters['activity'] === 'failures', fn (Builder $query) => $query->where(function (Builder $query): void {
                $query->where('new_values->status', 'failed')->orWhere('metadata->to', 'failed');
            }))
            ->when($filters['period'] !== 'all', fn (Builder $query) => $query->where('created_at', '>=', now()->utc()->subDays(match ($filters['period']) {
                'day' => 1,
                'week' => 7,
                default => 30,
            })))
            ->when($filters['camera_id'] !== '', function (Builder $query) use ($filters): void {
                $id = (int) $filters['camera_id'];
                $query->where(function (Builder $query) use ($id): void {
                    $query->where(fn (Builder $query) => $query->where('auditable_type', Camera::class)->where('auditable_id', $id))
                        ->orWhere(function (Builder $query) use ($id): void {
                            $query->where('auditable_type', CameraRecording::class)->where(function (Builder $query) use ($id): void {
                                $query->where('metadata->camera_id', $id)
                                    ->orWhere('new_values->camera_id', $id)
                                    ->orWhere('old_values->camera_id', $id)
                                    ->orWhereIn('auditable_id', CameraRecording::query()->select('id')->where('camera_id', $id));
                            });
                        });
                });
            })
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = '%'.mb_strtolower($filters['search']).'%';
                $query->where(function (Builder $query) use ($search, $filters): void {
                    foreach (['event', 'actor_label', 'actor_type', 'source', 'auditable_type', 'ip_address', 'user_agent', 'old_values->name', 'new_values->name', 'old_values->email', 'new_values->email', 'old_values->key', 'new_values->key', 'new_values->message', 'new_values->status', 'metadata->reason'] as $column) {
                        $query->orWhereLike($column, $search);
                    }
                    foreach ([User::class => ['name', 'email'], AllowedLoginEmail::class => ['email'], AppSetting::class => ['key'], LiveWall::class => ['name']] as $type => $columns) {
                        $query->orWhereHasMorph('auditable', [$type], function (Builder $query) use ($columns, $search): void {
                            $query->where(function (Builder $query) use ($columns, $search): void {
                                foreach ($columns as $column) {
                                    $query->orWhereLike($column, $search);
                                }
                            });
                        });
                    }
                    $settingKeys = array_keys(array_filter(AuditLogPresenter::SETTING_LABELS, static fn (string $label): bool => mb_stripos($label, $filters['search']) !== false));
                    if ($settingKeys !== []) {
                        $query->orWhere(function (Builder $query) use ($settingKeys): void {
                            $query->where('auditable_type', AppSetting::class)->where(function (Builder $query) use ($settingKeys): void {
                                $query->whereIn('old_values->key', $settingKeys)->orWhereIn('new_values->key', $settingKeys)
                                    ->orWhereHasMorph('auditable', [AppSetting::class], fn (Builder $query) => $query->whereIn('key', $settingKeys));
                            });
                        });
                    }
                    $query->orWhere(function (Builder $query) use ($search): void {
                        $query->where('auditable_type', Camera::class)->whereIn('auditable_id', Camera::query()->select('id')->whereLike('name', $search));
                    })->orWhere(function (Builder $query) use ($search): void {
                        $ids = Camera::query()->whereLike('name', $search)->pluck('id')->all();
                        $query->where('auditable_type', CameraRecording::class)->where(function (Builder $query) use ($ids): void {
                            $query->whereIn('metadata->camera_id', $ids)
                                ->orWhereIn('new_values->camera_id', $ids)
                                ->orWhereIn('old_values->camera_id', $ids)
                                ->orWhereIn('auditable_id', CameraRecording::query()->select('id')->whereIn('camera_id', $ids));
                        });
                    });
                    if (ctype_digit($filters['search'])) {
                        $query->orWhere('auditable_id', (int) $filters['search'])->orWhere('user_id', (int) $filters['search']);
                    }
                });
            });

        $auditLogs = $query->latest('created_at')->latest('id')->paginate(25)->withQueryString();
        $cameraOptions = Camera::query()->orderBy('name')->pluck('name', 'id');

        return view('admin.audit-logs', [
            'auditLogs' => $auditLogs,
            'entries' => $auditLogs->getCollection()->map(fn (AuditLog $audit) => new AuditLogPresenter($audit, $cameraOptions->all())),
            'filters' => $filters,
            'cameraOptions' => $cameraOptions,
            'refreshedAt' => now()->utc(),
            'timezone' => app(ApplicationSettingsService::class)->appTimezone(),
            'retentionDays' => app(ApplicationSettingsService::class)->auditRetentionDays(),
            'actorTypeOptions' => AuditLog::actorTypeLabels(),
            'sourceOptions' => [
                AuditLog::SOURCE_HTTP => 'Web app',
                AuditLog::SOURCE_QUEUE => 'Background worker',
                AuditLog::SOURCE_CONSOLE => 'Scheduled task or command',
                AuditLog::SOURCE_SYSTEM => 'System process',
            ],
            'eventOptions' => AuditLog::query()->select('event')->distinct()->orderBy('event')->pluck('event')
                ->mapWithKeys(static fn (string $event): array => [$event => AuditLog::eventLabelFor($event)])->all(),
            'subjectTypeOptions' => AuditLog::query()->select('auditable_type')->distinct()->whereNotNull('auditable_type')->orderBy('auditable_type')->pluck('auditable_type')
                ->mapWithKeys(static fn (string $type): array => [$type => AuditLog::subjectTypeLabelFor($type)])->all(),
        ]);
    }
}
