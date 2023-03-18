# Scheduler

Cron job scheduler - with locks, parallelism and more

## Content

- [Why do you need it?](#why-do-you-need-it)
- [Quick start](#quick-start)
- [Execution time](#execution-time)
- [Events](#events)
- [Handling errors](#handling-errors)
- [Locks and job overlapping](#locks-and-job-overlapping)
- [Job types](#job-types)
	- [Callback job](#callback-job)
	- [Custom job](#custom-job)
- [Job info and result](#job-info-and-result)
- [Run summary](#run-summary)
- [Run single job](#run-single-job)
- [CLI commands](#cli-commands)
	- [Run command - run jobs once](#run-command)
	- [Run job command - run single job](#run-job-command)
	- [List command - show all jobs](#list-command)
	- [Worker command - run jobs periodically](#worker-command)

## Why do you need it?

Let's say you already use cron jobs configured via crontab (or custom solution given by hosting). Each cron has to be
registered in crontab in every single environment (e.g. local, stage, production) and application itself is generally
not aware of these cron jobs.

With this library you can manage all cron jobs in application and setup them in crontab with single line.

> Why not any alternative library? There is ton of them.

Well, you are right. But do they manage everything needed? By not using crontab directly you loose several features that
library has to replicate:

- parallelism - jobs should run in parallel and start in time even if one or more run for a long time
- [failure protection](#handling-errors) - if one job fails, the failure should be logged and the other jobs should
  still be executed
- [cron expressions](#execution-time) - library has to parse and properly evaluate cron expression to determine whether
  job should be run

Orisai Scheduler solves all of these problems.

On top of that you get:

- [locking](#locks-and-job-overlapping) - each job should run only once at a time, without overlapping
- [before/after job events](#events) for accessing job status
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
* * * * * path/to/project/bin/scheduler.php >> /dev/null 2>&1
```

Got to go!

## Execution time

Cron execution time is expressed via `CronExpression`, using crontab syntax

```php
use Cron\CronExpression;

$scheduler->addJob(
	/* ... */,
	new CronExpression('* * * * *'),
);
```

It's important to use caution with cron syntax, so please refer to the example below.
To validate your cron, you can also utilize [crontab.guru](https://crontab.guru).

```
*   *   *   *   *
-   -   -   -   -
|   |   |   |   |
|   |   |   |   |
|   |   |   |   +----- day of week (0-6) (or SUN-SAT) (0=Sunday)
|   |   |   +--------- month (1-12) (or JAN-DEC)
|   |   +------------- day of month (1-31)
|   +----------------- hour (0-23)
+--------------------- minute (0-59)
```

Each part of expression can also use wildcard, lists, ranges and steps:

- wildcard - `* * * * *` - At every minute.
- lists - e.g. `15,30 * * * *` - At minute 15 and 30.
- ranges - e.g. `1-9 * * * *` - At every minute from 1 through 9.
- steps - e.g. `*/5 * * * *` - At every 5th minute.

You can also use macro instead of an expression:

- `@yearly`, `@annually` - Run once a year, midnight, Jan. 1 - `0 0 1 1 *`
- `@monthly` - Run once a month, midnight, first of month - `0 0 1 * *`
- `@weekly` - Run once a week, midnight on Sun - `0 0 * * 0`
- `@daily`, `@midnight` - Run once a day, midnight - `0 0 * * *`
- `@hourly` - Run once an hour, first minute - `0 * * * *`

## Events

Run callbacks before and after job to collect statistics, etc.

```php
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;

$scheduler->addBeforeJobCallback(
	function(JobInfo $info): void {
		// Executes before job start
	},
);

$scheduler->addAfterJobCallback(
	function(JobInfo $info, JobResult $result): void {
		// Executes after job finish
	},
);
```

Check [job info and result](#job-info-and-result) for available status info

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
	$this->logger->error("Job {$info->getName()} failed", [
		'exception' => $throwable,
		'name' => $info->getName(),
		'expression' => $info->getExpression(),
		'start' => $info->getStart()->format(DateTimeInterface::ATOM),
		'end' => $result->getEnd()->format(DateTimeInterface::ATOM),
	]);
},
$scheduler = new SimpleScheduler($errorHandler);
```

## Locks and job overlapping

Crontab jobs are time-based and simply run at specified intervals. If they take too long, they may overlap and run
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

```php
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobLock;

new CallbackJob(function (JobLock $lock): void {
	// Lock methods are the same as symfony/lock provides
	$lock->isAcquiredByCurrentProcess(); // bool (same is symfony isAcquired(), but with more accurate name)
	$lock->getRemainingLifetime(); // float|null
	$lock->isExpired(); // bool
	$lock->refresh(); // void
});
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

### Custom job

Create own job implementation

```php
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobLock;

final class CustomJob implements Job
{

	public function getName(): string
	{
		// Provide (preferably unique) name of the job. It will be used in jobs list
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

## Job info and result

Status information available via [events](#events) and [run summary](#run-summary)

Info:

```php
$name = $info->getName(); // string
$expression = $info->getExpression(); // string, e.g. '* * * * *'
$start = $info->getStart(); // DateTimeImmutable
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

foreach ($summary->getJobs() as $jobSummary) {
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

[Handling errors](#handling-errors) is the same as for `run()` method, except instead of `RunFailure` is
thrown `JobFailure`.

## CLI commands

For symfony/console you may use our commands:

- [Run](#run-command)
- [List](#list-command)
- [Worker](#worker-command)

> Examples assume you run console via executable php script `bin/console`

Assuming you don't use some DI library for handling services, register commands like this:

```php
use Symfony\Component\Console\Application;
use Orisai\Scheduler\Command\ListCommand;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Command\RunJobCommand;
use Orisai\Scheduler\Command\WorkerCommand;

$app = new Application();
$app->addCommands([
	new ListCommand($scheduler),
	new RunCommand($scheduler),
	new RunJobCommand($scheduler),
	new WorkerCommand(),
])
```

### Run command

Run scheduler once, executing jobs scheduled for the current minute

`bin/console scheduler:run`

You can also change crontab settings to use command instead:

```
* * * * * php path/to/project/bin/console scheduler:run >> /dev/null 2>&1
```

### Run job command

Run single job, ignoring scheduled time

`bin/console scheduler:run-job <id>`

- use `--no-force` to respect due time and only run job if it is due

### List command

List all scheduled jobs (in `expression [id] name... next-due` format)

`bin/console scheduler:list`

- use `--next` to sort jobs by their next execution time
- `--next=N` lists only *N* next jobs (e.g. `--next=3` prints maximally 3)
- use `-v` to display absolute times

### Worker command

Run scheduler repeatedly, once every minute

`bin/console scheduler:worker`

- requires `pcntl_*` function to be enabled
- if your executable script is not `bin/console`, specify it:
	- via `your/console scheduler:worker -e=your/console`
	- or via constructor parameter `new WorkerCommand(executable: 'your/console')`
