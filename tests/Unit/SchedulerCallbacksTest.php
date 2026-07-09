<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit;

use Cron\CronExpression;
use DateTimeImmutable;
use Generator;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\SchedulerCallbacks;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunInfo;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;
use function iterator_to_array;

final class SchedulerCallbacksTest extends TestCase
{

	public function testJobCallbacks(): void
	{
		$callbacks = new SchedulerCallbacks();

		$events = [];
		$callbacks->addBeforeJob(static function (JobInfo $info) use (&$events): void {
			$events[] = 'before';
		});
		$callbacks->addLockedJob(static function (JobInfo $info, JobResult $result) use (&$events): void {
			$events[] = 'locked';
		});
		$callbacks->addAfterJob(static function (JobInfo $info, JobResult $result) use (&$events): void {
			$events[] = 'after';
		});

		$info = new JobInfo(1, 'name', '* * * * *', 0, 0, new DateTimeImmutable(), null, false);
		$doneResult = new JobResult(new CronExpression('* * * * *'), new DateTimeImmutable(), JobResultState::done());
		$lockResult = new JobResult(new CronExpression('* * * * *'), new DateTimeImmutable(), JobResultState::lock());

		$callbacks->fireJobStarted($info);
		self::assertSame(['before'], $events);

		$events = [];
		$callbacks->fireJobFinished($info, $doneResult);
		self::assertSame(['after'], $events);

		$events = [];
		$callbacks->fireJobFinished($info, $lockResult);
		self::assertSame(['locked', 'after'], $events);
	}

	public function testRunCallbacks(): void
	{
		$callbacks = new SchedulerCallbacks();

		$received = [];
		$callbacks->addBeforeRun(static function (RunInfo $info) use (&$received): void {
			$received[] = $info;
		});
		$callbacks->addAfterRun(static function (RunSummary $summary) use (&$received): void {
			$received[] = $summary;
		});

		$runStart = new DateTimeImmutable();
		$job = new CallbackJob(static function (): void {
		});
		$schedules = ['id' => JobSchedule::create($job, new CronExpression('* * * * *'), 0)];

		$callbacks->fireBeforeRun($runStart, $schedules);

		self::assertCount(1, $received);
		self::assertInstanceOf(RunInfo::class, $received[0]);
		self::assertCount(1, $received[0]->getJobInfos());
		self::assertSame('id', $received[0]->getJobInfos()[0]->getJobId());

		$summary = new RunSummary($runStart, $runStart, [], false);
		$callbacks->fireAfterRun($summary);

		self::assertCount(2, $received);
		self::assertSame($summary, $received[1]);
	}

	public function testWrapGenerator(): void
	{
		$callbacks = new SchedulerCallbacks();

		$fired = [];
		$callbacks->addAfterJob(static function (JobInfo $info, JobResult $result) use (&$fired): void {
			$fired[] = [$info, $result];
		});

		$info = new JobInfo(1, 'name', '* * * * *', 0, 0, new DateTimeImmutable(), null, false);
		$result = new JobResult(new CronExpression('* * * * *'), new DateTimeImmutable(), JobResultState::done());
		$summary = new JobSummary($info, $result);

		$generator = (static function () use ($summary): Generator {
			yield $summary;

			return new RunSummary(new DateTimeImmutable(), new DateTimeImmutable(), [$summary], false);
		})();

		$yielded = iterator_to_array($callbacks->wrapGenerator($generator));

		self::assertSame([$summary], $yielded);
		self::assertCount(1, $fired);
		self::assertSame($info, $fired[0][0]);
		self::assertSame($result, $fired[0][1]);
	}

}
