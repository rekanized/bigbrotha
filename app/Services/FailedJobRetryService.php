<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FailedJobRetryService
{
    private const PAYLOAD_META_KEY = 'bigbrotha_failed_job_retry';

    public function autoRetryEnabled(): bool
    {
        return (bool) config('queue.failed.auto_retry.enabled', true);
    }

    public function maxAutoRetries(): int
    {
        return max(0, (int) config('queue.failed.auto_retry.max_retries', 2));
    }

    public function autoRetryBatchSize(): int
    {
        return max(1, (int) config('queue.failed.auto_retry.batch_size', 5));
    }

    public function autoRetryCooldownSeconds(): int
    {
        return max(0, (int) config('queue.failed.auto_retry.cooldown_seconds', 60));
    }

    /**
     * @return array{total_retries: int, auto_retries: int, manual_retries: int, last_retry_type: string|null, last_retry_at: string|null}
     */
    public function retryMetadataFromPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        $meta = is_array($decoded) && is_array($decoded[self::PAYLOAD_META_KEY] ?? null)
            ? $decoded[self::PAYLOAD_META_KEY]
            : [];

        $lastRetryType = $meta['last_retry_type'] ?? null;

        return [
            'total_retries' => max(0, (int) ($meta['total_retries'] ?? 0)),
            'auto_retries' => max(0, (int) ($meta['auto_retries'] ?? 0)),
            'manual_retries' => max(0, (int) ($meta['manual_retries'] ?? 0)),
            'last_retry_type' => in_array($lastRetryType, ['auto', 'manual'], true) ? $lastRetryType : null,
            'last_retry_at' => is_string($meta['last_retry_at'] ?? null) && trim((string) $meta['last_retry_at']) !== ''
                ? (string) $meta['last_retry_at']
                : null,
        ];
    }

    /**
     * @return array{enabled: bool, scanned: int, retried: int, limit_reached: int, errors: int}
     */
    public function retryBatch(?int $limit = null): array
    {
        if (!$this->autoRetryEnabled() || $this->maxAutoRetries() === 0 || !Schema::hasTable('failed_jobs')) {
            return [
                'enabled' => false,
                'scanned' => 0,
                'retried' => 0,
                'limit_reached' => 0,
                'errors' => 0,
            ];
        }

        $effectiveLimit = $limit === null ? $this->autoRetryBatchSize() : max(1, $limit);
        $eligibleFailedAt = now()->subSeconds($this->autoRetryCooldownSeconds());

        $result = [
            'enabled' => true,
            'scanned' => 0,
            'retried' => 0,
            'limit_reached' => 0,
            'errors' => 0,
        ];

        foreach ($this->eligibleFailedJobIds($eligibleFailedAt) as $failedJobId) {
            if ($result['retried'] >= $effectiveLimit) {
                break;
            }

            $result['scanned']++;
            $retryResult = $this->retryFailedJobById($failedJobId, automatic: true);

            if ($retryResult['status'] === 'retried') {
                $result['retried']++;

                continue;
            }

            if ($retryResult['status'] === 'limit-reached') {
                $result['limit_reached']++;

                continue;
            }

            if ($retryResult['status'] === 'missing') {
                continue;
            }

            $result['errors']++;
        }

        return $result;
    }

    /**
     * @return iterable<int>
     */
    private function eligibleFailedJobIds(\DateTimeInterface $eligibleFailedAt): iterable
    {
        foreach (DB::table('failed_jobs')
            ->where('failed_at', '<=', $eligibleFailedAt)
            ->orderBy('failed_at')
            ->orderBy('id')
            ->cursor() as $failedJob) {
            yield (int) $failedJob->id;
        }
    }

    /**
     * @return array{status: string, message?: string, metadata?: array{total_retries: int, auto_retries: int, manual_retries: int, last_retry_type: string|null, last_retry_at: string|null}}
     */
    public function retryFailedJobById(int $failedJobId, bool $automatic = false, bool $force = false): array
    {
        try {
            return DB::transaction(function () use ($failedJobId, $automatic, $force): array {
                $failedJob = DB::table('failed_jobs')
                    ->where('id', $failedJobId)
                    ->lockForUpdate()
                    ->first();

                if ($failedJob === null) {
                    return ['status' => 'missing'];
                }

                $metadata = $this->retryMetadataFromPayload((string) $failedJob->payload);

                if ($automatic && !$force && $metadata['total_retries'] >= $this->maxAutoRetries()) {
                    return [
                        'status' => 'limit-reached',
                        'metadata' => $metadata,
                    ];
                }

                $connection = trim((string) $failedJob->connection) !== ''
                    ? (string) $failedJob->connection
                    : (string) config('queue.default');

                $payload = $this->payloadWithUpdatedRetryMetadata((string) $failedJob->payload, $metadata, $automatic);

                Queue::connection($connection)->pushRaw($payload, (string) $failedJob->queue);

                DB::table('failed_jobs')->where('id', $failedJobId)->delete();

                return [
                    'status' => 'retried',
                    'metadata' => $this->retryMetadataFromPayload($payload),
                ];
            });
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{total_retries: int, auto_retries: int, manual_retries: int, last_retry_type: string|null, last_retry_at: string|null}  $metadata
     */
    private function payloadWithUpdatedRetryMetadata(string $payload, array $metadata, bool $automatic): string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return $payload;
        }

        $decoded[self::PAYLOAD_META_KEY] = [
            'total_retries' => $metadata['total_retries'] + 1,
            'auto_retries' => $metadata['auto_retries'] + ($automatic ? 1 : 0),
            'manual_retries' => $metadata['manual_retries'] + ($automatic ? 0 : 1),
            'last_retry_type' => $automatic ? 'auto' : 'manual',
            'last_retry_at' => now()->toIso8601String(),
        ];

        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : $payload;
    }
}