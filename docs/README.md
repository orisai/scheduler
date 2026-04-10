# Scheduler

Cron job scheduler - with locks, parallelism and more

## Content

- [Why do you need it?](#why-do-you-need-it)
- [Quick start](#quick-start)
- [Execution time](#execution-time)
	- [Cron expression - minutes and above](#cron-expression---minutes-and-above)
	- [Seconds](#seconds)
	- [Timezones](#timezones)
- [Events](#events)
	- [Before job event](#before-job-event)
	- [After job event](#after-job-event)
	- [Locked job event](#locked-job-event)
	- [Before run event](#before-run-event)
	- [After run event](#after-run-event)
- [Handling errors](#handling-errors)
- [Logging potential problems](#logging-potential-problems)
- [Locks and job overlapping](#locks-and-job-overlapping)
	- [Multi-server protection](#multi-server-protection)
- [Parallelization and process isolation](#parallelization-and-process-isolation)
- [Job types](#job-types)
	- [Callback job](#callback-job)
	- [Custom job](#custom-job)
	- [Symfony console job](#symfony-console-job)
- [Job info and result](#job-info-and-result)
- [Run summary](#run-summary)
- [Run single job](#run-single-job)
- [CLI commands](#cli-commands)
	- [Run command - run jobs once](#run-command)
	- [Run job command - run single job](#run-job-command)
	- [List command - show all jobs](#list-command)
	- [Worker command - run jobs periodically](#worker-command)
	- [Explain command - explain cron expression syntax](#explain-command)
- [Run tracking](#run-tracking)
	- [Status command](#status-command)
- [Maintenance mode](#maintenance-mode)
	- [Setup](#maintenance-setup)
	- [Signal handling](#signal-handling)
	- [Deploy integration](#deploy-integration)
	- [Exit codes](#exit-codes)
- [Lazy loading](#lazy-loading)
- [Integrations and extensions](#integrations-and-extensions)
- [Troubleshooting guide](#troubleshooting-guide)
	- [Running a job throws JobProcessFailure exception](#running-a-job-throws-jobprocessfailure-exception)
	- [Job starts too late](#job-starts-too-late)
	- [Job does not start at scheduled time](#job-does-not-start-at-scheduled-time)
	- [Job executions overlap](#job-executions-overlap)

## Why do you need it?

Let's say you already use cron jobs configured via crontab (or custom solution given by hosting). Each cron has to be
registered in crontab in every single environment (e.g. local, stage, production) and application itself is generally
not aware of these cron jobs.

With this library you can manage all cron jobs in application and setup them in crontab with single line.

> Why not any [alternative library](https://github.com/search?q=php+cron&type=repositories)? There is ton of them.

Well, you are right. But do they manage everything needed? By not using crontab directly you loose several features that
library has to replicate:

- [parallelism](#parallelization-and-process-isolation) - jobs should run in parallel and start in time even if one or
  more run for a long time
- [failure protection](#handling-errors) - if one job fails, the failure should be logged and the other jobs should
  still be executed
- [cron expressions](#execution-time) - library has to parse and properly evaluate cron expression to determine whether
  job should be run

Orisai Scheduler solves all of these problems.

On top of that you get:

- [locking](#locks-and-job-overlapping) - each job should run only once at a time, without overlapping
- [per-second scheduling](#seconds) - run jobs multiple times in a minute
- [timezones](#timezones) - interpret job schedule within specified timezone
- [events](#events) for accessing job status
- [overview of all jobs](#list-command), including estimated time of next run
- running jobs either [once](#run-command) or [periodically](#worker-command) during development
- running just a [single](#run-single-job) job, either ignoring or respecting due times

## Quick start

Install with [Composer](https://getcomposer.org)

```sh
composer require orisai/scheduler
```

Create script with scheduler setup (e.g. `bin/scheduler.php`)

```php
use Cron\CronExpression;
use Orisai\Scheduler\SimpleScheduler;

$scheduler = new SimpleScheduler();

// Add jobs
$scheduler->addJob(
	new CallbackJob(fn() => exampleTask()),
	new CronExpression('* * * * *'),
);

$scheduler->run();
```

Configure crontab to run your script each minute

```
* * * * * cd path/to/project && php bin/scheduler.php >> /dev/null 2>&1
```

Good to go!

## Execution time

Execution time is determined by [cron expression](#cron-expression---minutes-and-above) which allows you to schedule
jobs from anywhere between once a year and once every minute and [seconds], allowing you tu run job several times in a
minute.

In ideal situation, jobs are executed just in time, but it may not be always the case. Crontab can execute jobs several
seconds late, serial jobs execution may take way over a minute and long jobs may overlap. To prevent any issues, we
implement multiple measures:

- jobs [repeated after seconds](#seconds) take in account crontab may run late and delay each execution accordingly to
  minimize unwanted gaps between executions (e.g. if crontab starts 10 seconds late, all jobs also run 10 seconds late)
- [parallel execution](#parallelization-and-process-isolation) can be used instead of the serial
- [locks](#locks-and-job-overlapping) should be used to prevent overlapping of long-running jobs

### Cron expression - minutes and above

Main job execution time is expressed via `CronExpression`, using crontab syntax

```php
use Cron\CronExpression;

$scheduler->addJob(
	/* ... */,
	new CronExpression('* * * * *'),
);
```

It's important to use caution with cron syntax, so please refer to the example below.
To validate your cron, you can also utilize [explain command](#explain-command) or
the [explainer](https://github.com/orisai/cron-expression-explainer) directly.

```
*   *   *   *   *
-   -   -   -   -
|   |   |   |   |
|   |   |   |   |
|   |   |   |   +----- day of week (0-7) (Sunday = 0 or 7) (or SUN-SAT)
|   |   |   +--------- month (1-12) (or JAN-DEC)
|   |   +------------- day of month (1-31)
|   +----------------- hour (0-23)
+--------------------- minute (0-59)
```

Each part of expression can also use wildcard, lists, ranges and steps:

- wildcard - match always
	- `* * * * *` - At every minute.
	- day of week and day of month also support `?`, an alias to `*`
- lists - match list of values, ranges and steps
	- e.g. `15,30 * * * *` - At minute 15 and 30.
- ranges - match values in range
	- e.g. `1-9 * * * *` - At every minute from 1 through 9.
- steps - match every nth value in range
	- e.g. `*/5 * * * *` - At every 5th minute.
	- e.g. `0-30/5 * * * *` - At every 5th minute from 0 through 30.
- combinations
	- e.g. `0-14,30-44 * * * *` - At every minute from 0 through 14 and every minute from 30 through 44.

You can also use macro instead of an expression:

- `@yearly`, `@annually` - At 00:00 on 1st of January. (same as `0 0 1 1 *`)
- `@monthly` - At 00:00 on day-of-month 1. (same as `0 0 1 * *`)
- `@weekly` - At 00:00 on Sunday. (same as `0 0 * * 0`)
- `@daily`, `@midnight` - At 00:00. (same as `0 0 * * *`)
- `@hourly` - At minute 0. (same as `0 * * * *`)

Day of month extra features:

- nearest weekday - weekday (Monday-Friday) nearest to the given day
	- e.g. `* * 15W * *` - At every minute on a weekday nearest to the 15th.
	- If you were to specify `15W` as the value, the meaning is: "the nearest weekday to the 15th of the month"
	  So if the 15th is a Saturday, the trigger will fire on Friday the 14th.
	  If the 15th is a Sunday, the trigger will fire on Monday the 16th.
	  If the 15th is a Tuesday, then it will fire on Tuesday the 15th.
	- However, if you specify `1W` as the value for day-of-month,
	  and the 1st is a Saturday, the trigger will fire on Monday the 3rd,
	  as it will not 'jump' over the boundary of a month's days.
- last day of the month
	- e.g. `* * L * *` - At every minute on a last day-of-month.
- last weekday of the month
	- e.g. `* * LW * *` - At every minute on a last weekday.

Day of week extra features:

- nth day
	- e.g. `* * * * 7#4` - At every minute on 4th Sunday.
	- 1-5
	- Every day of week repeats 4-5 times a month. To target the last one, use "last day" feature instead.
- last day
	- e.g. `* * * * 7L` - At every minute on the last Sunday.

### Seconds

Run a job every n seconds within a minute.

```php
use Cron\CronExpression;

$scheduler->addJob(
	/* ... */,
	new CronExpression('* * * * *'),
	/* ... */,
	1, // every second, 60 times a minute
);
```

```php
use Cron\CronExpression;

$scheduler->addJob(
	/* ... */,
	new CronExpression('* * * * *'),
	/* ... */,
	30, // every 30 seconds, 2 times a minute
);
```

With default, synchronous job executor, all jobs scheduled for current second are executed and just after it is
finished, jobs for the next second are executed. With [parallel](#parallelization-and-process-isolation) executor it is
different - all jobs are executed as soon as it is their time. Therefore, it is strongly recommended to
use [locking](#locks-and-job-overlapping) to prevent overlapping.

### Timezones

All jobs run within timezone used by your application. You may specify that your job execution time should be
interpreted within different timezone, e.g. every midnight in Europe/Prague.

```php
use Cron\CronExpression;
use DateTimeZone;

$scheduler->addJob(
	/* ... */,
	new CronExpression('0 0 * * *'),
	/* ... */,
	/* ... */,
	new DateTimeZone('Europe/Prague'),
);
```

Some timezones use daylight savings time. When daylight saving time changes occur, scheduled job may run twice or even
not run at all during that period. Make sure you run your tasks often enough and that running them more often gives you
expected results.

If you want job to run at specific time (e.g. midnight) in timezone of each user, run it every 15 minutes and implement
timezone checking logic yourself. Several time zones have deviations of either 30 or 45 minutes. For instance, UTC-03:30
is the standard time in Newfoundland, while Nepal's standard time is UTC+05:45. Indian Standard Time is UTC+05:30, and
Myanmar Standard Time is UTC+06:30.

## Events

Run callbacks to collect statistics, etc.

### Before job event

Executes before job start

- has [JobInfo](#job-info-and-result) available as a parameter
- does not execute if job is [locked](#locks-and-job-overlapping), see [locked job event](#locked-job-event)

```php
use Orisai\Scheduler\Status\JobInfo;

$scheduler->addBeforeJobCallback(
	function(JobInfo $info): void {
		// Executes before job start
	},
);
```

### After job event

Executes after job finish

- has [JobInfo and JobResult](#job-info-and-result) available as a parameter
- executes even if job failed with an exception

```php
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;

$scheduler->addAfterJobCallback(
	function(JobInfo $info, JobResult $result): void {
		// Executes after job finish
	},
);
```

### Locked job event

Executes when [lock](#locks-and-job-overlapping) for given job is acquired by another process and therefore job does not
execute

- has [JobInfo and JobResult](#job-info-and-result) available as a parameter

```php
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;

$scheduler->addLockedJobCallback(
	function(JobInfo $info, JobResult $result): void {
		// Executes when lock for given job is acquired by another process
	},
);
```

### Before run event

Executes before every run (every minute), even if no jobs will be executed

- has RunInfo available as a parameter

```php
use Orisai\Scheduler\Status\RunInfo;

$scheduler->addBeforeRunCallback(
	function(RunInfo $info): void {
		$info->getStart(); // DateTimeImmutable

		foreach ($info->getJobInfos() as $jobInfo) {
			$jobInfo->getId(); // int|string
			$jobInfo->getName(); // string
			$jobInfo->getExpression(); // string, e.g. * * * * *
			$jobInfo->getTimeZone(); // DateTimeZone|null
			$jobInfo->getExtendedExpression(); // string, e.g. '* * * * * / 30 (Europe/Prague)'
			$jobInfo->getRepeatAfterSeconds(); // int<0, 30>
			$jobInfo->getRunsCountPerMinute(); // int<1, max>
			$jobInfo->getEstimatedStartTimes(); // list<DateTimeImmutable>
		}
	},
);
```

### After run event

Executes after every run (every minute), even if no jobs were executed

- has [RunSummary](#run-summary) available as a parameter

```php
use Orisai\Scheduler\Status\RunSummary;

$scheduler->addAfterRunCallback(
	function(RunSummary $summary): void {
		// Executes after every run (every minute), even if no jobs were executed
	},
);
```

## Handling errors

After all jobs finish, an exception `RunFailure` composing exceptions thrown by all jobs is thrown. This
exception will inform you about which exceptions were thrown, including their messages and source. But this still makes
exceptions hard to access by application error handler and causes [CLI commands](#cli-commands) to hard fail.

To overcome this limitation, add minimal error handler into scheduler. When an error handler is
set, `RunFailure` is *not thrown*.

Assuming you have a [PSR-3 logger](https://github.com/php-fig/log), e.g. [Monolog](https://github.com/Seldaek/monolog)
installed, it would look like this:

```php
use DateTimeInterface;
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Throwable;

$errorHandler = function(Throwable $throwable, JobInfo $info, JobResult $result): void {
	$id = $info->getId();
	$name = $info->getName();

	$this->logger->error("Job [$id] $name failed", [
		'exception' => $throwable,
		'id' => $id,
		'name' => $name,
		'expression' => $info->getExtendedExpression(),
		'runSecond' => $info->getRunSecond(),
		'start' => $info->getStart()->format(DateTimeInterface::ATOM),
		'end' => $result->getEnd()->format(DateTimeInterface::ATOM),
		'forcedRun' => $info->isForcedRun(),
	]);
},
$scheduler = new SimpleScheduler($errorHandler);
```

## Logging potential problems

Using a [PSR-3](https://www.php-fig.org/psr/psr-3/)-compatible logger
(like [Monolog](https://github.com/Seldaek/monolog)) you may log some situations which do not fail the job, but are most
certainly unwanted:

- [Lock](#locks-and-job-overlapping) was released before the job finished. Your job has access to the lock and should
  extend the lock time so this does not happen.

```php
use Orisai\Scheduler\SimpleScheduler;

$scheduler = new SimpleScheduler(null, null, null, null, $logger);
```

If you use [process job executor](#parallelization-and-process-isolation), then also these situations are logged:

- Subprocess running the job produced unexpected *stdout* output. Job should never echo or write directly to stdout.
- Subprocess running the job produced unexpected *stderr* output. This may happen just due to deprecation notices but may
  also be caused by more serious problem occurring in CLI.

```php
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Executor\ProcessJobExecutor;

$executor = new ProcessJobExecutor(null, $logger);
$scheduler = new SimpleScheduler(null, null, $executor, null, $logger);
```

## Locks and job overlapping

Jobs are time-based and simply run at specified intervals. If they take too long, they may overlap and run
simultaneously. This may cause issues if the jobs access the same resources, such as files or databases, leading to
conflicts or data corruption.

To avoid such issues, we provide locking mechanism which ensures that only one instance of a job is running at any given
time.

```php
use Orisai\Scheduler\SimpleScheduler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

$lockFactory = new LockFactory(new FlockStore());
$scheduler = new SimpleScheduler(null, $lockFactory);
```

To choose the right lock store for your use case, please refer
to [symfony/lock](https://symfony.com/doc/current/components/lock.html) documentation. There are several available
stores with various levels of reliability, affecting when lock is released.

Lock is automatically acquired and released by scheduler even if a (recoverable) error occurred during job or its
events. Yet you still have to handle lock expiring in case your jobs take more than 5 minutes, and you are using an
expiring store.

For long-running jobs, call `extendTo()` periodically to prevent the lock from expiring:

```php
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobLock;

new CallbackJob(function (JobLock $lock): void {
	$items = $this->repository->findUnprocessed();

	foreach ($items as $item) {
		// Keep the lock alive while processing — expires 120 seconds from now
		$lock->extendTo(120);
		$this->process($item);
	}
});
```

Available `JobLock` methods:

```php
$lock->extendTo(120);          // set lock expiration to 120 seconds from now
$lock->getRemainingLifetime(); // float|null - seconds until lock expires
$lock->isExpired();            // bool - whether the lock TTL has expired
```

If a lock expires before the job finishes, a warning is logged via the PSR-3 logger. Configure
a [logger](#logging-potential-problems) to be notified about this — it indicates the job takes longer
than the lock TTL and should use `extendTo()` to keep the lock alive.

To make sure locks are correctly used during deployments, specify constant id for every added job, lock identifiers rely
on that fact. Otherwise, your job id will change when new jobs are added before it and acquired lock will be ignored.

```php
$scheduler->addJob(
	/* ... */,
	/* ... */,
	'job-id',
);
```

### Multi-server protection

When running the scheduler on multiple servers, a job can be executed twice within the same minute:
server A finishes the job and releases its lock, then server B starts slightly later, sees no lock, and runs the same job.

The scheduler prevents this using a **minute lock** — a short-lived lock (30-second TTL) acquired before the job
runs. Unlike the job lock (which is released when the job finishes), the minute lock is never explicitly released.
It stays in the lock store until its TTL expires, preventing another server from running the same job within the
same minute.

This requires a distributed lock store (Redis, database, etc.) — `InMemoryStore` is per-process and does not
provide multi-server protection.

For sub-minute jobs (`repeatAfterSeconds > 0`), each execution second gets its own minute lock key, so different
seconds within the same minute don't interfere with each other.

Manual job execution (`$scheduler->runJob($id)`) is not affected — forced runs bypass the minute lock.

## Parallelization and process isolation

It is important for crontab scheduler tasks to be executed asynchronously and in separate processes because this
approach provides several benefits, including:

- Isolation: Each task runs in its own separate process, which ensures that it is isolated from other tasks and any
  errors or issues that occur in one task will not affect the execution of other tasks.
- Resource management: Asynchronous execution of tasks allows for better resource management as multiple tasks can be
  executed simultaneously without causing resource conflicts.
- Efficiency: Asynchronous execution also allows for greater efficiency as tasks can be executed concurrently, reducing
  the overall execution time.
- Scalability: Asynchronous execution enables the system to scale more easily as additional tasks can be added without
  increasing the load on any one process.
- Flexibility: Asynchronous execution also allows for greater flexibility in scheduling as tasks can be scheduled to run
  at different times and frequencies without interfering with each other.

Overall, asynchronous and separate process execution of crontab scheduler tasks provides better performance,
reliability, and flexibility than running tasks synchronously in a single process.

To set up scheduler for parallelization and process isolation, you need to
have [proc_*](https://www.php.net/manual/en/ref.exec.php) functions enabled. Also in the background is
used [run-job command](#run-job-command), so you need to have [console](#cli-commands) set up as well.

```php
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\SimpleScheduler;

$executor = new ProcessJobExecutor();
$scheduler = new SimpleScheduler(null, null, $executor);
```

If your executable script is not `bin/console` or if you are using multiple scheduler setups, specify the executable:

```php
use Orisai\Scheduler\Executor\ProcessJobExecutor;

$executor = new ProcessJobExecutor();
$executor->setExecutable('bin/console', 'scheduler:run-job');
```

## Job types

### Callback job

Calls given Closure, when job is run

```php
use Closure;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobLock;

$scheduler->addJob(
	new CallbackJob(Closure::fromCallable([$object, 'method'])),
	/* ... */,
);

$scheduler->addJob(
	new CallbackJob(fn(JobLock $lock) => exampleTask()),
	/* ... */,
);
```

Callback must be a function/method matching following signature (unused parameters may be omitted). Functionality is
equal with `run()` method of a [custom job](#custom-job).

```php
use Orisai\Scheduler\Job\JobLock;

public function example(JobLock $lock): void
{
	// Do whatever you need to
}
```

### Custom job

Create own job implementation

- name should be preferably unique - it is used for [logging](#handling-errors), [event](#events) metadata and listing
  jobs in [commands](#cli-commands)
- `run()` method must throw an exception in order to mark the job failed
- `run()` method may manipulate [locking mechanism](#locks-and-job-overlapping)

```php
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobLock;

final class CustomJob implements Job
{

	public function getName(): string
	{
		// Provide (preferably unique) name of the job
		return static::class;
	}

	public function run(JobLock $lock): void
	{
 		// Do whatever you need to
	}

}
```

```php
$scheduler->addJob(
	new CustomJob(),
	/* ... */,
);
```

### Symfony console job

Run [symfony/console](https://github.com/symfony/console) command as a job

- if job succeeds (returns zero code), command output is ignored
- if job fails (returns non-zero code), exception is thrown, including command return code, output and if thrown by the
  command, the exception

```php
use Orisai\Scheduler\Job\SymfonyConsoleJob;

$job = new SymfonyConsoleJob($command, $application);
$scheduler->addJob(
	$job,
	/* ... */,
);

```

Command can be parametrized:

```php
$job->setCommandParameters([
	'argument' => 'value',
	'--value-option' => 'value',
	'--no-value-option' => null,
	'--array-value-option' => ['value1', 'value2'],
]);
```

When running command as a job, [lock](#locks-and-job-overlapping) cannot be simply refreshed as with other jobs.
Instead, you can change lock's default time to live to ensure lock was not released before the job finished.

```php
$job->setLockTtl(600); // Time in seconds
```

## Job info and result

Status information available via [events](#events) and [run summary](#run-summary)

Info:

```php
$id = $info->getId(); // string|int
$name = $info->getName(); // string
$expression = $info->getExpression(); // string, e.g. '* * * * *'
$repeatAfterSeconds = $info->getRepeatAfterSeconds(); // int<0, 30>
$timeZone = $info->getTimeZone(); // DateTimeZone|null
$extendedExpression = $info->getExtendedExpression(); // string, e.g. '* * * * * / 30 (Europe/Prague)'
$runSecond = $info->getRunSecond(); // int
$start = $info->getStart(); // DateTimeImmutable
$forcedRun = $info->isForcedRun(); // bool, happens when running job via $scheduler->runJob() or scheduler:run-job command, ignoring the cron expression
```

Result:

```php
$end = $result->getEnd(); // DateTimeImmutable
$state = $result->getState(); // JobResultState

// Next runs are computed from time when job was finished
$nextRun = $info->getNextRunDate(); // DateTimeImmutable
$threeNextRuns = $info->getNextRunDates(3); // list<DateTimeImmutable>
```

## Run summary

Scheduler run returns summary for inspection

```php
$summary = $scheduler->run(); // RunSummary

$summary->getStart(); // DateTimeImmutable
$summary->getEnd(); // DateTimeImmutable

foreach ($summary->getJobSummaries() as $jobSummary) {
	$jobSummary->getInfo(); // JobInfo
	$jobSummary->getResult(); // JobResult
}
```

Check [job info and result](#job-info-and-result) for available jobs status info

## Run single job

For testing purposes it may be useful to run single job

To do so, assign an ID to job when adding it to scheduler. You may also use an auto-assigned ID visible
in [list command](#list-command) but that's not recommended because it depends just on order in which jobs were added.

```php
$scheduler->addJob($job, $expression, 'id');
$scheduler->runJob('id'); // JobSummary
```

If you still want to respect job schedule and run it only if it is due, set 2nd parameter to false

```php
$scheduler->runJob('id', false); // JobSummary|null
```

Non-forced runs (`$scheduler->runJob('id', false)`) also respect [maintenance mode](#maintenance-mode) — the job
is skipped (returns `null`) when maintenance is active. Forced runs always execute regardless of maintenance.

[Handling errors](#handling-errors) is the same as for `run()` method, except instead of `RunFailure` is
thrown `JobFailure`.

## CLI commands

For [symfony/console](https://github.com/symfony/console) you may use our commands:

- [Run](#run-command)
- [Run job](#run-job-command)
- [List](#list-command)
- [Worker](#worker-command)
- [Explain](#explain-command)

> Examples assume you run console via executable php script `bin/console`

Assuming you don't use some DI library for handling services, register commands like this:

```php
use Symfony\Component\Console\Application;
use Orisai\Scheduler\Command\ExplainCommand;
use Orisai\Scheduler\Command\ListCommand;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Command\RunJobCommand;
use Orisai\Scheduler\Command\WorkerCommand;

$app = new Application();
$app->addCommands([
	new ExplainCommand($scheduler),
	new ListCommand($scheduler),
	new RunCommand($scheduler),
	new RunJobCommand($scheduler),
	new WorkerCommand(),
])
```

### Run command

Run scheduler once, executing jobs scheduled for the current minute

`bin/console scheduler:run`

Options:

- `--json` - output json with job info and result

You can also change crontab settings to use command instead:

```
* * * * * cd path/to/project && php bin/console scheduler:run >> /dev/null 2>&1
```

### Run job command

Run single job, ignoring scheduled time

`bin/console scheduler:run-job <id>`

Options:

- `--no-force` - respect due time and only run job if it is due
- `--json` - output json with job info and result

### List command

List all scheduled jobs (in `expression / second (timezone) [id] name... next-due` format)

```shell
bin/console scheduler:list
bin/console scheduler:list --next=3
bin/console scheduler:list --timezone=Europe/Prague
bin/console scheduler:list --explain
bin/console scheduler:list --explain=en
```

Options:

- `--next` - sort jobs by their next execution time
	- `--next=N` lists only *N* next jobs (e.g. `--next=3` prints maximally 3)
- `-v` - display absolute times
- `--timezone` (or `-tz`) - display times in specified timezone instead of one used by application
	- e.g. `--tz=UTC`
- `--explain[=<locale>]` - explain whole expression, including [seconds](#seconds) and [timezones](#timezones)
	- [Explain command](#explain-command) with `--id` parameter can be used to explain specific job
	- e.g. `--explain`
	- e.g. `--explain=en` (to choose locale)

### Worker command

Run scheduler repeatedly, once every minute

> This command should be used only for local development and requires interactive CLI.
> In production environment should be used crontab with the [run command](#run-command).

`bin/console scheduler:worker`

- requires [proc_*](https://www.php.net/manual/en/ref.exec.php) functions to be enabled
- if your executable script is not `bin/console` or if you are using multiple scheduler setups, specify the executable:
	- via `your/console scheduler:worker -s=your/console -c=scheduler:run`
	- or via setter `$workerCommand->setExecutable('your/console', 'scheduler:run')`

Options:

- `--script=<script>` (or `-s`) - script executed by worker (defaults to `bin/console`)
- `--command=<command>` (or `-c`) - command executed by worker (defaults to `scheduler:run`)
- `--force` - force run when non-interactive CLI is detected (!make sure you can terminate the worker!)

### Explain command

Explain cron expression syntax

```shell
bin/console scheduler:explain
bin/console scheduler:explain --id="job id"
bin/console scheduler:explain --expression="0 22 * 12 *"
bin/console scheduler:explain --expression="* 8 * * *" --seconds=10 --timezone="Europe/Prague" --locale=en
bin/console scheduler:explain -e"* 8 * * *" -s10 -tz"Europe/Prague" -len
```

Options:

- `--id=<id>` - explain specific job
	- [List command](#list-command) with `--explain` parameter can be used to explain all jobs
- `--expression=<expression>` (or `-e`) - explain expression
- `--seconds=<seconds>` (or `-s`) - repeat every n seconds
- `--timezone=<timezone>` (or `-tz`) - the timezone time should be displayed in
- `--locale=<locale>` (or `-l`) - explain in specified locale

## Run tracking

`RunRegistry` tracks active `scheduler:run` processes. This is useful for monitoring and for deploy scripts
to check if it's safe to proceed. Run tracking works independently of maintenance mode - you can use it
with any executor.

Two implementations are provided:

**FileRunRegistry** (single-server):

```php
use Orisai\Scheduler\RunRegistry\FileRunRegistry;

$registry = new FileRunRegistry(__DIR__ . '/var/scheduler-runs');
```

Stores a JSON file per active run with PID, process start time, and timestamp. Detects stale runs by:
1. Checking if the process is alive (`posix_kill($pid, 0)`)
2. Comparing process start time from `/proc` on Linux to detect PID reuse
3. Time-based fallback for systems without `/proc` (e.g. macOS)

Dead and stale processes are cleaned up automatically.

**LockPoolRunRegistry** (multi-server):

```php
use Orisai\Scheduler\RunRegistry\LockPoolRunRegistry;

$registry = new LockPoolRunRegistry($lockFactory, 10); // pool of 10 slots
```

Uses `symfony/lock` with a fixed pool of lock keys. Works with any lock store (Redis, database, etc.).
Locks are refreshed periodically to prevent TTL expiry during long runs.
If all pool slots are taken, throws `LogicException` - increase the pool size.

Pass the registry to the scheduler:

```php
use Orisai\Scheduler\SimpleScheduler;

$scheduler = new SimpleScheduler(
    null,      // errorHandler
    null,      // lockFactory
    null,      // executor
    null,      // clock
    null,      // logger
    null,      // maintenanceManager
    $registry,
);
```

### Status command

Check active runs and maintenance state:

```bash
php bin/console scheduler:status
```

Output:

```
Maintenance: UNAVAILABLE
Active runs: 2
  - 1712345678-a3f2b1 (PID 12345, started 15s ago)
  - 1712345679-d4e5c2 (PID 12346, started 3s ago)
Ready for shutdown: NO
```

Also available programmatically:

```php
$status = $scheduler->getStatus(); // ActivityStatus
$status->isMaintenanceEnabled();   // ?bool - null when maintenance not configured
$status->getActiveRuns();          // list<ActiveRun>
$status->isReadyForShutdown();     // true when maintenance active AND no active runs
```

Each `ActiveRun` provides `getId()`, `getPid()` and `getStartTimestamp()`.

## Maintenance mode

During deployments, you typically want to stop running cron jobs to prevent interference. The scheduler supports
a two-phase shutdown: first it waits for running jobs to finish naturally (graceful), then force-kills any remaining
processes after a configurable grace period.

Both executors support maintenance mode:
- **ProcessJobExecutor**: force-kills child processes after the grace period expires
- **BasicJobExecutor**: stops after the current job finishes (cannot interrupt in-process execution)

### Maintenance setup

Create a `MaintenanceChecker` implementation for your environment:

```php
use Orisai\Scheduler\Maintenance\MaintenanceChecker;

class AppMaintenanceChecker implements MaintenanceChecker
{

    public function isMaintenance(): bool
    {
        return file_exists(__DIR__ . '/maintenance.running');
    }

}
```

Set up the scheduler with `MaintenanceManager` and `RunRegistry`:

```php
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\RunRegistry\FileRunRegistry;
use Orisai\Scheduler\SimpleScheduler;

$checker = new AppMaintenanceChecker();
$registry = new FileRunRegistry(__DIR__ . '/var/scheduler-runs');
$manager = new MaintenanceManager($checker);

$scheduler = new SimpleScheduler(
    null,      // errorHandler
    null,      // lockFactory
    null,      // executor
    null,      // clock
    null,      // logger
    $manager,
    $registry,
);

$runCommand = new RunCommand($scheduler, null, $manager);
```

The grace period (time before force-kill) defaults to 30 seconds. This matches the Kubernetes
`terminationGracePeriodSeconds` default - long enough for most jobs to finish DB transactions, API calls
and file operations, short enough to not block deploys excessively.

```php
$manager = new MaintenanceManager($checker, 60); // 60 second grace period
```

### Signal handling

`RunCommand` and `WorkerCommand` handle `SIGTERM` and `SIGINT` signals:

- **RunCommand**: first signal triggers graceful shutdown (same as maintenance mode), second signal forces immediate exit
- **WorkerCommand**: first signal stops spawning new `scheduler:run` processes and waits for current ones to finish, second signal forces immediate exit

Signal handling in `RunCommand` requires `MaintenanceManager` to be passed to the command constructor.

Signal handling requires the `pcntl` extension (available on Linux/macOS CLI, not on Windows).
When pcntl is not available, signals are silently skipped - the `MaintenanceChecker` polling approach still works.

### Deploy integration

Typical deploy flow:

1. Enable maintenance (create your maintenance flag)
2. Poll `scheduler:status` with `--fail-when-not-ready-for-shutdown` until ready (exits with `0` when ready, `1` when not):

```bash
while ! php bin/console scheduler:status --fail-when-not-ready-for-shutdown; do
    sleep 1
done
```

3. Deploy the application
4. Disable maintenance (remove your maintenance flag)

### Exit codes

The `scheduler:run` command returns:

- `0` - all jobs completed successfully
- `1` - one or more jobs failed
- `2` - maintenance shutdown (run was stopped due to maintenance)

## Lazy loading

Jobs are executed only when it is their due time. To prevent initializing potentially heavy job dependencies when they
are not needed, you may lazy load the jobs. This is especially helpful
if [separate processes](#parallelization-and-process-isolation) are used for jobs.

```php
use Orisai\Scheduler\Job\Job;

$jobConstructor = function(): Job {
	// Initialize job
};
$scheduler->addLazyJob($jobConstructor, $expression, /* ... */);
```

For the same purpose you can also use `ManagedScheduler` instead of `SimpleScheduler`. It is functionally identical,
except:

- it requires a `JobManager` implementation as a first argument
- `addJob()` method is in `JobManager` instead of scheduler

Either use our `SimpleJobManager` implementation to construct job via a callback or use it as an inspiration to create
your own DI-specific version.

```php
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\ManagedScheduler;
use Orisai\Scheduler\Manager\SimpleJobManager;

$manager = new SimpleJobManager();
$jobConstructor = function(): Job {
	// Initialize job
};
$manager->addLazyJob($jobConstructor, $expression, /* ... */);

$scheduler = new ManagedScheduler($manager);
```

## Integrations and extensions

- [Nette](https://github.com/nette) integration - [orisai/nette-scheduler](https://github.com/orisai/nette-scheduler)

## Troubleshooting guide

Common errors and how to solve them.

### Running a job throws JobProcessFailure exception

Process can fail due to various reasons. Here are covered the most common (and known) ones.

*Stdout is empty:*

Stdout is used to return job result as a json. Being empty means that either executed command is completely wrong and
does not run the job or that job was terminated prematurely. Premature termination may happen when job or one of its
before/after events call the `exit()` (or `die()`) function or when the process is killed on system level.

*Stdout contains different output than json with job result:*

If the message says something like *Could not open input file: bin/console* then either executable file does not exist
(you can change path to executable, as described [here](#parallelization-and-process-isolation)) or permissions are set
up badly, and you don't have rights to execute the file.

In case of other stdout outputs you may run completely wrong command or the command writes to stdout. While we are able
to catch most output to `php://output` (like `print` and `echo`) and handle it properly, it is not always possible.
Output may still be produced outside the PHP script, you may have defined output buffer with higher priority than the
one from job runner or terminated the job.

*Stderr contains a suppressed error:*

That means you don't have an [error handler](#handling-errors) set or that the error handler throws an exception. Set an
error handler and make sure that it does not throw any exception.

### Job starts too late

Make sure to set up [parallel job executor](#parallelization-and-process-isolation). Otherwise, jobs are executed one
after the other and every preceding job will delay execution of the next job.

Before run/job events must finish before any jobs are started. Optimize them well and never use functions
like `sleep()`.

### Job does not start at scheduled time

Cron expressions are quite complex and interpreting them may not be always easy. Use `--explain` parameter of
the [list command](#list-command) or the [explain command](#explain-command) to explain the expression.

You can also check the next run date computed from cron expression

```php
$scheduler->getJobSchedules()['job-id']->getExpression()->getNextRunDate();
```

### Job executions overlap

Set up [locking](#locks-and-job-overlapping) and make sure the lock storage is sufficient for your setup. E.g. flock (
lock files on the disk) will not work for applications running across multiple servers.

Default lock timeout is set to 5 minutes. If your lock storage supports expiration and job takes over 5 minutes, lock
will be released before job finishes. In such case it is up to you to prolong the expiration time.
Each [job type](#job-types) allows you to control the lock.
