<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Exception;

use Cron\CronExpression;
use DateTimeImmutable;
use Exception;
use Orisai\Scheduler\Exception\JobFailure;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
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
		$exception = new Exception('test');

		$failure = JobFailure::create($info, $result, $exception);
		self::assertSame($info, $failure->getInfo());
		self::assertSame($result, $failure->getResult());
		self::assertSame($exception, $failure->getPrevious());
		self::assertSame([$exception], $failure->getSuppressed());
		self::assertStringStartsWith('Job failed', $failure->getMessage());
	}

}
