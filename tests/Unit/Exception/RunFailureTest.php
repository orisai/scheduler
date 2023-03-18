<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Exception;

use Error;
use Exception;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;

final class RunFailureTest extends TestCase
{

	public function test(): void
	{
		$summary = new RunSummary([]);
		$suppressed = [
			new Exception(),
			new Error(),
		];

		$failure = RunFailure::create($summary, $suppressed);
		self::assertSame($summary, $failure->getSummary());
		self::assertSame($suppressed, $failure->getSuppressed());
		self::assertStringStartsWith('Run failed', $failure->getMessage());
	}

}
