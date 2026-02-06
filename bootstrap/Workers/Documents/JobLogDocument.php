<?php

namespace Nraa\Workers\Documents;

use Nraa\Database\Model;

/**
 * Job Log Document
 * 
 * Stores structured job logs in MongoDB for centralized monitoring.
 */
final class JobLogDocument extends Model
{
    protected static $collection = 'job_logs';

    // Public properties for log data
    public ?string $jobId = null;
    public ?string $workerId = null;
    public ?string $pool = null;
    public string $level = 'info'; // debug, info, warning, error
    public string $message = '';
    public ?\MongoDB\BSON\UTCDateTime $timestamp = null;
    public array $metadata = [];

    /**
     * Create and save a log entry
     * 
     * @param array $data Log data
     * @return self Created log document
     */
    public static function log(array $data): self
    {
        $log = parent::create(array_merge([
            'timestamp' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
            'level' => 'info',
            'metadata' => [],
        ], $data));

        $log->save();
        return $log;
    }
}
