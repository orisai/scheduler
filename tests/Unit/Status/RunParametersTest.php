<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Generator;
use Orisai\Scheduler\Status\RunParameters;
use PHPUnit\Framework\TestCase;

final class RunParametersTest extends TestCase
{

	/**
	 * @param int<0, max> $second
	 *
	 * @dataProvider provide
	 */
	public function test(int $second, bool $manualRun): void
	{
		$parameters = new RunParameters($second, $manualRun);

		self::assertSame($second, $parameters->getSecond());
		self::assertSame($manualRun, $parameters->isManualRun());
		self::assertEquals($parameters, RunParameters::fromArray($parameters->toArray()));
	}

	public function provide(): Generator
	{
		yield [1, false];
		yield [10, true];
	}

}
