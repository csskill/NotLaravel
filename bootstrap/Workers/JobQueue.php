<?php

namespace Nraa\Workers;

use Nraa\Workers\Documents\JobDocument;
use Nraa\Workers\JobRetryStrategy;
use MongoDB\Collection;

class JobQueue
{

    private Collection $collection;

    public function __construct()
    {
        $db = new \Nraa\Database\Drivers\MongoDBDriver();
        $this->collection = $db->getCollection('jobs');
    }

    /**
     * Atomically fetch and update a queued job for the given worker.
     * This method will return the next job assigned to the given worker, or null if none is found.
     * The job will be marked as 'in_progress' and its 'startedAt' attribute will be set to the current time.
     *
     * @param string $workerId The ID of the worker to fetch the job for.
     * @return JobDocument|null The next job assigned to the worker, or null if none is found.
     */
    public function getNextJob($workerId)
    {
        // Use Model's instance method for atomic findOneAndUpdate
        // This ensures consistency with the Model system and proper type mapping
        $instance = new JobDocument();

        $filter = [
            'status'   => 'assigned',
            'assignee' => $workerId,
        ];

        try {
            // Use Model's findOneAndUpdate (from trait) for atomic operation
            $result = $instance->findOneAndUpdate(
                $filter,
                ['$set' => ['status' => 'in_progress', 'startedAt' => new \MongoDB\BSON\UTCDateTime()]],
                [
                    'sort' => ['createdAt' => 1],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                ]
            );

            if (!$result) {
                return null;
            }

            // Extract the ID from the result
            $id = null;
            if (is_array($result) && isset($result['_id'])) {
                $id = $result['_id'];
            } elseif ($result instanceof \MongoDB\Model\BSONDocument && isset($result['_id'])) {
                $id = $result['_id'];
            } elseif ($result instanceof \stdClass && isset($result->_id)) {
                $id = $result->_id;
            } elseif ($result instanceof JobDocument) {
                // If typeMap auto-hydrated, return it directly
                return $result;
            }

            if (!$id) {
                return null;
            }

            // Ensure ID is an ObjectId instance
            if (!($id instanceof \MongoDB\BSON\ObjectId)) {
                if (is_array($id)) {
                    $id = new \MongoDB\BSON\ObjectId($id['$oid'] ?? $id);
                } else {
                    $id = new \MongoDB\BSON\ObjectId((string)$id);
                }
            }

            // Use Model's findOne() for proper hydration
            $job = JobDocument::findOne(['_id' => $id]);

            if (!$job) {
                $idString = (string)$id;
                echo "[" . date('H:i:s') . "] ⚠️ Worker {$workerId}: Job {$idString} found by findOneAndUpdate but not found by findOne!\n";
                return null;
            }

            return $job;
        } catch (\Throwable $e) {
            echo "[" . date('H:i:s') . "] ❌ Worker {$workerId}: Error in getNextJob: {$e->getMessage()}\n";
            echo "{$e->getTraceAsString()}\n";
            return null;
        }
    }

    /**
     * Enqueue a new job.
     *
     * @param array|object $jobData The job data to enqueue.
     * @param bool $preventDuplicates If true, check for existing jobs before enqueuing
     * @return JobDocument The enqueued job document.
     */
    public function enqueue(array|object $jobData, bool $preventDuplicates = true): JobDocument
    {
        // Check for duplicate jobs if idempotency_key is provided and preventDuplicates is true
        if ($preventDuplicates && isset($jobData['idempotency_key'])) {
            $existingJob = JobDocument::findOne([
                'idempotency_key' => $jobData['idempotency_key'],
                'status' => ['$in' => ['pending', 'assigned', 'in_progress']]
            ]);

            if ($existingJob) {
                return $existingJob;
            }
        }

        return JobDocument::create($jobData);
    }

    /**
     * Fetch all jobs from the database.
     *
     * @return iterable The iterable list of JobDocument objects.
     */
    public function fetchAll(): iterable
    {
        return JobDocument::all();
    }

    /**
     * Fetch all pending jobs from the database.
     *
     * @param int $limit The limit of jobs to fetch. Defaults to 10.
     * @return iterable The iterable list of JobDocument objects.
     */
    public function fetchPending(int $limit = 10): iterable
    {
        $now = new \DateTimeImmutable();

        return JobDocument::find(
            [
                'status' => 'pending',
                'nextRunAt' => ['$lte' => new \MongoDB\BSON\UTCDateTime($now)],
            ],
            [
                'limit' => $limit,
                'sort' => ['createdAt' => 1],
            ]
        )->toArray();
    }

    /**
     * Mark a job as assigned to a given worker.
     *
     * @param string $jobId The ID of the job to mark as assigned.
     * @param string $workerId The ID of the worker to assign the job to.
     */
    public function markAssigned(string $jobId, string $workerId): void
    {
        $instance = new JobDocument();

        // Use atomic findOneAndUpdate to prevent race conditions
        // Only update if status is still 'pending' (not already assigned)
        $filter = [
            '_id' => new \MongoDB\BSON\ObjectId($jobId),
            'status' => 'pending'  // Only assign if still pending
        ];

        $update = [
            '$set' => [
                'status' => 'assigned',
                'assignee' => $workerId,
                'assignedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable())
            ]
        ];

        try {
            echo "[" . date('H:i:s') . "] Marking job (" . $jobId . ") assigned to " . $workerId . " \n";

            $result = $instance->findOneAndUpdate(
                $filter,
                $update,
                [
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                ]
            );

            if (!$result) {
                // Job not found or already assigned - verify current state
                $verify = JobDocument::findOne(['_id' => new \MongoDB\BSON\ObjectId($jobId)]);
                if ($verify) {
                    if ($verify->status === 'assigned' || $verify->status === 'in_progress') {
                        echo "[" . date('H:i:s') . "] ⚠️  Job {$jobId} already assigned to " . ($verify->assignee ?? 'unknown') . " (status: {$verify->status})\n";
                    } else {
                        echo "[" . date('H:i:s') . "] ❌ Job {$jobId} assignment failed - unexpected status: {$verify->status}\n";
                    }
                } else {
                    echo "[" . date('H:i:s') . "] ❌ Job {$jobId} not found\n";
                }
                return;
            }

            // Success - verify the assignment
            $verify = JobDocument::findOne(['_id' => new \MongoDB\BSON\ObjectId($jobId)]);
            if ($verify && $verify->assignee !== $workerId) {
                echo "[" . date('H:i:s') . "] ⚠️ WARNING: Job {$jobId} assignee not saved correctly! Expected: {$workerId}, Got: " . ($verify->assignee ?? 'null') . "\n";
            } else {
                echo "[" . date('H:i:s') . "] ✓ Job {$jobId} successfully assigned to {$workerId}\n";
            }
        } catch (\Exception $e) {
            echo "[" . date('H:i:s') . "] ❌ Error saving job assignment: {$e->getMessage()}\n";
            echo "{$e->getTraceAsString()}\n";
        }
    }


    /**
     * Mark a job as completed.
     *
     * This method will mark the job with the given ID as completed and remove any transient state.
     * If the job does not exist, this method will do nothing.
     *
     * @param string $jobId The ID of the job to mark as completed.
     */
    public function markCompleted(string $jobId): void
    {
        try {
            $job = JobDocument::findOne(['_id' => new \MongoDB\BSON\ObjectId($jobId)]);
            if (!$job) {
                return;
            }

            $job->status = 'completed';
            $job->completedAt = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());

            // clear transient state
            $job->assignee = null;
            $job->error = null;

            $job->save();

            echo "✅ Job {$job->id} marked as completed\n";
        } catch (\Exception $e) {
            echo "❌ Job {$jobId} failed: {$e->getMessage()}\n";
            echo "{$e->getTraceAsString()}\n";
        }
    }


    /**
     * Mark a job as in_progress.
     *
     * This method will mark the job with the given ID as in_progress and update the completedAt field.
     * If the job does not exist, this method will do nothing.
     *
     * @param string $jobId The ID of the job to mark as in_progress.
     */
    public function markInProgress(string $jobId): void
    {
        $job = JobDocument::findOne(['_id' => new \MongoDB\BSON\ObjectId($jobId)]);
        if ($job) {
            $job->status      = 'in_progress';
            echo "[" . date('H:i:s') . "] job ({$jobId}) as in_progress \n";
            $job->completedAt = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());
            $job->save();
        }
    }

    /**
     * Set the priority of a job.
     *
     * This method sets the priority of the job with the given ID.
     * If the job does not exist, this method will do nothing.
     *
     * @param string $jobId The ID of the job to set the priority of.
     * @param int    $priority The new priority of the job.
     */
    public function setPriority(string $jobId, int $priority): void
    {
        $job = JobDocument::findOne(['_id' => new \MongoDB\BSON\ObjectId($jobId)]);
        if ($job) {
            $job->priority = $priority;
            $job->save();
        }
    }

    /**
     * Fetch jobs assigned to a given worker.
     *
     * @param string $workerId
     * @param int    $limit
     * @return iterable
     */
    public function fetchAssigned(string $workerId, int $limit = 10): iterable
    {
        $jobs = JobDocument::find([
            'status'   => 'assigned',
            'assignee' => $workerId,
        ])->toArray();

        $i = 0;
        foreach ($jobs as $job) {
            if ($i >= $limit) break;
            yield $job;
            $i++;
        }
    }

    /**
     * Mark a job as failed.
     *
     * This method marks a job as failed and increases its attempt count.
     * If the job has not exceeded the maximum number of attempts, it will be rescheduled to run again after a fixed delay.
     * Uses unified retry strategy: 30s, 60s, 120s delays.
     *
     * If the job has exceeded the maximum number of attempts, it will be marked as permanently failed.
     *
     * @param string $jobId The ID of the job to mark as failed.
     * @param string $errorMessage The error message to store with the job.
     */
    public function markFailed(string $jobId, string $errorMessage): void
    {
        $job = JobDocument::findOne(['_id' => new \MongoDB\BSON\ObjectId($jobId)]);
        if (!$job) {
            return;
        }

        $job->attempts = ($job->attempts ?? 0) + 1;
        $maxAttempts = $job->maxAttempts ?? 3;
        $job->error = $errorMessage;
        $job->failedAt = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());

        if (JobRetryStrategy::shouldRetry($job->attempts, $maxAttempts)) {
            // Use unified retry strategy with fixed delays
            $delaySeconds = JobRetryStrategy::getDelay($job->attempts);
            $job->nextRunAt = new \MongoDB\BSON\UTCDateTime((new \DateTimeImmutable())->modify("+{$delaySeconds} seconds"));

            $job->status = 'pending';
            $job->assignee = null;
            echo "♻️ Retrying job {$job->id} in {$delaySeconds}s (attempt {$job->attempts}/{$maxAttempts})\n";
        } else {
            $job->status = 'failed';
            echo "❌ Job {$job->id} permanently failed after {$job->attempts} attempts (max: {$maxAttempts})\n";
        }

        $job->save();
    }
}
