# Scheduler

Cron job scheduler - with locks, parallelism and more

## Content

- [Why do you need it?](#why-do-you-need-it)
- [Quick start](#quick-start)
- [Execution time](#execution-time)
- [Events](#events)
- [Logging errors](#logging-errors)
- [Job types](#job-types)
	- [Callback job](#callback-job)
	- [Custom job](#custom-job)
- [Job info and result](#job-info-and-result)
- [Run summary](#run-summary)

## Why do you need it?

Let's say you already use cron jobs configured via crontab (or custom solution given by hosting). Each cron has to be
registered in crontab in every single environment (e.g. local, stage, production) and application itself is generally
not aware of these cron jobs.

With this library you can manage all cron jobs in application and setup them in crontab with single line.

> Why not any alternative library? There is ton of them.

Well, you are right. But do they manage everything needed? By not using crontab directly you loose several features that
library has to replicate:

- locking - each job should run only once at a time
- parallelism - jobs should run in parallel and start in time even if one or more run for a long time
- failure protection - if one job fails, the failure should be logged and the other jobs should still be executed
- cron expressions - library has to parse and properly evaluate cron expression to determine whether job should be run

Orisai Scheduler solves all of these problems.

On top of that you get:

- overview of all jobs, including estimated times of previous and next runs and whether job is currently running
- heatmap for load-balancing your jobs
- before/after job events for customizations

## Quick start

Install with [Composer](https://getcomposer.org)

```sh
composer require orisai/scheduler
```

Create script with scheduler setup (e.g. `bin/scheduler.php`)

```php
use Cron\CronExpression;
use Orisai\Scheduler\Scheduler;

$scheduler = new Scheduler();

// Add jobs
$scheduler->addJob(
	new CallbackJob(fn() => exampleTask()),
	new CronExpression('* * * * *'),
);

$scheduler->run();
```

Configure crontab to run your script each minute

```
* * * * * path/to/bin/scheduler.php >> /dev/null 2>&1
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

Run callbacks before and after job to collect statistics, log errors etc.

```php
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;

$scheduler->addBeforeJobCallback(
	function(JobInfo $info): void {
		// Executes before job start

		$name = $info->getName(); // string
		$start = $info->getStart(); // DateTimeImmutable
	},
);

$scheduler->addAfterJobCallback(
	function(JobInfo $info, JobResult $result): void {
		// Executes after job finish
	},
);
```

Check [job info and result](#job-info-and-result) for available status info

## Logging errors

Because any exceptions and errors are suppressed to prevent one failing job from failing others, you have to handle
logging exceptions yourself.

Assuming you have a [PSR-3 logger](https://github.com/php-fig/log), e.g. [Monolog](https://github.com/Seldaek/monolog)
installed, it would look like this:

```php
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;

$scheduler->addAfterJobCallback(
	function(JobInfo $info, JobResult $result): void {
		$throwable = $result->getThrowable();
		if ($throwable !== null) {
			$this->logger->error('Job failed', [
				'exception' => $throwable,
			]);
		}
	},
);
```

## Job types

### Callback job

Calls given Closure, when job is run

```php
use Closure;
use Orisai\Scheduler\Job\CallbackJob;

$scheduler->addJob(
	new CallbackJob(Closure::fromCallable([$object, 'method'])),
	/* ... */,
);

$scheduler->addJob(
	new CallbackJob(fn() => exampleTask()),
	/* ... */,
);
```

### Custom job

Create own job implementation

```php
use Orisai\Scheduler\Job\Job;

final class CustomJob implements Job
{

	public function getName(): string
	{
		// Provide (preferably unique) name of the job. It will be used in jobs overview
		return static::class;
	}

	public function run(): void
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
$throwable = $result->getThrowable(); // Throwable|null
```

## Run summary

Scheduler run returns summary for inspection

```php
$summary = $scheduler->run(); // RunSummary

foreach ($summary->getJobs() as [$info, $result]) {
	// $info instanceof JobInfo
	// $result instanceof JobResult
}
```

Check [job info and result](#job-info-and-result) for available jobs status info
