<?php

namespace Nraa\Workers;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Nraa\Database\Drivers\MongoDBDriver;
use Nraa\Workers\Documents\JobDocument;

class JobTypeControlService
{
    private const CONTROL_DOCUMENT_ID = 'global';
    private const CACHE_TTL_SECONDS_DEFAULT = 5;

    public const JOB_STATUS_DISABLED = 'disabled';
    public const SCHEDULED_STATUS_SKIPPED_DISABLED = 'skipped_disabled';

    private static int $cacheLoadedAt = 0;

    /**
     * @var array<string, bool>
     */
    private static array $cachedDisabledJobTypeMap = [];

    private Collection $controlsCollection;
    private Collection $jobsCollection;
    private Collection $scheduledJobsCollection;

    public function __construct(
        ?Collection $controlsCollection = null,
        ?Collection $jobsCollection = null,
        ?Collection $scheduledJobsCollection = null
    ) {
        $db = MongoDBDriver::getInstance();
        $this->controlsCollection = $controlsCollection ?? $db->getCollection('job_type_controls');
        $this->jobsCollection = $jobsCollection ?? $db->getCollection('jobs');
        $this->scheduledJobsCollection = $scheduledJobsCollection ?? $db->getCollection('scheduled_jobs');
    }

    /**
     * @return array<int, string>
     */
    public function getDisabledJobTypes(bool $forceRefresh = false): array
    {
        return array_keys($this->getDisabledJobTypeMap($forceRefresh));
    }

    public function isJobTypeDisabled(string $jobClass): bool
    {
        $jobClass = trim($jobClass);
        if ($jobClass === '') {
            return false;
        }

        return isset($this->getDisabledJobTypeMap()[$jobClass]);
    }

    /**
     * @param array|object $jobData
     */
    public function isJobDataDisabled(array|object $jobData): bool
    {
        return $this->isJobTypeDisabled(self::extractJobClassFromJobData($jobData));
    }

    /**
     * @return array{disabled_jobs:int, skipped_scheduled_jobs:int}
     */
    public function disableJobType(string $jobClass): array
    {
        $jobClass = trim($jobClass);
        if ($jobClass === '') {
            throw new \InvalidArgumentException('Job class is required.');
        }

        $now = new UTCDateTime(new \DateTimeImmutable());
        $this->controlsCollection->updateOne(
            ['_id' => self::CONTROL_DOCUMENT_ID],
            [
                '$addToSet' => ['disabled_job_types' => $jobClass],
                '$set' => ['updatedAt' => $now],
            ],
            ['upsert' => true]
        );

        $this->rememberDisabledJobType($jobClass);

        return $this->suppressQueuedJobsForType($jobClass, $now);
    }

    public function enableJobType(string $jobClass): void
    {
        $jobClass = trim($jobClass);
        if ($jobClass === '') {
            throw new \InvalidArgumentException('Job class is required.');
        }

        $this->controlsCollection->updateOne(
            ['_id' => self::CONTROL_DOCUMENT_ID],
            [
                '$pull' => ['disabled_job_types' => $jobClass],
                '$set' => ['updatedAt' => new UTCDateTime(new \DateTimeImmutable())],
            ],
            ['upsert' => true]
        );

        $this->forgetDisabledJobType($jobClass);
    }

    public function markJobDocumentDisabled(JobDocument $job): void
    {
        $job->status = self::JOB_STATUS_DISABLED;
        $job->assignee = null;
        $job->error = null;
        $job->startedAt = null;
        $job->failedAt = null;
        $job->assignedAt = null;
        $job->lastHeartbeat = null;
        $job->nextRunAt = null;
        $job->save();
    }

    /**
     * @param array|object|null $task
     */
    public static function extractJobClassFromTask(array|object|null $task): string
    {
        if ($task instanceof \MongoDB\Model\BSONDocument || $task instanceof \MongoDB\Model\BSONArray) {
            $task = $task->getArrayCopy();
        } elseif ($task instanceof \stdClass) {
            $task = (array)$task;
        }

        if (!is_array($task)) {
            return '';
        }

        return trim((string)($task['class'] ?? ''));
    }

    /**
     * @param array|object $jobData
     */
    public static function extractJobClassFromJobData(array|object $jobData): string
    {
        if ($jobData instanceof \MongoDB\Model\BSONDocument || $jobData instanceof \MongoDB\Model\BSONArray) {
            $jobData = $jobData->getArrayCopy();
        } elseif ($jobData instanceof \stdClass) {
            $jobData = (array)$jobData;
        }

        if (!is_array($jobData)) {
            return '';
        }

        return self::extractJobClassFromTask($jobData['task'] ?? null);
    }

    /**
     * @return array{disabled_jobs:int, skipped_scheduled_jobs:int}
     */
    private function suppressQueuedJobsForType(string $jobClass, UTCDateTime $now): array
    {
        $disabledJobs = $this->jobsCollection->updateMany(
            [
                'task.class' => $jobClass,
                'status' => ['$in' => ['pending', 'assigned']],
            ],
            [
                '$set' => [
                    'status' => self::JOB_STATUS_DISABLED,
                    'assignee' => null,
                    'assignedAt' => null,
                    'startedAt' => null,
                    'lastHeartbeat' => null,
                    'nextRunAt' => null,
                    'error' => null,
                    'failedAt' => null,
                    'active_idempotency_key' => null,
                    'updatedAt' => $now,
                ],
            ]
        )->getModifiedCount();

        $skippedScheduledJobs = $this->scheduledJobsCollection->updateMany(
            [
                'job.task.class' => $jobClass,
                'status' => 'scheduled',
            ],
            [
                '$set' => [
                    'status' => self::SCHEDULED_STATUS_SKIPPED_DISABLED,
                    'terminalAt' => $now,
                    'updatedAt' => $now,
                ],
            ]
        )->getModifiedCount();

        return [
            'disabled_jobs' => $disabledJobs,
            'skipped_scheduled_jobs' => $skippedScheduledJobs,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function getDisabledJobTypeMap(bool $forceRefresh = false): array
    {
        $now = time();
        if (
            !$forceRefresh
            && self::$cacheLoadedAt > 0
            && ($now - self::$cacheLoadedAt) < $this->getCacheTtlSeconds()
        ) {
            return self::$cachedDisabledJobTypeMap;
        }

        $doc = $this->controlsCollection->findOne(
            ['_id' => self::CONTROL_DOCUMENT_ID],
            ['projection' => ['disabled_job_types' => 1]]
        );

        $map = [];
        if ($doc instanceof \MongoDB\Model\BSONDocument || $doc instanceof \MongoDB\Model\BSONArray) {
            $doc = $doc->getArrayCopy();
        } elseif ($doc instanceof \stdClass) {
            $doc = (array)$doc;
        }

        $rawTypes = [];
        if (is_array($doc)) {
            $rawTypes = $doc['disabled_job_types'] ?? [];
            if ($rawTypes instanceof \MongoDB\Model\BSONArray || $rawTypes instanceof \MongoDB\Model\BSONDocument) {
                $rawTypes = $rawTypes->getArrayCopy();
            }
        }

        if (is_array($rawTypes)) {
            foreach ($rawTypes as $jobType) {
                $jobType = trim((string)$jobType);
                if ($jobType === '') {
                    continue;
                }
                $map[$jobType] = true;
            }
        }

        self::$cachedDisabledJobTypeMap = $map;
        self::$cacheLoadedAt = $now;

        return self::$cachedDisabledJobTypeMap;
    }

    private function rememberDisabledJobType(string $jobClass): void
    {
        self::$cachedDisabledJobTypeMap[$jobClass] = true;
        self::$cacheLoadedAt = time();
    }

    private function forgetDisabledJobType(string $jobClass): void
    {
        unset(self::$cachedDisabledJobTypeMap[$jobClass]);
        self::$cacheLoadedAt = time();
    }

    private function getCacheTtlSeconds(): int
    {
        return max(1, min(300, (int)($_ENV['JOB_TYPE_CONTROL_CACHE_TTL_SECONDS'] ?? self::CACHE_TTL_SECONDS_DEFAULT)));
    }
}
