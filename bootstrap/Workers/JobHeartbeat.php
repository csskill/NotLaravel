<?php

namespace Nraa\Workers;

use Nraa\Workers\Documents\JobDocument;

/**
 * Job Heartbeat
 * 
 * Manages heartbeat updates for in-progress jobs to detect stale jobs.
 * Heartbeats are updated periodically to indicate a job is still running.
 */
class JobHeartbeat
{
    /**
     * Update heartbeat for a job
     * 
     * Atomically updates the lastHeartbeat timestamp for the given job.
     * This should be called periodically (every 10 seconds) while a job is running.
     * 
     * @param string $jobId Job ID
     * @return bool True if update was successful
     */
    public static function update(string $jobId): bool
    {
        try {
            $job = JobDocument::findOne(['_id' => new \MongoDB\BSON\ObjectId($jobId)]);
            
            if (!$job) {
                return false;
            }

            // Only update heartbeat for in-progress jobs
            if ($job->status !== 'in_progress') {
                return false;
            }

            // Update heartbeat timestamp
            $job->lastHeartbeat = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());
            $job->save();

            return true;
        } catch (\Throwable $e) {
            // Don't let heartbeat failures break job execution
            error_log("Failed to update heartbeat for job {$jobId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get heartbeat interval from environment
     * 
     * @return int Heartbeat interval in seconds
     */
    public static function getInterval(): int
    {
        return (int)($_ENV['HEARTBEAT_INTERVAL'] ?? 10);
    }

    /**
     * Get stale job threshold from environment
     * 
     * @return int Stale threshold in seconds (jobs without heartbeat for this long are considered stale)
     */
    public static function getStaleThreshold(): int
    {
        return (int)($_ENV['STALE_JOB_THRESHOLD'] ?? 60); // Default: 60 seconds (6 missed heartbeats)
    }
}
