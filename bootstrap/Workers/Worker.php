<?php

namespace Nraa\Workers;

use React\EventLoop\Loop;
use Nraa\Workers\Documents\JobExecutionDocument;
use Symfony\Component\Process\Process;

class Worker
{
    protected string $id;
    protected int $capacity;
    protected array $jobs = [];
    protected $pid = null;
    protected ?Process $process = null;
    protected string $pool; // Pool name (download, parse, calculation, general)
    protected array $config = []; // Optional configuration data for this worker

    /**
     * Constructs a new Worker instance.
     *
     * @param string $id The unique identifier for the Worker (e.g., 'download-0')
     * @param int $capacity The maximum number of jobs the Worker can handle
     * @param string $pool Pool name this worker belongs to (download, parse, calculation, general)
     * @param array $config Optional configuration data for this worker (e.g., credentials, endpoints, etc.)
     */
    public function __construct(string $id, int $capacity, string $pool = 'general', array $config = [])
    {
        $this->id = $id;
        $this->capacity = $capacity;
        $this->pool = $pool;
        $this->config = $config;
    }

    /**
     * Gets the unique identifier for the Worker.
     *
     * @return string The unique identifier for the Worker.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gets the pool this worker belongs to.
     *
     * @return string Pool name (download, parse, calculation, general)
     */
    public function getPool(): string
    {
        return $this->pool;
    }

    /**
     * Gets the configuration data for this worker.
     *
     * @return array Configuration data (may be empty)
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Gets a specific configuration value.
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Configuration value or default
     */
    public function getConfigValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Gets the number of jobs the Worker can still handle without exceeding its capacity.
     *
     * @return int The number of jobs the Worker can still handle without exceeding its capacity.
     */
    public function getFreeCapacity(): int
    {
        return $this->capacity - count($this->jobs);
    }


    /**
     * Gets the Process instance for the Worker, or null if the Worker has not yet spawned a process.
     *
     * @return ?Process The Process instance for the Worker, or null if the Worker has not yet spawned a process.
     */
    public function getProcess(): ?Process
    {
        return $this->process;
    }

    /**
     * Starts the Worker's work process by spawning a new process.
     *
     * This method will spawn a new process that will execute the job runner command with the given Worker ID.
     * The process will be responsible for executing jobs assigned to the Worker.
     */
    public function startWorkProcess(): void
    {
        $this->spawnProcess();
    }


    /**
     * Spawns a new process to execute the job runner command with the given Worker ID.
     *
     * This method will start a new process that will execute the job runner command with the given Worker ID.
     * The process will be responsible for executing jobs assigned to the Worker.
     * If the process dies, it will be restarted.
     */
    protected function spawnProcess(): void
    {
        $this->process = new Process([
            'php',
            'nraa',
            'app:job-runner',
            $this->id
        ]);

        $this->process->setTimeout(null);
        $this->process->setIdleTimeout(null);

        $workerId = $this->id;

        echo "✅ Spawned {$workerId}\n";

        $this->process->start();

        $this->process->wait(function ($type, $buffer) use ($workerId) {
            if ($type === Process::OUT) {
                echo "[{$workerId}][OUT] " . $buffer;
            } else {
                echo "[{$workerId}][ERR] " . $buffer;
            }
        });

        if (!$this->process->isRunning()) {
            echo "❌ {$workerId} died, restarting...\n";
            $this->spawnProcess();
        }
    }

    /**
     * Starts the Worker's work process by executing all assigned jobs.
     *
     * This method will execute all assigned jobs by creating a new JobExecution instance for each job.
     * The JobExecution instance will execute the job and return a promise that will be resolved or rejected by the job execution process.
     * The promise will be resolved with the result of the job or rejected with an exception if the job fails.
     * Once all jobs have been executed, the Worker's jobs array will be cleared.
     */
    public function startWork(): void
    {
        foreach ($this->jobs as $job) {
            echo "Running job {$job->id} for {$job->employer}\n";
            $execution = new JobExecution($this, $job);
            $deferred  = $execution->getDeferred();

            $promise = $deferred->promise();
            $promise->then(
                function ($exec) use ($job) {
                    echo "✅ Job {$job->id} done by {$this->id}\n";
                },
                function ($err) use ($job) {
                    echo "❌ Job {$job->id} failed: {$err->getMessage()}\n";
                    echo "{$err->getTraceAsString()}\n";
                }
            );
            Loop::addPeriodicTimer(1, function () use ($deferred, $execution) {
                $execution->executeAsync($deferred);
            });
        }

        $this->jobs = [];
    }
}
