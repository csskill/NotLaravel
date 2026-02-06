<?php

namespace Nraa\Workers;

use React\EventLoop\Loop;

class JobScheduler
{
    protected JobQueue $queue;
    protected ScheduledJobs $scheduledJobs;
    protected RecurringJobs $recurringJobs;

    /**
     * Construct a JobScheduler instance.
     *
     * @param JobQueue $queue The JobQueue to distribute jobs to
     * @param ScheduledJobs $scheduled The ScheduledJobs instance to fetch scheduled jobs from
     * @param RecurringJobs $recurring The RecurringJobs instance to fetch recurring jobs from
     */
    public function __construct(JobQueue $queue, ScheduledJobs $scheduled, RecurringJobs $recurring)
    {
        $this->queue = $queue;
        $this->scheduledJobs = $scheduled;
        $this->recurringJobs = $recurring;
    }
}
