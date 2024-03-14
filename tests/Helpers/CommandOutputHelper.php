<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Helpers;

use Symfony\Component\Console\Tester\CommandTester;
use function array_map;
use function assert;
use function explode;
use function implode;
use function preg_replace;
use function rtrim;
use const PHP_EOL;

final class CommandOutputHelper
{

	/**
	 * @param string|CommandTester $output
	 */
	public static function getCommandOutput($output): string
	{
		if ($output instanceof CommandTester) {
			$output = $output->getDisplay();
		}

		$display = preg_replace('~\R~u', PHP_EOL, $output);
		assert($display !== null);

		return implode(
			PHP_EOL,
			array_map(
				static fn (string $s): string => rtrim($s),
				explode(PHP_EOL, $display),
			),
		);
	}

}
