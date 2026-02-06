<?php

namespace Nraa\Workers;

#[\AllowDynamicProperties]
class Job
{
    protected string $id;
    protected string $employer;
    protected array $instructions = [];
    protected int $priority;
    protected string $task; // format: Class::method
    protected string $status; // in_queue, in_progress, completed, failed
    protected ?Worker $assignee = null;
    protected \DateTime $createdAt;

    /**
     * Constructs a new Job instance.
     *
     * @param string $employer The informational string describing the job creator.
     * @param array $instructions The instructions for the job to execute.
     * @param int $priority The priority of the job; higher values mean higher priority.
     * @param string $task The task to execute, formatted as Class::method.
     */
    public function __construct(string $employer, array $instructions, int $priority, string $task)
    {
        $this->id = uniqid('job_', true);
        $this->employer = $employer;
        $this->instructions = $instructions;
        $this->priority = $priority;
        $this->task = $task;
        $this->status = 'in_queue';
        $this->createdAt = new \DateTime();
    }

    /**
     * Constructs a Job instance from a MongoDB document.
     *
     * @param array $doc The MongoDB document to convert into a Job instance.
     * @return Job The Job instance constructed from the document.
     */
    public static function fromDocument($doc)
    {
        $obj = new static('', [], 0, '');
        foreach ((array)$doc as $key => $value) {
            if ($key === '_id') {
                $obj->id = $value;
                continue;
            }
            $obj->$key = $value;
        }
        return $obj;
    }

    /**
     * Assigns the job to a given worker.
     *
     * Changes the job status to 'in_progress' and sets the assignee to the given worker.
     *
     * @param Worker $worker The worker to assign the job to.
     */
    public function assignTo(Worker $worker): void
    {
        $this->assignee = $worker;
        $this->status = 'in_progress';
    }

    /**
     * Sets the job status to 'completed'.
     *
     * This method can be used to indicate that the job has been successfully completed.
     */
    public function complete(): void
    {
        $this->status = 'completed';
    }

    /**
     * Sets the job status to 'in_progress'.
     *
     * This method can be used to indicate that the job is currently being processed.
     */
    public function inProgress(): void
    {
        $this->status = 'in_progress';
    }

    /**
     * Sets the job status to 'failed'.
     *
     * This method can be used to indicate that the job has failed.
     */
    public function fail(): void
    {
        $this->status = 'failed';
    }

    /**
     * Gets the ID of the job.
     *
     * @return string The ID of the job.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gets the employer of the job.
     *
     * This method returns the employer of the job as a string.
     *
     * @return string The employer of the job.
     */
    public function getEmployer(): string
    {
        return $this->employer;
    }

    /**
     * Returns the instructions for the job.
     *
     * This method returns the instructions for the job as an array.
     *
     * @return array The instructions for the job.
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    /**
     * Returns the priority of the job.
     *
     * This method returns the priority of the job as an integer.
     * Higher values mean higher priority.
     *
     * @return int The priority of the job.
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Returns the task to execute, formatted as Class::method.
     *
     * @return string The task to execute.
     */
    public function getTask(): string
    {
        return $this->task;
    }

    /**
     * Returns the status of the job.
     *
     * The status can be one of 'in_queue', 'in_progress', or 'completed'.
     *
     * @return string The status of the job.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Returns the worker assigned to the job, or null if not assigned.
     *
     * @return ?Worker The worker assigned to the job, or null if not assigned.
     */
    public function getAssignee(): ?Worker
    {
        return $this->assignee;
    }
}
