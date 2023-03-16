<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Exception;

use Error;
use Exception;
use Orisai\Scheduler\Exception\JobsExecutionFailure;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;

final class JobsExecutionFailureTest extends TestCase
{

	public function test(): void
	{
		$summary = new RunSummary([]);
		$suppressed = [
			new Exception(),
			new Error(),
		];

		$failure = JobsExecutionFailure::create($summary, $suppressed);
		self::assertSame($summary, $failure->getSummary());
		self::assertStringStartsWith('Executed jobs failed', $failure->getMessage());
	}

}
