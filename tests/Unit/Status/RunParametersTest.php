<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Status;

use Orisai\Scheduler\Status\RunParameters;
use PHPUnit\Framework\TestCase;

final class RunParametersTest extends TestCase
{

	public function test(): void
	{
		$second = 1;
		$parameters = new RunParameters($second);

		self::assertSame($second, $parameters->getSecond());
		self::assertEquals($parameters, RunParameters::fromArray($parameters->toArray()));
	}

}
