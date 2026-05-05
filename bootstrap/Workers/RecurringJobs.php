<?php

namespace Nraa\Workers;

use Nraa\Workers\Documents\RecurringJobDocument;
use Cron\CronExpression;
use MongoDB\BSON\UTCDateTime;
use Nraa\Pillars\Log;

class RecurringJobs
{
    /**
     * Track the last time we checked recurring jobs to ensure we only check once per minute
     * even if expandDueJobs is called more frequently
     * 
     * @var \DateTimeImmutable|null
     */
    private static ?string $lastRecurringCheckMinuteKey = null;

    /**
     * Registers a new recurring job.
     * If a job with the same jobCommand already exists, updates its cron expression instead of creating a duplicate.
     *
     * @param array $jobData An array containing the class and method to call, as well as any parameters to pass to the method.
     * @param string $cronExpr The cron expression to use for scheduling the job.
     *
     * @return RecurringJobDocument The registered or updated recurring job document.
     */
    public function register(array $jobData, string $cronExpr): RecurringJobDocument
    {
        $normalizedJobData = $this->normalizeJobCommand($jobData);
        $jobIdentifier = $this->getJobIdentifier($normalizedJobData);

        $existingJobs = iterator_to_array(RecurringJobDocument::all());
        $matching = [];

        foreach ($existingJobs as $job) {
            $existingIdentifier = $this->extractIdentifierFromDocument($job);
            if ($existingIdentifier === $jobIdentifier && $existingIdentifier !== '') {
                $matching[] = $job;
            }
        }

        if (!empty($matching)) {
            $primary = $this->chooseCanonicalRecurringJob($matching);
            $primary->cron = $cronExpr;
            $primary->jobCommand = $normalizedJobData;
            $primary->identifier = $jobIdentifier !== '' ? $jobIdentifier : null;
            $primary->save();

            foreach ($matching as $candidate) {
                if ((string)$candidate->id === (string)$primary->id) {
                    continue;
                }
                $candidate->delete();
            }

            return $primary;
        }

        return RecurringJobDocument::create([
            'jobCommand' => $normalizedJobData,
            'cron' => $cronExpr,
            'lastRun' => null,
            'identifier' => $jobIdentifier !== '' ? $jobIdentifier : null,
        ]);
    }

    /**
     * Generate a unique identifier for a job command
     * Used to detect duplicate jobs
     * 
     * @param array $jobData Job command array [ClassName::class, 'methodName']
     * @return string Unique identifier
     */
    private function getJobIdentifier(array $jobData): string
    {
        if (empty($jobData)) {
            return '';
        }

        // Extract class and method
        $className = is_string($jobData[0] ?? null) ? $jobData[0] : (is_object($jobData[0] ?? null) ? get_class($jobData[0]) : '');
        $methodName = $jobData[1] ?? '';

        // Normalize class name (handle ::class constants)
        if (strpos($className, '::class') !== false) {
            $className = str_replace('::class', '', $className);
        }

        return $className . '::' . $methodName;
    }

    /**
     * Normalize job command arrays/documents to plain PHP arrays.
     *
     * @param mixed $jobData
     * @return array
     */
    private function normalizeJobCommand(mixed $jobData): array
    {
        if (is_array($jobData)) {
            return $jobData;
        }
        if ($jobData instanceof \MongoDB\Model\BSONArray) {
            return $jobData->getArrayCopy();
        }
        if ($jobData instanceof \MongoDB\Model\BSONDocument) {
            return $jobData->getArrayCopy();
        }
        if ($jobData instanceof \stdClass) {
            return (array)$jobData;
        }
        return [];
    }

    private function extractIdentifierFromDocument(RecurringJobDocument $job): string
    {
        $existingIdentifier = trim((string)($job->identifier ?? ''));
        if ($existingIdentifier !== '') {
            return $existingIdentifier;
        }

        return $this->getJobIdentifier(
            $this->normalizeJobCommand($job->jobCommand ?? [])
        );
    }

    /**
     * @param RecurringJobDocument[] $jobs
     */
    private function chooseCanonicalRecurringJob(array $jobs): RecurringJobDocument
    {
        usort($jobs, function (RecurringJobDocument $a, RecurringJobDocument $b): int {
            $updatedA = $this->toEpochSeconds($a->updatedAt ?? null);
            $updatedB = $this->toEpochSeconds($b->updatedAt ?? null);
            if ($updatedA !== $updatedB) {
                return $updatedB <=> $updatedA; // newest first
            }

            $createdA = $this->toEpochSeconds($a->createdAt ?? null);
            $createdB = $this->toEpochSeconds($b->createdAt ?? null);
            if ($createdA !== $createdB) {
                return $createdB <=> $createdA; // newest first
            }

            return strcmp((string)$a->id, (string)$b->id);
        });

        return $jobs[0];
    }

    private function toEpochSeconds(?\MongoDB\BSON\UTCDateTime $value): int
    {
        if (!$value) {
            return 0;
        }
        return (int)floor($value->toDateTime()->getTimestamp());
    }


    /**
     * Expands all recurring jobs that are due to run at the given datetime into individual jobs.
     * Multiple workers may run this simultaneously - duplicate job prevention is handled by
     * the idempotency_key system in JobQueue.
     * 
     * This method is rate-limited to only check recurring jobs once per minute, even if called
     * more frequently. This ensures cron expressions are evaluated correctly.
     *
     * @param \DateTimeImmutable $now The datetime to check against.
     *
     * @return array An array of Job instances that are due to run.
     */
    public function expandDueJobs(\DateTimeImmutable $now): array
    {
        $currentMinuteStart = $this->resolveCurrentMinuteStart($now);
        $currentMinuteKey = $currentMinuteStart->format('Y-m-d H:i');

        // Rate limit: only evaluate recurring definitions once per wall-clock minute
        // inside the current process. This keeps the CronExpression evaluation aligned
        // with cron minute boundaries instead of an arbitrary 60-second offset.
        if (self::$lastRecurringCheckMinuteKey === $currentMinuteKey) {
            return [];
        }
        self::$lastRecurringCheckMinuteKey = $currentMinuteKey;

        $dueJobs = [];
        $recurringJobs = RecurringJobDocument::all();
        $recJobsArray = iterator_to_array($recurringJobs);
        $canonicalJobs = $this->deduplicateRecurringDefinitions($recJobsArray);

        foreach ($canonicalJobs as $recJob) {
            $cronString = $recJob->cron ?? null;
            if (!empty($cronString)) {
                $cron = new CronExpression($cronString);
                if ($cron->isDue($now)) {
                    if ($this->hasRunInCurrentMinute($recJob, $currentMinuteStart)) {
                        continue;
                    }

                    if (!$this->claimRecurringRunForCurrentMinute($recJob, $now, $currentMinuteStart)) {
                        continue;
                    }

                    try {
                        // Create a new Job for this run of the recurring job.
                        // The atomic minute claim above guarantees max once-per-minute
                        // firing even if the dispatcher process restarts mid-minute.
                        $jobRegistrar = new JobRegistrar();
                        $job = $jobRegistrar->registerJob(
                            (array)$recJob->jobCommand,
                            [],
                            null,
                            'RecurringJob (' . $recJob->id . ')',
                            true
                        );
                        if ($job !== null) {
                            $dueJobs[] = $job;
                        }
                    } catch (\Throwable $e) {
                        Log::error('RecurringJobs: failed to enqueue recurring job', [
                            'recurring_job_id' => (string)$recJob->id,
                            'cron' => (string)$cronString,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $dueJobs;
    }

    /**
     * Collapse duplicate recurring definitions by job identifier.
     *
     * @param RecurringJobDocument[] $recJobsArray
     * @return RecurringJobDocument[]
     */
    private function deduplicateRecurringDefinitions(array $recJobsArray): array
    {
        $grouped = [];
        foreach ($recJobsArray as $job) {
            $identifier = $this->extractIdentifierFromDocument($job);
            $groupKey = $identifier !== '' ? $identifier : '__id__' . (string)$job->id;
            $grouped[$groupKey][] = $job;
        }

        $canonical = [];
        foreach ($grouped as $jobs) {
            $primary = $this->chooseCanonicalRecurringJob($jobs);

            $identifier = $this->extractIdentifierFromDocument($primary);
            if ($identifier !== '' && ($primary->identifier ?? null) !== $identifier) {
                $primary->identifier = $identifier;
                $primary->save();
            }

            foreach ($jobs as $candidate) {
                if ((string)$candidate->id === (string)$primary->id) {
                    continue;
                }
                $candidate->delete();
            }

            $canonical[] = $primary;
        }

        return $canonical;
    }

    private function resolveCurrentMinuteStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTime(
            (int)$now->format('H'),
            (int)$now->format('i'),
            0,
            0
        );
    }

    private function hasRunInCurrentMinute(RecurringJobDocument $recJob, \DateTimeImmutable $currentMinuteStart): bool
    {
        $lastRun = $recJob->lastRun ?? null;
        if (!$lastRun instanceof UTCDateTime) {
            return false;
        }

        return $lastRun->toDateTime()->getTimestamp() >= $currentMinuteStart->getTimestamp();
    }

    private function claimRecurringRunForCurrentMinute(
        RecurringJobDocument $recJob,
        \DateTimeImmutable $now,
        \DateTimeImmutable $currentMinuteStart
    ): bool {
        $nowUtc = new UTCDateTime($now);
        $currentMinuteUtc = new UTCDateTime($currentMinuteStart);

        $result = $recJob->getCollection()->findOneAndUpdate(
            [
                '_id' => $recJob->id,
                '$or' => [
                    ['lastRun' => null],
                    ['lastRun' => ['$exists' => false]],
                    ['lastRun' => ['$lt' => $currentMinuteUtc]],
                ],
            ],
            [
                '$set' => [
                    'lastRun' => $currentMinuteUtc,
                    'updatedAt' => $nowUtc,
                ],
            ],
            [
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        return $result !== null;
    }
}
