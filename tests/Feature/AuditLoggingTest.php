<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Models\User;
use App\Services\CameraRecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_created_updated_and_deleted_audit_logs_for_auditable_models(): void
    {
        $camera = $this->createCamera();

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => Carbon::create(2026, 4, 10, 10, 30, 0, 'UTC'),
            'message' => 'Queued by scheduler.',
        ]);

        $createdAudit = AuditLog::query()
            ->where('auditable_type', $recording->getMorphClass())
            ->where('auditable_id', $recording->getKey())
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame(AuditLog::ACTOR_TYPE_SYSTEM, $createdAudit->actor_type);
        $this->assertSame('System', $createdAudit->actor_label);
        $this->assertSame(AuditLog::SOURCE_CONSOLE, $createdAudit->source);
        $this->assertNull($createdAudit->user_id);
        $this->assertSame(CameraRecording::STATUS_QUEUED, $createdAudit->new_values['status'] ?? null);
        $this->assertSame('Queued by scheduler.', $createdAudit->new_values['message'] ?? null);

        $recording->update([
            'message' => 'Queued by stale row recovery.',
        ]);

        $updatedAudit = AuditLog::query()
            ->where('auditable_type', $recording->getMorphClass())
            ->where('auditable_id', $recording->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(['message' => 'Queued by scheduler.'], $updatedAudit->old_values);
        $this->assertSame(['message' => 'Queued by stale row recovery.'], $updatedAudit->new_values);

        $auditableType = $recording->getMorphClass();
        $auditableId = $recording->getKey();

        $recording->delete();

        $deletedAudit = AuditLog::query()
            ->where('auditable_type', $auditableType)
            ->where('auditable_id', $auditableId)
            ->where('event', 'deleted')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_QUEUED, $deletedAudit->old_values['status'] ?? null);
        $this->assertSame('Queued by stale row recovery.', $deletedAudit->old_values['message'] ?? null);
        $this->assertNull($deletedAudit->new_values);
    }

    public function test_it_records_http_actor_metadata_for_recording_state_transitions(): void
    {
        Route::middleware('web')->post('/_test/audit/recordings/{recording}/processing', function (CameraRecording $recording, CameraRecordingService $recordings) {
            $recordings->markRecordingProcessing($recording, 'Worker claimed the queued segment.');

            return response()->noContent();
        });

        $camera = $this->createCamera();
        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => Carbon::create(2026, 4, 10, 11, 0, 0, 'UTC'),
            'message' => 'Queued by scheduler.',
        ]);
        $user = User::factory()->create([
            'name' => 'Audit Operator',
        ]);

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->withHeader('User-Agent', 'Audit Test Agent')
            ->post('/_test/audit/recordings/'.$recording->getKey().'/processing')
            ->assertNoContent();

        $audit = AuditLog::query()
            ->where('auditable_type', $recording->getMorphClass())
            ->where('auditable_id', $recording->getKey())
            ->where('event', 'recording.state_transition')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(AuditLog::ACTOR_TYPE_USER, $audit->actor_type);
        $this->assertSame('Audit Operator', $audit->actor_label);
        $this->assertSame(AuditLog::SOURCE_HTTP, $audit->source);
        $this->assertSame('192.0.2.1', $audit->ip_address);
        $this->assertSame('Audit Test Agent', $audit->user_agent);
        $this->assertSame(['status' => CameraRecording::STATUS_QUEUED, 'message' => 'Queued by scheduler.'], $audit->old_values);
        $this->assertSame(['status' => CameraRecording::STATUS_PROCESSING, 'message' => 'Worker claimed the queued segment.'], $audit->new_values);
        $this->assertSame(CameraRecording::STATUS_QUEUED, $audit->metadata['from'] ?? null);
        $this->assertSame(CameraRecording::STATUS_PROCESSING, $audit->metadata['to'] ?? null);
    }

    public function test_it_does_not_touch_a_recording_when_the_processing_update_is_identical(): void
    {
        $camera = $this->createCamera();
        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_PROCESSING,
            'scheduled_for' => Carbon::create(2026, 4, 10, 11, 0, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 10, 11, 0, 0, 'UTC'),
            'message' => 'Worker claimed the queued segment.',
        ]);

        $originalUpdatedAt = $recording->updated_at;

        $this->travel(5)->seconds();

        try {
            app(CameraRecordingService::class)->markRecordingProcessing($recording, 'Worker claimed the queued segment.');
        } finally {
            $this->travelBack();
        }

        $recording->refresh();

        $this->assertTrue($recording->updated_at?->equalTo($originalUpdatedAt));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_it_can_suppress_a_single_automatic_heartbeat_audit_without_suppressing_later_updates(): void
    {
        $camera = $this->createCamera();
        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_PROCESSING,
            'scheduled_for' => Carbon::create(2026, 4, 10, 11, 0, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 10, 11, 0, 0, 'UTC'),
            'message' => 'Motion event started.',
        ]);

        $recording->suppressNextAuditEvent('updated')->update([
            'message' => 'Motion heartbeat refreshed.',
        ]);

        $this->assertSame(0, $recording->auditLogs()->where('event', 'updated')->count());

        $recording->update([
            'message' => 'Operator-significant update.',
        ]);

        $this->assertSame(1, $recording->auditLogs()->where('event', 'updated')->count());
    }

    private function createCamera(): Camera
    {
        return Camera::query()->create([
            'name' => 'Audit Camera',
            'local_ip' => '192.0.2.210',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 7,
        ]);
    }
}
