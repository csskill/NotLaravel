<?php

namespace Nraa\Workers;

use Cron\CronExpression;

class RecurringJob
{
    protected string $id;
    protected string $employer;
    protected string $task;
    protected array $instructions;
    protected string $cronExpression;
    protected CronExpression $cron;
    protected \DateTime $nextRun;

    /**
     * Constructs a new RecurringJob instance.
     *
     * @param string $employer The employer of the job.
     * @param string $task The task to execute.
     * @param array $instructions The instructions for the job.
     * @param string $cronExpression The cron expression to use for scheduling the job.
     */
    public function __construct(string $employer, string $task, array $instructions, string $cronExpression)
    {
        $this->id = uniqid('recjob_', true);
        $this->employer = $employer;
        $this->task = $task;
        $this->instructions = $instructions;
        $this->cronExpression = $cronExpression;
        $this->cron = CronExpression::factory($cronExpression);
        $this->nextRun = $this->cron->getNextRunDate();
    }

    /**
     * Check if the job is due to run at the given datetime.
     *
     * @param \DateTime $now The datetime to check against.
     * @return bool True if the job is due to run, false otherwise.
     */
    public function isDue(\DateTime $now): bool
    {
        return $now >= $this->nextRun;
    }

    /**
     * Gets the ID of the job.
     *
     * @return string The ID of the job.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Materialize a RecurringJob into a Job instance.
     *
     * This method is used to convert a RecurringJob instance into a Job instance.
     * It will create a new Job instance with the same employer, instructions, and task as the RecurringJob instance.
     *
     * @return Job The Job instance materialized from the RecurringJob instance.
     */
    public function materializeJob(): Job
    {
        return new Job($this->employer, $this->instructions, 1, $this->task);
    }

    /**
     * Reschedule the job for the next run.
     *
     * This method updates the job's nextRun attribute to the next run date according to the job's cron expression.
     */
    public function reschedule(): void
    {
        $this->nextRun = $this->cron->getNextRunDate();
    }

    /**
     * Returns the next run date of the job.
     *
     * @return \DateTime The next run date of the job.
     */
    public function getNextRun(): \DateTime
    {
        return $this->nextRun;
    }
}
