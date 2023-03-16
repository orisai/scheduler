<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Cron\CronExpression;
use DateTimeImmutable;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;

final class RunSummaryTest extends TestCase
{

	public function test(): void
	{
		$jobs = [
			[
				new JobInfo('1', '* * * * *', new DateTimeImmutable()),
				new JobResult(new CronExpression('* * * * *'), new DateTimeImmutable(), JobResultState::done()),
			],
			[
				new JobInfo('2', '1 * * * *', new DateTimeImmutable()),
				new JobResult(new CronExpression('1 * * * *'), new DateTimeImmutable(), JobResultState::done()),
			],
		];

		$summary = new RunSummary($jobs);
		self::assertSame(
			$jobs,
			$summary->getJobs(),
		);
	}

}
