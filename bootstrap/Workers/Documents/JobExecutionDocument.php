<?php

namespace Nraa\Workers\Documents;

use Nraa\Database\Model;

final class JobExecutionDocument extends Model
{
    protected static $collection = 'job_executions';

    // Public properties for execution data
    public ?string $jobId = null;
    public ?string $workerId = null;
    public ?string $employer = null;
    public ?\MongoDB\BSON\UTCDateTime $startedAt = null;
    public ?\MongoDB\BSON\UTCDateTime $finishedAt = null;
    public ?string $execution_time = null;
    public ?string $status = null;
    public $result = null;
    public ?string $error = null;
    public ?int $attempt = null;
    public ?int $attempts = null;
    public ?int $nextRetryDelay = null;

    public static function log(array $data): self
    {
        $execution = parent::create(array_merge([
            'createdAt' => new \MongoDB\BSON\UTCDateTime(new \DateTime()),
        ], (array) $data));

        $execution->save();
        return $execution;
    }
}
