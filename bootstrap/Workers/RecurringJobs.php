<?php

namespace Nraa\Workers;

use MongoDB\BSON\Binary;
use Nraa\Workers\Documents\RecurringJobDocument;
use Cron\CronExpression;

class RecurringJobs
{
    /**
     * Track the last time we checked recurring jobs to ensure we only check once per minute
     * even if expandDueJobs is called more frequently
     * 
     * @var \DateTimeImmutable|null
     */
    private static ?\DateTimeImmutable $lastRecurringCheck = null;

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
        // Normalize jobCommand for comparison (convert to string representation)
        // jobCommand is typically [ClassName::class, 'methodName']
        $jobIdentifier = $this->getJobIdentifier($jobData);
        
        // Check if a recurring job with this identifier already exists
        $existingJobs = RecurringJobDocument::all();
        $existingJob = null;
        
        foreach ($existingJobs as $job) {
            $existingJobCommand = $job->jobCommand ?? [];
            
            // Convert BSON array/object to PHP array if needed
            if (is_object($existingJobCommand)) {
                $existingJobCommand = json_decode(json_encode($existingJobCommand), true);
            }
            
            if (!is_array($existingJobCommand)) {
                continue;
            }
            
            $existingIdentifier = $this->getJobIdentifier($existingJobCommand);
            if ($existingIdentifier === $jobIdentifier && !empty($existingIdentifier)) {
                $existingJob = $job;
                break;
            }
        }
        
        if ($existingJob) {
            // Update existing job's cron expression
            $existingJob->cron = $cronExpr;
            $existingJob->save();
            return $existingJob;
        }
        
        // Create new job if it doesn't exist
        return RecurringJobDocument::create([
            'jobCommand'  => $jobData,
            'cron' => $cronExpr,
            'lastRun' => null,
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
        // Rate limit: Only check recurring jobs once per minute
        // This prevents issues with cron expression evaluation when called more frequently
        if (self::$lastRecurringCheck !== null) {
            $secondsSinceLastCheck = $now->getTimestamp() - self::$lastRecurringCheck->getTimestamp();
            if ($secondsSinceLastCheck < 60) {
                // Less than 60 seconds since last check, skip this run
                return [];
            }
        }
        
        // Update last check time
        self::$lastRecurringCheck = $now;

        $dueJobs = [];
        $recurringJobs = RecurringJobDocument::all();
        $recJobsArray = iterator_to_array($recurringJobs);

        foreach ($recJobsArray as $recJob) {
            $cronString = $recJob->cron ?? null;
            if (!empty($cronString)) {
                $cron = new \Cron\CronExpression($cronString);
                if ($cron->isDue($now)) {
                    echo "[" . date('H:i:s') . "] Current recurring job ($recJob->id) should run. Cron: $cron \r\n";

                    // Create a new Job for this run of the recurring job
                    // The JobQueue's idempotency_key prevents duplicate jobs if multiple workers run this
                    $jobRegistrar = new JobRegistrar();
                    $job = $jobRegistrar->registerJob(
                        (array) $recJob->jobCommand,
                        [],
                        null,
                        'RecurringJob (' . $recJob->id . ')',
                        true  // preventDuplicates = true (uses idempotency_key)
                    );
                    $dueJobs[] = $job;

                    // Update lastRun (multiple workers may do this, but it's harmless)
                    $recJob->lastRun = new \MongoDB\BSON\UTCDateTime($now);
                    $recJob->save();
                }
            }
        }

        return $dueJobs;
    }
}
