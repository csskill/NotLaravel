         ┌──────────────────────────────┐
         │  Supervisor: app:job-worker │
         │  (JobManager + JobQueue)    │
         └───────────────┬────────────┘
                         │
                         │ Fetch due jobs from
                         │ Scheduled / Recurring
                         ▼
                ┌───────────────────┐
                │   JobQueue        │
                │   Status: pending │
                │   nextRunAt <= now│
                └─────────┬─────────┘
                          │
                          │ distributeJobs()
                          │ → markAssigned(jobId, workerId)
                          ▼
           ┌────────────────────────────┐
           │  Worker Processes (N)      │
           │  app:job-runner            │
           │  Loop every 2 seconds      │
           └─────────┬──────────────────┘
                     │
                     │ fetchAssigned(workerId, limit)
                     ▼
              ┌──────────────┐
              │   Worker     │
              │ Local Jobs   │
              └─────┬────────┘
                    │
                    │ startWork()
                    ▼
             ┌──────────────────────────┐
             │   JobExecution           │
             │   Async Execution / Fork │
             │   Tracks in_progress     │
             └───────────┬─────────────┘
                         │
          ┌──────────────┴───────────────┐
          │                              │
      Success                        Failure
      status = completed             status = failed
      completedAt = now              retries++
      JobQueue::markCompleted()      JobQueue::markFailed()
      JobExecutionDocument           if retries < maxRetries
                                    nextRunAt = now + backoff
                                    status = pending
                                    Worker will pick up later


// JobRegistrar
$registrar = new JobRegistrar($queue, $scheduled);

// Immediate job
$registrar->registerJob([CliController::class, 'index2'], ['ImmediateJob' => 'tester'], null, 'Dev');
// Scheduled job in 5 seconds
$registrar->registerJob([CliController::class, 'index1'], ['ScheduledJob' => 'test'], (new \DateTimeImmutable())->modify('+5 seconds'), 'Dev');
// Recurring job
$recurring->register([CliController::class, 'index3'], '*/1 * * * *');
$recurring->register([CliController::class, 'index3'], '*/1 * * * *');
$recurring->register([CliController::class, 'index3'], '*/2 * * * *');
$recurring->register([CliController::class, 'index3'], '*/5 * * * *');