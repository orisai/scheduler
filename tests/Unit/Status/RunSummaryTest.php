<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Cron\CronExpression;
use DateTimeImmutable;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;

final class RunSummaryTest extends TestCase
{

	public function test(): void
	{
		$start = new DateTimeImmutable();
		$end = new DateTimeImmutable();
		$jobSummaries = [
			new JobSummary(
				new JobInfo('id', '1', '* * * * *', new DateTimeImmutable()),
				new JobResult(new CronExpression('* * * * *'), new DateTimeImmutable(), JobResultState::done()),
			),
			new JobSummary(
				new JobInfo('id', '2', '1 * * * *', new DateTimeImmutable()),
				new JobResult(new CronExpression('1 * * * *'), new DateTimeImmutable(), JobResultState::done()),
			),
		];

		$summary = new RunSummary($start, $end, $jobSummaries);
		self::assertSame($start, $summary->getStart());
		self::assertSame($end, $summary->getEnd());
		self::assertSame(
			$jobSummaries,
			$summary->getJobSummaries(),
		);
	}

}
