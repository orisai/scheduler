<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Cron\CronExpression;
use DateTimeImmutable;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use PHPUnit\Framework\TestCase;

final class JobSummaryTest extends TestCase
{

	public function test(): void
	{
		$info = new JobInfo('id', 'name', '* * * * *', 0, new DateTimeImmutable());
		$result = new JobResult(
			new CronExpression('* * * * *'),
			new DateTimeImmutable(),
			JobResultState::done(),
		);

		$summary = new JobSummary($info, $result);
		self::assertSame($info, $summary->getInfo());
		self::assertSame($result, $summary->getResult());

		self::assertSame(
			[
				'info' => $summary->getInfo()->toArray(),
				'result' => $summary->getResult()->toArray(),
			],
			$summary->toArray(),
		);
	}

}
