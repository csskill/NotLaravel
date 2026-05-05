<?php

namespace Nraa\Workers\Documents;

use Nraa\Database\Attributes\Index;
use Nraa\Database\Model;

#[Index(keys: ['status' => 1, 'updatedAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'assignee' => 1, 'createdAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'assignee' => 1, 'priority' => -1, 'createdAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'nextRunAt' => 1, 'createdAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'pool' => 1, 'priority' => -1, 'nextRunAt' => 1, 'createdAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'pool' => 1, 'lane' => 1, 'priority' => -1, 'nextRunAt' => 1, 'createdAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'pool' => 1, 'lane' => 1, 'fairness_key' => 1, 'nextRunAt' => 1, 'createdAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'lastHeartbeat' => 1], options: [])]
#[Index(keys: ['status' => 1, 'startedAt' => 1], options: [])]
#[Index(keys: ['assignee' => 1, 'status' => 1], options: [])]
#[Index(keys: ['task.class' => 1, 'status' => 1], options: [])]
#[Index(keys: ['pool' => 1, 'status' => 1, 'updatedAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'terminalAt' => -1], options: [])]
#[Index(keys: ['idempotency_key' => 1], options: ['sparse' => true])]
#[Index(
    keys: ['idempotency_key' => 1, 'status' => 1, 'createdAt' => 1],
    options: ['partialFilterExpression' => ['idempotency_key' => ['$exists' => true, '$type' => 'string']]]
)]
#[Index(
    keys: ['active_idempotency_key' => 1],
    options: ['unique' => true, 'partialFilterExpression' => ['active_idempotency_key' => ['$exists' => true, '$type' => 'string']]]
)]
final class JobDocument extends Model
{
    private const ACTIVE_STATUSES = ['pending', 'assigned', 'in_progress'];

    protected static $collection = 'jobs';

    // Public properties for job data
    public string $status = 'pending';
    public array $task = [];
    public array $instructions = [];
    public ?string $assignee = null;
    public ?string $employer = null;
    public int $priority = 1;
    public ?string $pool = null;
    public ?string $lane = null;
    public ?string $fairness_key = null;
    public ?int $workerLimit = null;
    public ?string $idempotency_key = null;
    public ?string $active_idempotency_key = null;
    /**
     * @deprecated Use $attempts instead. Will be removed in future version.
     */
    public int $retries = 0;
    /**
     * @deprecated Use $maxAttempts instead. Will be removed in future version.
     */
    public int $maxRetries = 3;
    public ?string $error = null;
    public ?\MongoDB\BSON\UTCDateTime $nextRunAt = null;
    public ?\MongoDB\BSON\UTCDateTime $startedAt = null;
    public ?\MongoDB\BSON\UTCDateTime $completedAt = null;
    public ?\MongoDB\BSON\UTCDateTime $failedAt = null;
    public ?\MongoDB\BSON\UTCDateTime $terminalAt = null;
    public ?\MongoDB\BSON\UTCDateTime $assignedAt = null;
    public ?int $attempts = null;
    public ?int $maxAttempts = null;
    public ?\MongoDB\BSON\UTCDateTime $lastHeartbeat = null;

    /**
     * Atomically update job status
     * 
     * @param string $status The new status
     * @return bool True if update was successful
     */
    public function setStatus($status): bool
    {
        $this->status = $status;
        $this->save();
        return true;
    }

    /**
     * Mark job as completed
     * 
     * @return bool True if update was successful
     */
    public function markCompleted(): bool
    {
        $this->status = 'completed';
        $this->completedAt = new \MongoDB\BSON\UTCDateTime();
        $this->terminalAt = new \MongoDB\BSON\UTCDateTime();
        $this->assignee = null;
        $this->assignedAt = null;
        $this->startedAt = null;
        $this->lastHeartbeat = null;
        $this->nextRunAt = null;
        $this->failedAt = null;
        $this->error = null;
        $this->updatedAt = new \MongoDB\BSON\UTCDateTime();
        $this->save();
        return true;
    }

    /**
     * Mark job as auto-resolved.
     *
     * @param string $message Resolution note
     * @return bool True if update was successful
     */
    public function markAutoResolved(string $message): bool
    {
        $this->status = 'auto_resolved';
        $this->completedAt = new \MongoDB\BSON\UTCDateTime();
        $this->terminalAt = new \MongoDB\BSON\UTCDateTime();
        $this->failedAt = null;
        $this->assignee = null;
        $this->assignedAt = null;
        $this->startedAt = null;
        $this->lastHeartbeat = null;
        $this->nextRunAt = null;
        $this->error = $message;
        $this->updatedAt = new \MongoDB\BSON\UTCDateTime();
        $this->save();
        return true;
    }

    /**
     * Mark job as failed
     * 
     * @param string $error The error message
     * @return bool True if update was successful
     */
    public function markFailed(string $error): bool
    {
        $this->status = 'failed';
        $this->failedAt = new \MongoDB\BSON\UTCDateTime();
        $this->terminalAt = new \MongoDB\BSON\UTCDateTime();
        $this->assignee = null;
        $this->assignedAt = null;
        $this->startedAt = null;
        $this->lastHeartbeat = null;
        $this->nextRunAt = null;
        $this->error = $error;
        $this->updatedAt = new \MongoDB\BSON\UTCDateTime();
        $this->save();
        return true;
    }

    public function markManuallyResolved(?string $message = null): bool
    {
        $this->status = 'manually_resolved';
        $this->completedAt = new \MongoDB\BSON\UTCDateTime();
        $this->terminalAt = new \MongoDB\BSON\UTCDateTime();
        $this->assignee = null;
        $this->assignedAt = null;
        $this->startedAt = null;
        $this->lastHeartbeat = null;
        $this->nextRunAt = null;
        $this->failedAt = null;
        if ($message !== null && trim($message) !== '') {
            $this->error = trim($message);
        }
        $this->updatedAt = new \MongoDB\BSON\UTCDateTime();
        $this->save();
        return true;
    }

    public static function create(object|array $data): self
    {
        $dataArr = array_merge([
            'status' => 'pending'
        ], (array) $data);

        $dataArr['nextRunAt'] = $dataArr['nextRunAt'] ?? new \MongoDB\BSON\UTCDateTime();
        $job = parent::create($dataArr);
        $job->save();
        return $job;
    }

    public function save()
    {
        $this->syncLifecycleFields();
        parent::save();
    }

    public static function isActiveStatus(?string $status): bool
    {
        $status = trim((string)$status);
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    public static function findActiveByIdempotencyKey(?string $idempotencyKey): ?self
    {
        $idempotencyKey = trim((string)$idempotencyKey);
        if ($idempotencyKey === '') {
            return null;
        }

        $existing = self::findOne(self::activeIdempotencyLookupFilter($idempotencyKey));
        if ($existing instanceof self) {
            return $existing;
        }

        $existing = self::findOne(
            self::legacyActiveIdempotencyLookupFilter($idempotencyKey),
            self::legacyActiveIdempotencyLookupOptions()
        );

        return $existing instanceof self ? $existing : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function activeIdempotencyLookupFilter(string $idempotencyKey): array
    {
        return [
            'active_idempotency_key' => [
                '$eq' => $idempotencyKey,
                '$type' => 'string',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function legacyActiveIdempotencyLookupFilter(string $idempotencyKey): array
    {
        return [
            'idempotency_key' => [
                '$eq' => $idempotencyKey,
                '$type' => 'string',
            ],
            'status' => ['$in' => self::ACTIVE_STATUSES],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function legacyActiveIdempotencyLookupOptions(): array
    {
        return [
            'sort' => [
                'createdAt' => 1,
            ],
        ];
    }

    private function syncLifecycleFields(): void
    {
        $this->pool = $this->normalizePool($this->pool);
        $this->lane = $this->normalizeLane($this->lane);
        $fairnessKey = trim((string)($this->fairness_key ?? ''));
        $this->fairness_key = $fairnessKey !== '' ? $fairnessKey : null;
        $idempotencyKey = trim((string)($this->idempotency_key ?? ''));
        if ($idempotencyKey !== '' && self::isActiveStatus($this->status)) {
            $this->active_idempotency_key = $idempotencyKey;
        } else {
            $this->active_idempotency_key = null;
        }

        if (self::isTerminalStatus($this->status)) {
            $this->terminalAt = $this->terminalAt ?? new \MongoDB\BSON\UTCDateTime();
            return;
        }

        $this->terminalAt = null;
    }

    private function normalizePool(?string $pool): string
    {
        $normalized = strtolower(trim((string)$pool));
        return $normalized !== '' ? $normalized : 'general';
    }

    private function normalizeLane(?string $lane): ?string
    {
        $normalized = strtolower(trim((string)$lane));
        return in_array($normalized, ['realtime', 'backfill'], true) ? $normalized : null;
    }

    private static function isTerminalStatus(?string $status): bool
    {
        $status = trim((string)$status);

        return in_array($status, ['completed', 'auto_resolved', 'manually_resolved', 'failed', 'disabled'], true);
    }
}
