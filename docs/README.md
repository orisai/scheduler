# Scheduler

Cron job scheduler - with locks, parallelism and more

## Content

- [Why do you need it?](#why-do-you-need-it)
- [Quick start](#quick-start)
- [Events](#events)
- [Logging errors](#logging-errors)

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
use Orisai\Scheduler\Scheduler;

$scheduler = new Scheduler();

// Add jobs
$scheduler->addJob(new CallbackJob(fn() => exampleTask()));

$scheduler->run();
```

Configure crontab to run your script each minute

```txt
* * * * * path/to/bin/scheduler.php >> /dev/null 2>&1
```

Got to go!

## Events

Run callbacks before and after job to collect statistics, log errors etc.

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
