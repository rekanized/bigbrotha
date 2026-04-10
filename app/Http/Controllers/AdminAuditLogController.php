<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'subject_type' => trim((string) $request->string('subject_type')),
            'actor_type' => trim((string) $request->string('actor_type')),
            'source' => trim((string) $request->string('source')),
            'event' => trim((string) $request->string('event')),
            'search' => trim((string) $request->string('search')),
        ];

        $auditLogs = AuditLog::query()
            ->with(['auditable', 'user'])
            ->when($filters['subject_type'] !== '', function (Builder $query) use ($filters): void {
                $query->where('auditable_type', $filters['subject_type']);
            })
            ->when($filters['actor_type'] !== '', function (Builder $query) use ($filters): void {
                $query->where('actor_type', $filters['actor_type']);
            })
            ->when($filters['source'] !== '', function (Builder $query) use ($filters): void {
                $query->where('source', $filters['source']);
            })
            ->when($filters['event'] !== '', function (Builder $query) use ($filters): void {
                $query->where('event', $filters['event']);
            })
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('event', 'like', '%'.$search.'%')
                        ->orWhere('actor_label', 'like', '%'.$search.'%')
                        ->orWhere('actor_type', 'like', '%'.$search.'%')
                        ->orWhere('source', 'like', '%'.$search.'%')
                        ->orWhere('auditable_type', 'like', '%'.$search.'%')
                        ->orWhere('ip_address', 'like', '%'.$search.'%')
                        ->orWhere('user_agent', 'like', '%'.$search.'%');

                    if (ctype_digit($search)) {
                        $searchQuery
                            ->orWhere('auditable_id', (int) $search)
                            ->orWhere('user_id', (int) $search);
                    }
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.audit-logs', [
            'auditLogs' => $auditLogs,
            'filters' => $filters,
            'actorTypeOptions' => AuditLog::actorTypeLabels(),
            'sourceOptions' => AuditLog::sourceLabels(),
            'eventOptions' => AuditLog::query()
                ->select('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event')
                ->mapWithKeys(static fn (string $event): array => [$event => AuditLog::eventLabelFor($event)])
                ->all(),
            'subjectTypeOptions' => AuditLog::query()
                ->select('auditable_type')
                ->distinct()
                ->whereNotNull('auditable_type')
                ->orderBy('auditable_type')
                ->pluck('auditable_type')
                ->mapWithKeys(static fn (string $type): array => [$type => AuditLog::subjectTypeLabelFor($type)])
                ->all(),
        ]);
    }
}