## Worker System

The current queue architecture has three layers:

1. `JobRegistrar`
   Creates immediate jobs in `jobs` or scheduled definitions in `scheduled_jobs`.
2. `app:job-dispatcher`
   Runs scheduled expansion, recurring expansion, stale recovery, and queue release.
3. `app:job-worker`
   Supervises one or more `app:job-runner` processes that only consume work for their pool.

Workers no longer dispatch jobs themselves.

## Queue Transport Modes

The queue transport is selected with `JOB_QUEUE_TRANSPORT`.

- `mongo`
  MongoDB is both the durable job store and the hot queue.
- `redis-streams`
  MongoDB remains the durable source of truth and audit log.
  Redis Streams becomes the delivery layer for pool workers.

In both modes:

- `jobs` remains the durable job document collection.
- `job_executions` remains the execution history collection.
- `scheduled_jobs` stores future jobs until they become due.
- `recurring_jobs` stores cron definitions.

## Current Flow

```text
JobRegistrar
  -> jobs / scheduled_jobs in Mongo

job-dispatcher
  -> fetch due scheduled jobs
  -> expand recurring jobs
  -> recover stale jobs
  -> queue transport release

Queue transport
  mongo:
    pending jobs stay in Mongo and are assigned there
  redis-streams:
    due pending jobs are published into Redis pool streams
    delayed retries are held in a Redis sorted set

job-worker / job-runner
  -> consume jobs for one pool
  -> execute job
  -> write job_executions
  -> update final job state in Mongo
  -> update Redis realtime state

## Real Concurrency

- PHP job handlers are synchronous. Promise wrapping alone does not create parallel execution.
- Use pool `runner_processes` to opt into real process-level concurrency inside one container.
- `capacity` is the total logical slot count for the container.
- `runner_processes` splits that total capacity across real supervised runner processes.
- Each runner advertises its own slot capacity to the dispatcher, so assignment math matches reality.
```

## Key Commands

```php
$registrar = new JobRegistrar();
$recurring = new RecurringJobs();

// Immediate job
$registrar->registerJob([CliController::class, 'index2'], ['ImmediateJob' => 'tester'], null, 'Dev');

// Scheduled job
$registrar->registerJob(
    [CliController::class, 'index1'],
    ['ScheduledJob' => 'test'],
    (new \DateTimeImmutable())->modify('+5 seconds'),
    'Dev'
);

// Recurring job
$recurring->register([CliController::class, 'index3'], '*/1 * * * *');
```

CLI entrypoints:

- `php nraa app:job-dispatcher`
- `php nraa app:job-worker general`
- `php nraa app:job-worker metadata`
- `php nraa app:seed-dummy-jobs`
- `php nraa app:validate-redis-streams-queue`

## Operational Notes

- Deploy the dedicated `job-dispatcher` service before relying on worker throughput.
- Do not mix `mongo` and `redis-streams` transport settings across app containers.
- In `redis-streams` mode, Redis is the hot delivery path, but Mongo is still authoritative for job status and history.
- Use `app:normalize-job-queue-data` before production cutover if older queue rows may have blank pools or stale active idempotency fields.
