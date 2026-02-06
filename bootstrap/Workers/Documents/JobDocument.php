<?php

namespace Nraa\Workers\Documents;

use Nraa\Database\Model;
use Ramsey\Uuid\Provider\Node\StaticNodeProvider;

final class JobDocument extends Model
{
    protected static $collection = 'jobs';

    // Public properties for job data
    public string $status = 'pending';
    public array $task = [];
    public array $instructions = [];
    public ?string $assignee = null;
    public ?string $employer = null;
    public int $priority = 1;
    public ?string $pool = null;
    public ?int $workerLimit = null;
    public ?string $idempotency_key = null;
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
        $this->assignee = null;
        $this->error = null;
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
        $this->error = $error;
        $this->save();
            return true;
    }

    public static function create(object|array $data): self
    {
        $dataArr = array_merge([
            'status' => 'pending'
        ], (array) $data);

        $job = parent::create($dataArr);
        $job->nextRunAt = $job->nextRunAt ?? $job->createdAt;
        if ($job->nextRunAt === null) {
            $job->nextRunAt = new \MongoDB\BSON\UTCDateTime();
        }
        $job->save();
        return $job;
    }
}
