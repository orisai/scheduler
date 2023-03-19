<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Exception;

use Cron\CronExpression;
use DateTimeImmutable;
use Exception;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use PHPUnit\Framework\TestCase;

final class JobFailureTest extends TestCase
{

	public function test(): void
	{
		$info = new JobInfo('name', '* * * * *', new DateTimeImmutable());
		$result = new JobResult(
			new CronExpression('* * * * *'),
			new DateTimeImmutable(),
			JobResultState::fail(),
		);
		$summary = new JobSummary($info, $result);
		$exception = new Exception('test');

		$failure = JobFailure::create($summary, $exception);
		self::assertSame($summary, $failure->getSummary());
		self::assertSame($exception, $failure->getPrevious());
		self::assertSame([$exception], $failure->getSuppressed());
		self::assertStringStartsWith('Job failed', $failure->getMessage());
	}

}
