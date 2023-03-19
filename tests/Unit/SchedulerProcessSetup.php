<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use Closure;
use Cron\CronExpression;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Throwable;

final class SchedulerProcessSetup
{

	public static function createWithErrorHandler(): Scheduler
	{
		$errorHandler = static function (): void {
			// Noop
		};

		return self::create($errorHandler, 'tests/Unit/scheduler-process-binary-with-error-handler.php');
	}

	public static function createWithoutErrorHandler(): Scheduler
	{
		return self::create(null, 'tests/Unit/scheduler-process-binary-without-error-handler.php');
	}

	public static function createWithDefaultExecutable(): Scheduler
	{
		return self::create();
	}

	/**
	 * @param Closure(Throwable, JobInfo, JobResult): (void)|null $errorHandler
	 */
	private static function create(?Closure $errorHandler = null, ?string $executable = null): Scheduler
	{
		$executor = new ProcessJobExecutor($executable);
		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler($errorHandler, null, $executor, $clock);
		$cbs = new CallbackList();

		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
			'job1',
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'exceptionJob'])),
			new CronExpression('* * * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('0 * * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('1 * * * *'),
		);

		return $scheduler;
	}

}
