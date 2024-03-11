<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Generator;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\ExplainCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Tests\Orisai\Scheduler\Helpers\CommandOutputHelper;

final class ExplainCommandTest extends TestCase
{

	public function testBasicExplain(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$command = new ExplainCommand($scheduler);
		$tester = new CommandTester($command);

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
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $code);
	}

	public function testExplainId(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
			'one',
			0,
			new DateTimeZone('Europe/Prague'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
			'two',
			0,
			new DateTimeZone('America/New_York'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('* * * 4 *'),
			'three',
			10,
		);

		$command = new ExplainCommand($scheduler, null, $clock);
		$tester = new CommandTester($command);

		$code = $tester->execute([
			'--id' => 'non-existent',
		]);

		self::assertSame(
			<<<'MSG'
Job with id 'non-existent' does not exist.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::FAILURE, $code);

		$code = $tester->execute([
			'--id' => 'one',
		]);

		self::assertSame(
			<<<'MSG'
At every minute.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $code);

		$code = $tester->execute([
			'--id' => 'two',
		]);

		self::assertSame(
			<<<'MSG'
At every 30th minute past every hour from 7 through 15 on every day-of-week from Monday through Friday in America/New_York time zone.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $code);

		$code = $tester->execute([
			'--id' => 'three',
		]);

		self::assertSame(
			<<<'MSG'
At every 10 seconds in April.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $code);
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @dataProvider provideExplainExpression
	 */
	public function testExplainExpression(array $input, string $output): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$command = new ExplainCommand($scheduler, null, $clock);
		$tester = new CommandTester($command);

		$tester->execute($input);

		self::assertSame($output, CommandOutputHelper::getCommandOutput($tester));
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function provideExplainExpression(): Generator
	{
		yield [
			[
				'--expression' => '* * * * *',
			],
			<<<'MSG'
At every minute.

MSG,
		];

		yield [
			[
				'--expression' => '* * * * *',
				'--seconds' => '0',
			],
			<<<'MSG'
At every minute.

MSG,
		];

		yield [
			[
				'--expression' => '* * * * *',
				'--seconds' => '1',
			],
			<<<'MSG'
At every second.

MSG,
		];

		yield [
			[
				'--expression' => '* * * * *',
				'--timezone' => 'Europe/Prague',
			],
			<<<'MSG'
At every minute in Europe/Prague time zone.

MSG,
		];

		yield [
			[
				'--expression' => '* * * * *',
				'--seconds' => '59',
				'--timezone' => 'UTC',
			],
			<<<'MSG'
At every 59 seconds in UTC time zone.

MSG,
		];

		yield [
			[
				'-e' => '* * * * *',
				'-s' => '59',
				'-tz' => 'UTC',
			],
			<<<'MSG'
At every 59 seconds in UTC time zone.

MSG,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @dataProvider provideInputError
	 */
	public function testInputError(array $input, string $output): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$command = new ExplainCommand($scheduler, null, $clock);
		$tester = new CommandTester($command);

		$tester->execute($input);

		self::assertSame($output, CommandOutputHelper::getCommandOutput($tester));
		self::assertSame($command::FAILURE, $tester->getStatusCode());
	}

	public function provideInputError(): Generator
	{
		yield [
			[
				'--id' => 'id',
				'--expression' => '* * * * *',
			],
			<<<'MSG'
Options --id and --expression cannot be combined.

MSG,
		];

		yield [
			[
				'--expression' => 'bad-expression',
			],
			<<<'MSG'
bad-expression is not a valid CRON expression

MSG,
		];

		yield [
			[
				'--expression' => '* * * * *',
				'--seconds' => '1.2',
			],
			<<<'MSG'
Option --seconds expects an int<0, 59>, '1.2' given.

MSG,
		];

		yield [
			[
				'--expression' => '* * * * *',
				'--seconds' => '-1',
			],
			<<<'MSG'
Option --seconds expects an int<0, 59>, '-1' given.

MSG,
		];

		yield [
			[
				'--expression' => '* * * * *',
				'--seconds' => '60',
			],
			<<<'MSG'
Option --seconds expects an int<0, 59>, '60' given.

MSG,
		];

		yield [
			[
				'--seconds' => '5',
			],
			<<<'MSG'
Option --seconds must be used with --expression.

MSG,
		];

		yield [
			[
				'--expression' => '* * * * *',
				'--timezone' => 'bad-timezone',
			],
			<<<'MSG'
Option --timezone expects a valid timezone, 'bad-timezone' given.

MSG,
		];

		yield [
			[
				'--timezone' => 'Europe/Prague',
			],
			<<<'MSG'
Option --timezone must be used with --expression.

MSG,
		];

		yield [
			[
				'--id' => 'id',
				'--seconds' => 'bad seconds',
				'--timezone' => 'bad timezone',
			],
			<<<'MSG'
Option --seconds expects an int<0, 59>, 'bad seconds' given.
Option --seconds cannot be used with --id.
Option --seconds must be used with --expression.
Option --timezone expects a valid timezone, 'bad timezone' given.
Option --timezone cannot be used with --id.
Option --timezone must be used with --expression.

MSG,
		];
	}

}
