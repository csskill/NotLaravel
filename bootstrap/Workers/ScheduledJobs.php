<?php

namespace Nraa\Workers;

use Nraa\Workers\Documents\ScheduledJobDocument;

class ScheduledJobs
{
    /**
     * Schedule a new job to run at the given datetime.
     *
     * @param array $jobData The job data to schedule. This should contain the following keys:
     *   - 'task': The callable to run when the job is executed.
     *   - 'instructions': Optional parameters for the callable.
     *   - 'employer': Optional informational string.
     * @param \DateTime|\DateTimeImmutable $at The datetime to schedule the job at.
     * @return ScheduledJobDocument The scheduled job document.
     */
    public function schedule(array $jobData, \DateTime|\DateTimeImmutable $at): ScheduledJobDocument
    {
        return ScheduledJobDocument::create([
            'job' => $jobData,
            'runAt' => new \MongoDB\BSON\UTCDateTime($at),
            'status' => 'scheduled',
        ]);
    }

    /**
     * Get all scheduled jobs from the database.
     *
     * @return iterable The iterable list of ScheduledJobDocument objects.
     */
    public function all()
    {
        return ScheduledJobDocument::all();
    }

    /**
     * Fetch all scheduled jobs that are due to run at or before the given datetime.
     *
     * @param \DateTime|\DateTimeImmutable $now The datetime to check against.
     * @return iterable The iterable list of ScheduledJobDocument objects that are due to run.
     */
    public function fetchDueJobs(\DateTime|\DateTimeImmutable $now): iterable
    {
        $results = ScheduledJobDocument::find([
            'runAt' => ['$lte' => new \MongoDB\BSON\UTCDateTime($now)],
            'status' => 'scheduled',
        ])->toArray();
        
        foreach ($results as $result) {
            yield $result;
        }
    }
}
