<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminJobQueue;
use App\Livewire\Admin\NetworkStorageSettingsPanel;
use App\Livewire\Recordings\TimelineReview;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Models\AllowedLoginEmail;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\CameraStorageService;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_users_are_redirected_away_from_admin_settings(): void
    {
        $this->get(route('admin.settings.index'))
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_users_cannot_open_admin_settings(): void
    {
        User::factory()->admin()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_admin_can_update_the_operator_display_timezone(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'app_timezone' => 'Europe/Amsterdam',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_timezone',
            'value' => 'Europe/Amsterdam',
        ]);
    }

    public function test_admin_can_open_the_current_users_page_and_see_navigation_links(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin Operator',
            'email' => 'admin@example.com',
            'google_id' => 'google-admin',
        ]);

        User::factory()->create([
            'name' => 'Standard Operator',
            'email' => 'operator@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Operator access')
            ->assertSee('Pending Google sign-in emails')
            ->assertSee('Admin Operator')
            ->assertSee('Standard Operator')
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.settings.index'), false)
            ->assertSee('Application settings');
    }

    public function test_first_authenticated_operator_sees_admin_navigation_when_no_admin_exists_yet(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('camera-fleet.index'))
            ->assertOk()
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.settings.index'), false)
            ->assertSee('Admin');
    }

    public function test_non_admin_operator_does_not_see_admin_navigation_when_an_admin_already_exists(): void
    {
        User::factory()->admin()->create();
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('camera-fleet.index'))
            ->assertOk()
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.settings.index'), false);
    }

    public function test_authenticated_root_redirects_to_camera_fleet(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get('/')
            ->assertRedirect(route('camera-fleet.index'));
    }

    public function test_admin_settings_page_shows_recorder_runtime_information(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Job queue monitor')
            ->assertSee('Recorder runtime')
            ->assertSee('ffmpeg binary')
            ->assertSee('Temp workspace');
    }

    public function test_admin_job_queue_component_lists_pending_and_failed_jobs(): void
    {
        config()->set('queue.default', 'database');
        config()->set('recording.worker.queue', 'recordings,default,review-assets');
        config()->set('queue.failed.auto_retry.enabled', true);
        config()->set('queue.failed.auto_retry.max_retries', 2);
        config()->set('queue.failed.auto_retry.batch_size', 5);
        config()->set('queue.failed.auto_retry.cooldown_seconds', 60);

        DB::table('jobs')->insert([
            [
                'queue' => 'review-assets',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\GenerateRecordingReviewAssetsJob',
                ], JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->addSecond()->timestamp,
                'created_at' => now()->timestamp,
            ],
            [
                'queue' => 'default',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                ], JSON_THROW_ON_ERROR),
                'attempts' => 1,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => '9e339aa3-8f8f-4f6c-bf2f-3b1e8fd22222',
            'connection' => 'database',
            'queue' => 'recordings',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ProcessCameraRecordingJob',
                'bigbrotha_failed_job_retry' => [
                    'total_retries' => 1,
                    'auto_retries' => 1,
                    'manual_retries' => 0,
                    'last_retry_type' => 'auto',
                    'last_retry_at' => now()->subMinute()->toIso8601String(),
                ],
            ], JSON_THROW_ON_ERROR),
            'exception' => "RuntimeException: queue test failed\n#0 /tmp/worker.php(10): test()",
            'failed_at' => now(),
        ]);

        Livewire::test(AdminJobQueue::class)
            ->assertSee('Job queue monitor')
            ->assertSee('recordings')
            ->assertSee('default')
            ->assertSee('review-assets')
            ->assertSee('GenerateRecordingReviewAssetsJob')
            ->assertSee('RefreshCameraPreviewJob')
            ->assertSee('ProcessCameraRecordingJob')
            ->assertSee('RuntimeException: queue test failed')
            ->assertSee('Pending retry')
            ->assertSee('Requeued 1 time')
            ->assertSee('Auto retry 2 times');
    }

    public function test_failed_job_auto_retry_command_requeues_eligible_jobs_and_respects_the_limit(): void
    {
        config()->set('queue.default', 'database');
        config()->set('queue.failed.auto_retry.enabled', true);
        config()->set('queue.failed.auto_retry.max_retries', 2);
        config()->set('queue.failed.auto_retry.batch_size', 5);
        config()->set('queue.failed.auto_retry.cooldown_seconds', 60);

        $retryableFailedJobId = DB::table('failed_jobs')->insertGetId([
            'uuid' => '9f0a48aa-3fa1-47f7-b39d-1f8a3a5b4401',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'commandName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                    'command' => 'serialized-command-placeholder',
                ],
                'bigbrotha_failed_job_retry' => [
                    'total_retries' => 1,
                    'auto_retries' => 1,
                    'manual_retries' => 0,
                    'last_retry_type' => 'auto',
                    'last_retry_at' => now()->subMinutes(2)->toIso8601String(),
                ],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: retry me',
            'failed_at' => now()->subMinutes(2),
        ]);

        $limitReachedFailedJobId = DB::table('failed_jobs')->insertGetId([
            'uuid' => '41b6ec57-8e69-4331-8651-2b67d5294501',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                'bigbrotha_failed_job_retry' => [
                    'total_retries' => 2,
                    'auto_retries' => 2,
                    'manual_retries' => 0,
                    'last_retry_type' => 'auto',
                    'last_retry_at' => now()->subMinutes(3)->toIso8601String(),
                ],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: stop retrying',
            'failed_at' => now()->subMinutes(3),
        ]);

        $exitCode = Artisan::call('queue:retry-failed-auto');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('failed_jobs', [
            'id' => $retryableFailedJobId,
        ]);
        $this->assertDatabaseHas('failed_jobs', [
            'id' => $limitReachedFailedJobId,
        ]);
        $this->assertDatabaseHas('jobs', [
            'queue' => 'default',
        ]);

        $queuedPayload = DB::table('jobs')->where('queue', 'default')->value('payload');
        $decodedPayload = json_decode((string) $queuedPayload, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, data_get($decodedPayload, 'bigbrotha_failed_job_retry.total_retries'));
        $this->assertSame(2, data_get($decodedPayload, 'bigbrotha_failed_job_retry.auto_retries'));
    }

    public function test_failed_job_auto_retry_command_does_not_let_limit_reached_rows_block_newer_eligible_retries(): void
    {
        config()->set('queue.default', 'database');
        config()->set('queue.failed.auto_retry.enabled', true);
        config()->set('queue.failed.auto_retry.max_retries', 2);
        config()->set('queue.failed.auto_retry.batch_size', 1);
        config()->set('queue.failed.auto_retry.cooldown_seconds', 60);

        DB::table('failed_jobs')->insert([
            'uuid' => 'd0b66e8b-ae0b-4c6a-b5da-f5c96f86ba01',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                'bigbrotha_failed_job_retry' => [
                    'total_retries' => 2,
                    'auto_retries' => 2,
                    'manual_retries' => 0,
                    'last_retry_type' => 'auto',
                    'last_retry_at' => now()->subMinutes(5)->toIso8601String(),
                ],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: already exhausted',
            'failed_at' => now()->subMinutes(5),
        ]);

        $retryableFailedJobId = DB::table('failed_jobs')->insertGetId([
            'uuid' => '892e49a1-ec8e-4a64-9db9-f7fd13e6c112',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'commandName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                    'command' => 'serialized-command-placeholder',
                ],
                'bigbrotha_failed_job_retry' => [
                    'total_retries' => 0,
                    'auto_retries' => 0,
                    'manual_retries' => 0,
                    'last_retry_type' => null,
                    'last_retry_at' => null,
                ],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: retry the newer row',
            'failed_at' => now()->subMinutes(4),
        ]);

        $exitCode = Artisan::call('queue:retry-failed-auto');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('failed_jobs', [
            'uuid' => 'd0b66e8b-ae0b-4c6a-b5da-f5c96f86ba01',
        ]);
        $this->assertDatabaseMissing('failed_jobs', [
            'id' => $retryableFailedJobId,
        ]);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_admin_job_queue_component_can_retry_a_failed_job(): void
    {
        config()->set('queue.default', 'database');

        $failedJobId = DB::table('failed_jobs')->insertGetId([
            'uuid' => '4db6a062-e945-4ad2-a6d1-8d91679f1234',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'commandName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                    'command' => 'serialized-command-placeholder',
                ],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: test',
            'failed_at' => now(),
        ]);

        Livewire::test(AdminJobQueue::class)
            ->call('retryFailedJob', $failedJobId)
            ->assertSet('statusTone', 'good')
            ->assertSet('statusMessage', 'Queued the failed job for another attempt.');

        $this->assertDatabaseMissing('failed_jobs', [
            'id' => $failedJobId,
        ]);

        $this->assertDatabaseHas('jobs', [
            'queue' => 'default',
        ]);
    }

    public function test_admin_job_queue_component_can_delete_a_failed_job(): void
    {
        config()->set('queue.default', 'database');

        $failedJobId = DB::table('failed_jobs')->insertGetId([
            'uuid' => '5212f1eb-5f04-4da7-9dad-3aa8b0f6d123',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RefreshCameraPreviewJob',
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: delete test',
            'failed_at' => now(),
        ]);

        $remainingFailedJobId = DB::table('failed_jobs')->insertGetId([
            'uuid' => '21a79864-f27d-4432-b7c4-6b6e58d79c55',
            'connection' => 'database',
            'queue' => 'recordings',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ProcessCameraRecordingJob',
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: keep me',
            'failed_at' => now()->subSecond(),
        ]);

        Livewire::test(AdminJobQueue::class)
            ->call('deleteFailedJob', $failedJobId)
            ->assertSet('statusTone', 'good')
            ->assertSet('statusMessage', 'Deleted the selected failed job.');

        $this->assertDatabaseMissing('failed_jobs', [
            'id' => $failedJobId,
        ]);

        $this->assertDatabaseHas('failed_jobs', [
            'id' => $remainingFailedJobId,
        ]);
    }

    public function test_admin_job_queue_component_can_clear_all_failed_jobs(): void
    {
        config()->set('queue.default', 'database');

        DB::table('failed_jobs')->insert([
            [
                'uuid' => 'ab8a8ccf-c0d4-45ef-a2f1-bbf90352f111',
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\RefreshCameraPreviewJob',
                ], JSON_THROW_ON_ERROR),
                'exception' => 'RuntimeException: clear test 1',
                'failed_at' => now(),
            ],
            [
                'uuid' => '028f32d2-4269-4f31-9bb7-24d590c49888',
                'connection' => 'database',
                'queue' => 'recordings',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\ProcessCameraRecordingJob',
                ], JSON_THROW_ON_ERROR),
                'exception' => 'RuntimeException: clear test 2',
                'failed_at' => now()->subSecond(),
            ],
        ]);

        Livewire::test(AdminJobQueue::class)
            ->call('clearFailedJobs')
            ->assertSet('statusTone', 'good')
            ->assertSet('statusMessage', 'Deleted 2 failed job records.');

        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_admin_job_queue_component_warns_when_worker_demand_reaches_the_cap(): void
    {
        config()->set('queue.default', 'database');
        config()->set('recording.worker.ensure_running', true);
        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.dynamic_enabled', true);
        config()->set('recording.worker.max_processes', 2);
        config()->set('recording.worker.cameras_per_process', 4);
        config()->set('recording.worker.jobs_per_process', 200);

        foreach (range(1, 8) as $index) {
            Camera::query()->create([
                'name' => 'Queue Warning Cam '.$index,
                'local_ip' => '192.168.1.'.(40 + $index),
                'rtsp_port' => 554,
                'rtsp_path' => '/stream'.$index,
                'supports_onvif' => false,
                'supports_rtsp' => true,
                'is_enabled' => true,
                'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
                'recording_retention_days' => 1,
            ]);
        }

        Livewire::test(AdminJobQueue::class)
            ->assertSee('Worker demand has reached the configured ceiling of 2 processes.');
    }

    public function test_non_admin_users_cannot_open_the_current_users_page(): void
    {
        User::factory()->admin()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_promote_a_standard_operator_to_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)
            ->put(route('admin.users.admin-role', ['user' => $operator]), [
                'is_admin' => '1',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($operator->fresh()->is_admin);
    }

    public function test_admin_can_allow_a_google_sign_in_email(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.allowed-emails.store'), [
                'email' => 'Operator@Example.com',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('allowed_login_emails', [
            'email' => 'operator@example.com',
            'added_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_remove_a_non_admin_google_sign_in_email(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
        ]);
        $entry = AllowedLoginEmail::query()->create([
            'email' => 'operator@example.com',
            'added_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.users.allowed-emails.destroy', ['allowedLoginEmail' => $entry]))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('allowed_login_emails', [
            'id' => $entry->id,
        ]);
    }

    public function test_last_admin_sign_in_email_cannot_be_removed(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
        ]);
        $entry = AllowedLoginEmail::query()->create([
            'email' => 'admin@example.com',
            'added_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.users.allowed-emails.destroy', ['allowedLoginEmail' => $entry]))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('allowed_login_emails', [
            'id' => $entry->id,
            'email' => 'admin@example.com',
        ]);
    }

    public function test_admin_can_demote_another_admin_when_multiple_admins_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.users.admin-role', ['user' => $otherAdmin]), [
                'is_admin' => '0',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse($otherAdmin->fresh()->is_admin);
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.users.admin-role', ['user' => $admin]), [
                'is_admin' => '0',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_recording_pages_use_the_configured_operator_timezone(): void
    {
        app(ApplicationSettingsService::class)->saveAppTimezone('Europe/Amsterdam');

        $operator = User::factory()->create();
        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.122',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 0),
            'started_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 0),
            'ended_at' => now()->utc()->setDate(2026, 4, 4)->setTime(12, 1),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/04/north-gate.mkv',
            'file_size_bytes' => 1024,
            'message' => 'Clip saved.',
        ]);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.index'))
            ->assertOk()
            ->assertSee('2026-04-04 14:00');

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('recordings.show', ['recording' => $recording]))
            ->assertOk()
            ->assertSee('2026-04-04 14:00:00');
    }

    public function test_admin_network_storage_panel_can_enable_smb_storage(): void
    {
        Livewire::test(NetworkStorageSettingsPanel::class)
            ->set('networkStorageEnabled', '1')
            ->set('networkStoragePath', '//192.168.1.199/fileshare/Applications/bigbrotha')
            ->set('networkStorageUsername', 'administrator')
            ->set('networkStoragePassword', 'secret-pass')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('networkStorageEnabled', '1');

        $this->assertDatabaseHas('app_settings', [
            'key' => ApplicationSettingsService::SETTING_NETWORK_STORAGE,
            'network_storage_enabled' => 1,
            'network_storage_path' => '//192.168.1.199/fileshare/Applications/bigbrotha',
            'network_storage_username' => 'administrator',
        ]);
    }

    public function test_admin_network_storage_panel_can_update_legacy_plaintext_password_records(): void
    {
        DB::table('app_settings')->insert([
            'key' => ApplicationSettingsService::SETTING_NETWORK_STORAGE,
            'value' => null,
            'network_storage_enabled' => 1,
            'network_storage_path' => '//192.168.1.199/fileshare/Applications/bigbrotha',
            'network_storage_username' => 'administrator',
            'network_storage_password' => 'legacy-plain-password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(NetworkStorageSettingsPanel::class)
            ->assertSet('networkStorageEnabled', '1')
            ->assertSet('networkStoragePath', '//192.168.1.199/fileshare/Applications/bigbrotha')
            ->assertSet('networkStorageUsername', 'administrator')
            ->assertSet('hasStoredPassword', true)
            ->set('networkStoragePassword', 'fresh-secret')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('networkStorageEnabled', '1')
            ->assertSet('hasStoredPassword', true);

        $diskConfig = app(ApplicationSettingsService::class)->networkStorageDiskConfig();

        $this->assertNotNull($diskConfig);
        $this->assertSame('fresh-secret', $diskConfig['password']);
        $this->assertNotSame(
            'fresh-secret',
            DB::table('app_settings')
                ->where('key', ApplicationSettingsService::SETTING_NETWORK_STORAGE)
                ->value('network_storage_password'),
        );
    }

    public function test_network_storage_disk_config_normalizes_roots_that_end_with_cameras(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha/cameras',
            'administrator',
            'secret-pass',
        );

        $diskConfig = app(ApplicationSettingsService::class)->networkStorageDiskConfig();

        $this->assertNotNull($diskConfig);
        $this->assertSame('Applications/bigbrotha/cameras', $diskConfig['root']);
    }

    public function test_network_storage_disk_config_appends_cameras_for_legacy_parent_paths(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $diskConfig = app(ApplicationSettingsService::class)->networkStorageDiskConfig();

        $this->assertNotNull($diskConfig);
        $this->assertSame('Applications/bigbrotha/cameras', $diskConfig['root']);
    }

    public function test_network_storage_disk_config_keeps_share_root_when_the_share_itself_is_cameras(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/cameras',
            'administrator',
            'secret-pass',
        );

        $diskConfig = app(ApplicationSettingsService::class)->networkStorageDiskConfig();

        $this->assertNotNull($diskConfig);
        $this->assertSame('192.168.1.199', $diskConfig['host']);
        $this->assertSame('cameras', $diskConfig['share']);
        $this->assertSame('', $diskConfig['root']);
    }

    public function test_network_storage_path_rejects_uri_credentials_and_parent_traversal(): void
    {
        $settings = app(ApplicationSettingsService::class);

        $this->assertNull($settings->parseNetworkStoragePath('smb://operator:secret@fileserver/share/cameras'));
        $this->assertNull($settings->parseNetworkStoragePath('//fileserver/share/../private'));
        $this->assertNull($settings->parseNetworkStoragePath("//fileserver/share/cameras\nother"));
    }

    public function test_smbclient_fallback_uses_a_private_authentication_file_instead_of_process_arguments(): void
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        $argumentLog = $binaryDirectory.'/smbclient-arguments.log';
        $authLog = $binaryDirectory.'/smbclient-auth.log';
        $binary = $binaryDirectory.'/fake-smbclient.sh';
        File::ensureDirectoryExists($binaryDirectory);
        File::delete([$argumentLog, $authLog]);
        File::put($binary, str_replace(
            ['__ARGUMENT_LOG__', '__AUTH_LOG__'],
            [$argumentLog, $authLog],
            <<<'BASH'
#!/usr/bin/env bash
set -e
printf '%s\n' "$@" > "__ARGUMENT_LOG__"
previous=""
for argument in "$@"; do
    if [[ "$previous" == "-A" ]]; then
        cp "$argument" "__AUTH_LOG__"
        stat -c '%a' "$argument" >> "__AUTH_LOG__"
        break
    fi
    previous="$argument"
done
BASH
        ));
        chmod($binary, 0755);

        $storage = app(CameraStorageService::class);
        $method = new \ReflectionMethod($storage, 'runSmbClientCommand');
        $method->setAccessible(true);
        $process = $method->invoke($storage, $binary, [
            'host' => 'fileserver',
            'share' => 'recordings',
            'root' => 'cameras',
            'username' => 'DOMAIN\\operator',
            'password' => 'secret%with spaces',
        ], 'allinfo "cameras/1/clip.mkv"', 3);

        $this->assertTrue($process->isSuccessful());
        $arguments = (string) file_get_contents($argumentLog);
        $auth = (string) file_get_contents($authLog);
        $this->assertStringContainsString("-A\n", $arguments);
        $this->assertStringNotContainsString('secret%with spaces', $arguments);
        $this->assertStringContainsString('username = operator', $auth);
        $this->assertStringContainsString('password = secret%with spaces', $auth);
        $this->assertStringContainsString('domain = DOMAIN', $auth);
        $this->assertStringEndsWith("600\n", $auth);
    }

    public function test_camera_storage_reads_legacy_parent_root_targets_for_network_recordings(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha/cameras',
            'administrator',
            'secret-pass',
        );

        $storage = app(CameraStorageService::class);
        $method = new \ReflectionMethod($storage, 'cameraDiskReadSmbTargetPaths');
        $method->setAccessible(true);

        $targets = $method->invoke($storage, '8/recordings/2026/05/09/20260509_151144-motion.mp4');

        $this->assertSame([
            'Applications/bigbrotha/cameras/8/recordings/2026/05/09/20260509_151144-motion.mp4',
            'Applications/bigbrotha/8/recordings/2026/05/09/20260509_151144-motion.mp4',
        ], $targets);
    }

    public function test_camera_storage_builds_the_full_smb_directory_chain_for_normalized_roots(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $storage = app(CameraStorageService::class);
        $method = new \ReflectionMethod($storage, 'cameraDiskSmbDirectoryTargetPaths');
        $method->setAccessible(true);

        $targets = $method->invoke($storage, '3/recordings/2026/05/10');

        $this->assertSame([
            'Applications',
            'Applications/bigbrotha',
            'Applications/bigbrotha/cameras',
            'Applications/bigbrotha/cameras/3',
            'Applications/bigbrotha/cameras/3/recordings',
            'Applications/bigbrotha/cameras/3/recordings/2026',
            'Applications/bigbrotha/cameras/3/recordings/2026/05',
            'Applications/bigbrotha/cameras/3/recordings/2026/05/10',
        ], $targets);
    }

    public function test_camera_storage_keeps_network_camera_setup_local_until_publish_time(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $camera = Camera::query()->create([
            'name' => 'Network Staging Lane',
            'local_ip' => '192.168.1.213',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream4',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('cameraDisk')->never();

        $stagingRoot = $storage->ensureCameraDirectories($camera);

        $this->assertSame(
            storage_path('app/private/ffmpeg-temp/camera-network-staging/cameras/'.$camera->id),
            $stagingRoot,
        );
        $this->assertDirectoryExists($stagingRoot.'/recordings');
        $this->assertDirectoryExists(storage_path('app/private/cameras/'.$camera->id.'/previews'));
    }

    public function test_authentication_settings_seeder_preserves_existing_auth_channels_without_explicit_seed_inputs(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'local_auth_enabled' => true,
        ]);

        $service = app(\App\Services\AuthenticationSettingsService::class);
        $configuration = [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
            'redirect_uri' => 'https://example.test/auth/google/callback',
        ];
        $verification = [
            'fingerprint' => $service->googleConfigurationFingerprint(
                $configuration['client_id'],
                $configuration['client_secret'],
                $configuration['redirect_uri'],
            ),
            'tested_at' => now()->utc()->toIso8601String(),
            'tested_email' => $admin->email,
        ];
        $service->saveConfiguration(true, true, $configuration, $verification);

        $this->withTemporaryEnv([
            'SEED_ADMIN_EMAIL' => null,
            'SEED_ADMIN_PASSWORD' => null,
            'SEED_ADMIN_NAME' => null,
            'SEED_GOOGLE_AUTH_ENABLED' => null,
            'SEED_GOOGLE_CLIENT_ID' => null,
            'SEED_GOOGLE_CLIENT_SECRET' => null,
            'SEED_GOOGLE_REDIRECT_URI' => null,
            'SEED_GOOGLE_TESTED_EMAIL' => null,
        ], function (): void {
            (new \Database\Seeders\AuthenticationSettingsSeeder())->run();
        });

        $freshService = app(\App\Services\AuthenticationSettingsService::class);

        $this->assertTrue($freshService->manualAuthEnabled());
        $this->assertTrue($freshService->googleAuthEnabled());
    }

    public function test_network_storage_settings_seeder_preserves_existing_settings_without_explicit_seed_inputs(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $this->withTemporaryEnv([
            'SEED_SMB_ENABLED' => null,
            'SEED_SMB_PATH' => null,
            'SEED_SMB_USERNAME' => null,
            'SEED_SMB_PASSWORD' => null,
        ], function (): void {
            (new \Database\Seeders\NetworkStorageSettingsSeeder())->run();
        });

        $settings = app(ApplicationSettingsService::class)->networkStorageSettings();

        $this->assertTrue($settings['enabled']);
        $this->assertSame('//192.168.1.199/fileshare/Applications/bigbrotha', $settings['path']);
        $this->assertSame('administrator', $settings['username']);
        $this->assertTrue($settings['has_password']);
    }

    public function test_camera_fleet_seeder_does_not_overwrite_existing_cameras(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Existing Front Gate',
            'local_ip' => '192.168.1.210',
            'hostname' => 'existing-front-gate.local',
            'rtsp_port' => 554,
            'rtsp_path' => '/existing-stream',
            'recording_rtsp_path' => '/existing-stream',
            'password' => 'existing-secret',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $this->withTemporaryEnv([
            'SEED_CAMERA_FLEET' => 'true',
        ], function (): void {
            (new \Database\Seeders\CameraFleetSeeder())->run();
        });

        $camera->refresh();

        $this->assertSame('Existing Front Gate', $camera->name);
        $this->assertSame('existing-front-gate.local', $camera->hostname);
        $this->assertSame('/existing-stream', $camera->rtsp_path);
        $this->assertSame('existing-secret', $camera->password);
    }

    public function test_timeline_review_allows_zooming_beyond_eight_times(): void
    {
        Livewire::test(TimelineReview::class, [
            'timelineHours' => 24,
            'timelinePayload' => [
                'dayStartMs' => Carbon::create(2026, 4, 7, 0, 0, 0, 'UTC')->valueOf(),
                'dayEndMs' => Carbon::create(2026, 4, 8, 0, 0, 0, 'UTC')->valueOf(),
                'focusAtMs' => Carbon::create(2026, 4, 7, 12, 0, 0, 'UTC')->valueOf(),
                'zoomScale' => 12.0,
            ],
        ])
            ->assertSet('timelineZoomScale', 12.0)
            ->assertSet('timelineZoomMaxScale', 16.0);
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function withTemporaryEnv(array $overrides, callable $callback): mixed
    {
        $originals = [];

        foreach ($overrides as $key => $value) {
            $original = getenv($key);
            $originals[$key] = $original === false ? null : $original;

            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            return $callback();
        } finally {
            foreach ($originals as $key => $value) {
                if ($value === null) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);

                    continue;
                }

                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    public function test_camera_storage_normalizes_staging_prefixed_recording_paths(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Storage Lane',
            'local_ip' => '192.168.1.210',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $storage = app(CameraStorageService::class);
        $absolutePath = $storage->writableAbsolutePath('cameras/'.$camera->id.'/recordings/2026/04/07/fixed-path.mkv');
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'segment');

        $this->assertSame(
            'cameras/'.$camera->id.'/recordings/2026/04/07/fixed-path.mkv',
            $storage->recordingRelativePathFromAbsolute($absolutePath),
        );

        $this->assertTrue($storage->recordingExists('camera-network-staging/cameras/'.$camera->id.'/recordings/2026/04/07/fixed-path.mkv'));
    }

    public function test_camera_storage_normalizes_absolute_read_cache_review_manifest_paths(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Storage Lane',
            'local_ip' => '192.168.1.212',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream3',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $storage = app(CameraStorageService::class);
        $cachedManifestPath = storage_path('app/private/ffmpeg-temp/camera-network-cache/cameras/'.$camera->id.'/recordings/2026/04/07/_review/20260407_203818-motion/manifest.json.cache_deadbeef');

        $this->assertSame(
            'cameras/'.$camera->id.'/recordings/2026/04/07/_review/20260407_203818-motion/manifest.json',
            $storage->normalizePrivateStorageRelativePath($cachedManifestPath),
        );

        $newCachedManifestPath = storage_path('app/private/ffmpeg-temp/camera-network-cache/cameras/'.$camera->id.'/recordings/2026/04/07/_review/20260407_203818-motion/manifest.cache_deadbeef.json');

        $this->assertSame(
            'cameras/'.$camera->id.'/recordings/2026/04/07/_review/20260407_203818-motion/manifest.json',
            $storage->normalizePrivateStorageRelativePath($newCachedManifestPath),
        );
    }

    public function test_camera_storage_network_read_cache_preserves_the_original_extension(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        config()->set('filesystems.disks.camera_private', [
            'driver' => 'local',
            'root' => storage_path('app/private/test-camera-private-disk'),
            'throw' => true,
            'report' => false,
        ]);

        $camera = Camera::query()->create([
            'name' => 'Storage Lane',
            'local_ip' => '192.168.1.214',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream5',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $storage = app(CameraStorageService::class);
        $relativePath = 'cameras/'.$camera->id.'/recordings/2026/04/07/network-read.mkv';
        $stagedPath = $storage->writableAbsolutePath($relativePath);

        File::ensureDirectoryExists(dirname($stagedPath));
        File::put($stagedPath, 'segment');
        $storage->finalizeStagedWrite($relativePath, $stagedPath);

        $resolvedPath = $storage->resolveRecordingAbsolutePath($relativePath);

        $this->assertNotNull($resolvedPath);
        $this->assertStringEndsWith('.mkv', (string) $resolvedPath);
        $this->assertTrue($storage->isTemporaryManagedPath($resolvedPath));

        $storage->deleteTemporaryFile($resolvedPath);
    }

    public function test_camera_storage_rejects_traversal_paths_for_private_reads(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Storage Lane',
            'local_ip' => '192.168.1.213',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream4',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $outsidePath = storage_path('app/private/../escaped-recording.mkv');
        File::ensureDirectoryExists(dirname($outsidePath));
        File::put($outsidePath, 'escaped');

        $storage = app(CameraStorageService::class);
        $traversalPath = 'cameras/'.$camera->id.'/recordings/2026/04/07/../../../../../escaped-recording.mkv';

        $this->assertNull($storage->normalizePrivateStorageRelativePath($traversalPath));
        $this->assertFalse($storage->recordingExists($traversalPath));
        $this->assertNull($storage->resolveRecordingAbsolutePath($traversalPath));
    }

    public function test_camera_storage_does_not_delete_files_outside_managed_temp_roots(): void
    {
        $storage = app(CameraStorageService::class);
        $outsidePath = storage_path('app/private/ffmpeg-temp/outside.txt');
        $craftedPath = storage_path('app/private/ffmpeg-temp/camera-network-cache/../outside.txt');

        File::ensureDirectoryExists(dirname($outsidePath));
        File::put($outsidePath, 'keep-me');

        $this->assertFalse($storage->isTemporaryManagedPath($craftedPath));

        $storage->deleteTemporaryFile($craftedPath);

        $this->assertFileExists($outsidePath);
    }

    public function test_camera_storage_stages_network_writes_under_ffmpeg_temp_and_finalizes_nested_paths(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        config()->set('filesystems.disks.camera_private', [
            'driver' => 'local',
            'root' => storage_path('app/private/test-camera-private-disk'),
            'throw' => true,
            'report' => false,
        ]);

        $camera = Camera::query()->create([
            'name' => 'Finalize Lane',
            'local_ip' => '192.168.1.211',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $storage = app(CameraStorageService::class);
        $relativePath = 'cameras/'.$camera->id.'/recordings/2026/04/07/finalize-check.mkv';
        $absolutePath = $storage->writableAbsolutePath($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'segment');

        $this->assertStringStartsWith(
            storage_path('app/private/ffmpeg-temp/camera-network-staging/'),
            $absolutePath,
        );

        $storage->finalizeStagedWrite($relativePath, $absolutePath);

        $this->assertTrue($storage->recordingExists($relativePath));
        $this->assertFileExists(storage_path('app/private/test-camera-private-disk/'.$camera->id.'/recordings/2026/04/07/finalize-check.mkv'));
        $this->assertFileDoesNotExist($absolutePath);
    }

    public function test_camera_storage_keeps_preview_files_local_when_network_storage_is_enabled(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        config()->set('filesystems.disks.camera_private', [
            'driver' => 'local',
            'root' => storage_path('app/private/test-camera-private-disk'),
            'throw' => true,
            'report' => false,
        ]);

        $camera = Camera::query()->create([
            'name' => 'Preview Lane',
            'local_ip' => '192.168.1.215',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream6',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $storage = app(CameraStorageService::class);
        $relativePath = 'cameras/'.$camera->id.'/previews/network-preview.jpg';
        $absolutePath = $storage->writableAbsolutePath($relativePath);

        $this->assertSame(
            storage_path('app/private/'.$relativePath),
            $absolutePath,
        );
        $this->assertFalse($storage->pathUsesNetworkStorage($relativePath));

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'preview');
        $storage->finalizeStagedWrite($relativePath, $absolutePath);

        $this->assertFileExists($absolutePath);
        $this->assertFileDoesNotExist(storage_path('app/private/test-camera-private-disk/'.$camera->id.'/previews/network-preview.jpg'));
    }

    public function test_camera_storage_keeps_review_assets_local_when_network_storage_is_enabled(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        config()->set('filesystems.disks.camera_private', [
            'driver' => 'local',
            'root' => storage_path('app/private/test-camera-private-disk'),
            'throw' => true,
            'report' => false,
        ]);

        $camera = Camera::query()->create([
            'name' => 'Review Lane',
            'local_ip' => '192.168.1.216',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream7',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $storage = app(CameraStorageService::class);
        $recordingRelativePath = 'cameras/'.$camera->id.'/recordings/2026/04/07/review-check.mkv';
        $manifestRelativePath = $storage->recordingReviewAssetRelativePath($recordingRelativePath, 'manifest.json');
        $manifestAbsolutePath = $storage->recordingReviewAssetAbsolutePath($recordingRelativePath, 'manifest.json', true);

        $this->assertSame(
            storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/07/_review/review-check/manifest.json'),
            $manifestAbsolutePath,
        );
        $this->assertFalse($storage->pathUsesNetworkStorage($manifestRelativePath));

        File::ensureDirectoryExists(dirname($manifestAbsolutePath));
        File::put($manifestAbsolutePath, '{}');
        $storage->finalizeStagedWrite($manifestRelativePath, $manifestAbsolutePath);

        $this->assertFileExists($manifestAbsolutePath);
        $this->assertFileDoesNotExist(storage_path('app/private/test-camera-private-disk/'.$camera->id.'/recordings/2026/04/07/_review/review-check/manifest.json'));
    }

    public function test_camera_storage_keeps_the_staged_network_file_when_post_upload_verification_fails(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        config()->set('filesystems.disks.camera_private', [
            'driver' => 'local',
            'root' => storage_path('app/private/test-camera-private-disk'),
            'throw' => true,
            'report' => false,
        ]);

        $camera = Camera::query()->create([
            'name' => 'Finalize Lane',
            'local_ip' => '192.168.1.212',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream3',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $relativePath = 'cameras/'.$camera->id.'/recordings/2026/04/07/finalize-verify-check.mkv';
        $absolutePath = $storage->writableAbsolutePath($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'segment');

        $storage->shouldReceive('verifyCameraDiskWrite')
            ->once()
            ->andThrow(new \RuntimeException('Unable to verify the uploaded file on the active camera storage disk.'));

        try {
            $storage->finalizeStagedWrite($relativePath, $absolutePath);
            $this->fail('Expected post-upload verification to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unable to verify the uploaded file', $exception->getMessage());
        }

        $this->assertFileExists($absolutePath);
        $this->assertFileExists(storage_path('app/private/test-camera-private-disk/'.$camera->id.'/recordings/2026/04/07/finalize-verify-check.mkv'));
    }

    public function test_camera_storage_upload_verification_reports_target_paths(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $localPath = storage_path('app/private/ffmpeg-temp/camera-network-staging/cameras/7/recordings/2026/04/10/retry-check.mkv');
        File::ensureDirectoryExists(dirname($localPath));
        File::put($localPath, 'segment');

        $disk = \Mockery::mock(FilesystemContract::class);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('cameraDisk')->andReturn($disk);
        $storage->shouldReceive('cameraDiskFileAvailabilityWithSmbClient')
            ->once()
            ->with('7/recordings/2026/04/10/retry-check.mkv')
            ->andReturn(CameraStorageService::RECORDING_AVAILABILITY_MISSING);

        $method = new \ReflectionMethod($storage, 'verifyCameraDiskWrite');
        $method->setAccessible(true);

        try {
            $method->invoke($storage, '7/recordings/2026/04/10/retry-check.mkv', $localPath);
            $this->fail('Expected upload verification to fail.');
        } catch (\Throwable $exception) {
            $message = ($exception->getPrevious() ?? $exception)->getMessage();

            $this->assertStringContainsString('The uploaded file is not visible on the active camera storage disk yet.', $message);
            $this->assertStringContainsString('relative_path=cameras/7/recordings/2026/04/10/retry-check.mkv', $message);
            $this->assertStringContainsString('disk_path=7/recordings/2026/04/10/retry-check.mkv', $message);
            $this->assertStringContainsString('smb_target_path=Applications/bigbrotha/cameras/7/recordings/2026/04/10/retry-check.mkv', $message);
            $this->assertStringContainsString('local_path='.str_replace('\\', '/', $localPath), $message);
            $this->assertStringContainsString('availability=missing', $message);
        }
    }

    public function test_camera_storage_network_availability_uses_metadata_when_exists_returns_false(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $disk = \Mockery::mock(FilesystemContract::class);
        $disk->shouldReceive('exists')
            ->once()
            ->with('99/recordings/2026/04/07/metadata-check.mkv')
            ->andReturn(false);
        $disk->shouldReceive('size')
            ->once()
            ->with('99/recordings/2026/04/07/metadata-check.mkv')
            ->andReturn(8192);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('cameraDisk')->andReturn($disk);
        $storage->shouldReceive('cameraDiskFileAvailabilityWithSmbClient')
            ->once()
            ->with('99/recordings/2026/04/07/metadata-check.mkv')
            ->andReturn(null);

        $this->assertSame(
            CameraStorageService::RECORDING_AVAILABILITY_PRESENT,
            $storage->recordingAvailability('cameras/99/recordings/2026/04/07/metadata-check.mkv'),
        );
    }

    public function test_camera_storage_upload_verification_uses_smbclient_size_when_adapter_size_lookup_fails(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $localPath = storage_path('app/private/ffmpeg-temp/camera-network-staging/cameras/7/recordings/2026/04/10/size-fallback.mkv');
        File::ensureDirectoryExists(dirname($localPath));
        File::put($localPath, 'segment');

        $disk = \Mockery::mock(FilesystemContract::class);
        $disk->shouldReceive('exists')
            ->once()
            ->with('7/recordings/2026/04/10/size-fallback.mkv')
            ->andReturn(true);
        $disk->shouldReceive('size')
            ->once()
            ->with('7/recordings/2026/04/10/size-fallback.mkv')
            ->andThrow(new \RuntimeException('adapter miss'));

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('cameraDisk')->andReturn($disk);
        $storage->shouldReceive('cameraDiskFileAvailabilityWithSmbClient')
            ->once()
            ->with('7/recordings/2026/04/10/size-fallback.mkv')
            ->andReturn(null);
        $storage->shouldReceive('cameraDiskFileSizeWithSmbClient')
            ->once()
            ->with('7/recordings/2026/04/10/size-fallback.mkv')
            ->andReturn(strlen('segment'));

        $method = new \ReflectionMethod($storage, 'verifyCameraDiskWrite');
        $method->setAccessible(true);
        $method->invoke($storage, '7/recordings/2026/04/10/size-fallback.mkv', $localPath);

        $this->assertFileExists($localPath);
    }

    public function test_camera_storage_network_availability_reports_missing_when_parent_listing_does_not_contain_the_file(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $disk = \Mockery::mock(FilesystemContract::class);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('cameraDisk')->andReturn($disk);
        $storage->shouldReceive('cameraDiskFileAvailabilityWithSmbClient')
            ->once()
            ->with('99/recordings/2026/04/07/missing-check.mkv')
            ->andReturn(CameraStorageService::RECORDING_AVAILABILITY_MISSING);

        $this->assertSame(
            CameraStorageService::RECORDING_AVAILABILITY_MISSING,
            $storage->recordingAvailability('cameras/99/recordings/2026/04/07/missing-check.mkv'),
        );
    }

    public function test_camera_storage_network_availability_reports_unreachable_when_all_network_checks_fail(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $disk = \Mockery::mock(FilesystemContract::class);
        $disk->shouldReceive('exists')
            ->once()
            ->with('99/recordings/2026/04/07/unreachable-check.mkv')
            ->andThrow(new \RuntimeException('network down'));
        $disk->shouldReceive('size')
            ->once()
            ->with('99/recordings/2026/04/07/unreachable-check.mkv')
            ->andThrow(new \RuntimeException('network down'));
        $disk->shouldReceive('files')
            ->once()
            ->with('99/recordings/2026/04/07')
            ->andThrow(new \RuntimeException('network down'));

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('cameraDisk')->andReturn($disk);
        $storage->shouldReceive('cameraDiskFileAvailabilityWithSmbClient')
            ->once()
            ->with('99/recordings/2026/04/07/unreachable-check.mkv')
            ->andReturn(null);

        $this->assertSame(
            CameraStorageService::RECORDING_AVAILABILITY_UNREACHABLE,
            $storage->recordingAvailability('cameras/99/recordings/2026/04/07/unreachable-check.mkv'),
        );
    }

    public function test_camera_storage_network_availability_uses_smbclient_fallback_when_the_adapter_under_reports_the_file(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $disk = \Mockery::mock(FilesystemContract::class);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('cameraDisk')->andReturn($disk);
        $storage->shouldReceive('cameraDiskFileAvailabilityWithSmbClient')
            ->once()
            ->with('99/recordings/2026/04/07/smbclient-fallback.mkv')
            ->andReturn(CameraStorageService::RECORDING_AVAILABILITY_PRESENT);

        $this->assertSame(
            CameraStorageService::RECORDING_AVAILABILITY_PRESENT,
            $storage->recordingAvailability('cameras/99/recordings/2026/04/07/smbclient-fallback.mkv'),
        );
    }

    public function test_camera_storage_can_resolve_a_network_recording_when_smbclient_confirms_the_file(): void
    {
        app(ApplicationSettingsService::class)->saveNetworkStorageSettings(
            true,
            '//192.168.1.199/fileshare/Applications/bigbrotha',
            'administrator',
            'secret-pass',
        );

        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, 'segment-data');
        rewind($stream);

        $disk = \Mockery::mock(FilesystemContract::class);
        $disk->shouldReceive('readStream')
            ->once()
            ->with('99/recordings/2026/04/07/read-fallback.mkv')
            ->andReturn($stream);

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('cameraDisk')->andReturn($disk);
        $storage->shouldReceive('cameraDiskFileAvailabilityWithSmbClient')
            ->once()
            ->with('99/recordings/2026/04/07/read-fallback.mkv')
            ->andReturn(CameraStorageService::RECORDING_AVAILABILITY_PRESENT);

        $resolvedPath = $storage->resolveRecordingAbsolutePath('cameras/99/recordings/2026/04/07/read-fallback.mkv');

        $this->assertNotNull($resolvedPath);
        $this->assertSame('segment-data', file_get_contents($resolvedPath));
        $this->assertStringEndsWith('.mkv', $resolvedPath);

        $storage->deleteTemporaryFile($resolvedPath);
    }
}
