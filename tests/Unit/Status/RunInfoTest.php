<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use DateTimeImmutable;
use Generator;
use Orisai\Scheduler\Status\PlannedJobInfo;
use Orisai\Scheduler\Status\RunInfo;
use PHPUnit\Framework\TestCase;

final class RunInfoTest extends TestCase
{

	/**
	 * @param list<PlannedJobInfo> $jobInfos
	 *
	 * @dataProvider provide
	 */
	public function test(DateTimeImmutable $start, array $jobInfos): void
	{
		$info = new RunInfo($start, $jobInfos);

		self::assertSame($start, $info->getStart());
		self::assertSame($jobInfos, $info->getJobInfos());
	}

	public function provide(): Generator
	{
		yield [
			new DateTimeImmutable(),
			[
				new PlannedJobInfo('id', 'name', '* * * * *', 0, new DateTimeImmutable()),
			],
		];

		yield [
			new DateTimeImmutable('1 month ago'),
			[
				new PlannedJobInfo('id', 'name', '* * * * *', 0, new DateTimeImmutable()),
				new PlannedJobInfo('id', 'name', '* * * * *', 0, new DateTimeImmutable()),
			],
		];
	}

}
