<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Orisai\Scheduler\Command\ExplainCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use function array_map;
use function explode;
use function implode;
use function putenv;
use function rtrim;
use const PHP_EOL;

final class ExplainCommandTest extends TestCase
{

	public function testBasicExplain(): void
	{
		$command = new ExplainCommand();
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
*   *   *   *   *
-   -   -   -   -
|   |   |   |   |
|   |   |   |   |
|   |   |   |   +----- day of week (0-7) (Sunday = 0 or 7) (or SUN-SAT)
|   |   |   +--------- month (1-12) (or JAN-DEC)
|   |   +------------- day of month (1-31)
|   +----------------- hour (0-23)
+--------------------- minute (0-59)

Each part of expression can also use wildcard, lists, ranges and steps:

- wildcard - match always
  - e.g. * * * * * - At every minute.
- lists - match list of values, ranges and steps
  - e.g. 15,30 * * * * - At minute 15 and 30.
- ranges - match values in range
  - e.g. 1-9 * * * * - At every minute from 1 through 9.
- steps - match every nth value in range
  - e.g. */5 * * * * - At every 5th minute.
  - e.g. 0-30/5 * * * * - At every 5th minute from 0 through 30.
- combinations
  - e.g. 0-14,30-44 * * * * - At every minute from 0 through 14 and every minute from 30 through 44.

You can also use macro instead of an expression:

- @yearly, @annually - Run once a year, midnight, Jan. 1 (same as 0 0 1 1 *)
- @monthly - Run once a month, midnight, first of month (same as 0 0 1 * *)
- @weekly - Run once a week, midnight on Sun (same as 0 0 * * 0)
- @daily, @midnight - Run once a day, midnight (same as 0 0 * * *)
- @hourly - Run once an hour, first minute (same as 0 * * * *)

Although they are not part of cron expression syntax, you can also add to job:

- seconds - repeat job every n seconds
- timezone - run only when cron expression matches within given timezone

MSG,
			implode(
				PHP_EOL,
				array_map(
					static fn (string $s): string => rtrim($s),
					explode(PHP_EOL, $tester->getDisplay()),
				),
			),
		);
		self::assertSame($command::SUCCESS, $code);
	}

}
