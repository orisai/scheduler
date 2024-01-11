<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use Closure;
use Cron\CronExpression;
use Exception;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\ManagedScheduler;
use Orisai\Scheduler\Manager\SimpleJobManager;
use Orisai\Scheduler\Scheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Throwable;
use function fwrite;
use const STDERR;

final class SchedulerProcessSetup
{

	public static function createWithErrorHandler(): Scheduler
	{
		$errorHandler = static function (): void {
			// Noop
		};

		return self::create($errorHandler, __DIR__ . '/scheduler-process-binary-with-error-handler.php');
	}

	public static function createWithoutErrorHandler(): Scheduler
	{
		return self::create(null, __DIR__ . '/scheduler-process-binary-without-error-handler.php');
	}

	public static function createWithDefaultExecutable(): Scheduler
	{
		return self::create();
	}

	public static function createEmpty(): ManagedScheduler
	{
		$jobManager = new SimpleJobManager();
		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/scheduler-process-binary-empty.php');

		return new ManagedScheduler($jobManager, null, null, $executor, $clock);
	}

	public static function createWithThrowingJob(): ManagedScheduler
	{
		$jobManager = new SimpleJobManager();
		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/scheduler-process-binary-with-throwing-job.php');

		$jobManager->addJob(
			new CallbackJob(static function (): void {
				throw new Exception('');
			}),
			new CronExpression('* * * * *'),
		);

		return new ManagedScheduler($jobManager, null, null, $executor, $clock);
	}

	public static function createWithStderr(): ManagedScheduler
	{
		$jobManager = new SimpleJobManager();
		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/scheduler-process-binary-sdterr.php');

		$jobManager->addJob(
			new CallbackJob(static function (): void {
				// Just forces executable to run
			}),
			new CronExpression('* * * * *'),
		);

		return new ManagedScheduler($jobManager, null, null, $executor, $clock);
	}

	public static function createWithStderrJob(): ManagedScheduler
	{
		$jobManager = new SimpleJobManager();
		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);
		$executor->setExecutable(__DIR__ . '/scheduler-process-binary-with-stderr-job.php');

		$jobManager->addJob(
			new CallbackJob(static function (): void {
				fwrite(STDERR, ' job error ');
			}),
			new CronExpression('* * * * *'),
		);

		return new ManagedScheduler($jobManager, null, null, $executor, $clock);
	}

	/**
	 * @param Closure(Throwable, JobInfo, JobResult): (void)|null $errorHandler
	 */
	private static function create(?Closure $errorHandler = null, ?string $script = null): Scheduler
	{
		$cbs = new CallbackList();
		$jobManager = new SimpleJobManager();
		$jobManager->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
			'job1',
			30,
		);
		$jobManager->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'exceptionJob'])),
			new CronExpression('* * * * *'),
		);
		$jobManager->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('0 * * * *'),
		);
		$jobManager->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('1 * * * *'),
		);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		if ($script !== null) {
			$executor->setExecutable($script);
		}

		return new ManagedScheduler($jobManager, $errorHandler, null, $executor, $clock);
	}

}
