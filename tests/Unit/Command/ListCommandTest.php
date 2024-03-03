<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Generator;
use Orisai\Clock\FrozenClock;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Scheduler\Command\ListCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use function array_map;
use function explode;
use function implode;
use function putenv;
use function rtrim;
use const PHP_EOL;

/**
 * @runTestsInSeparateProcesses
 */
final class ListCommandTest extends TestCase
{

	public function testNoJobs(): void
	{
		$scheduler = new SimpleScheduler();

		$command = new ListCommand($scheduler);
		$tester = new CommandTester($command);

		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
No scheduled jobs have been defined.

MSG,
			$tester->getDisplay(),
		);
		self::assertSame($command::SUCCESS, $code);
	}

	public function testList(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('* * * 4 *'),
		);
		$scheduler->addJob(
			new CallbackJob($cbs->getClosure()),
			new CronExpression('30 * 12 10 *'),
		);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
          * * * 4 *  [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 2 months
          * * * * *  [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 59 seconds
  */30 7-15 * * 1-5  [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 5 hours
       30 * 12 10 *  [3] tests/Doubles/CallbackList.php:32.. Next Due: 9 months

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

		putenv('COLUMNS=110');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
          * * * 4 *  [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke().......... Next Due: 2 months
          * * * * *  [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()............ Next Due: 59 seconds
  */30 7-15 * * 1-5  [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()............... Next Due: 5 hours
       30 * 12 10 *  [3] tests/Doubles/CallbackList.php:32................................ Next Due: 9 months

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

		putenv('COLUMNS=120');
		$code = $tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
          * * * 4 *  [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke()... Next Due: 1970-04-01 00:00:00 +01:00
          * * * * *  [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()....... Next Due: 1970-01-01 01:01:00 +01:00
  */30 7-15 * * 1-5  [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()....... Next Due: 1970-01-01 07:00:00 +01:00
       30 * 12 10 *  [3] tests/Doubles/CallbackList.php:32......................... Next Due: 1970-10-12 00:30:00 +01:00

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

	public function testListWithSeconds(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
			null,
			1,
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('* * * 4 *'),
			null,
			5,
		);
		$scheduler->addJob(
			new CallbackJob($cbs->getClosure()),
			new CronExpression('30 * 12 10 *'),
			null,
			30,
		);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=120');
		$code = $tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
          * * * 4 * / 5   [3] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-04-01 00:00:00 +01:00
          * * * * *       [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1().. Next Due: 1970-01-01 01:01:00 +01:00
  */30 7-15 * * 1-5       [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1().. Next Due: 1970-01-01 07:00:00 +01:00
  */30 7-15 * * 1-5 / 1   [2] Tests\Orisai\Scheduler\Doubles\CallbackList::job1().. Next Due: 1970-01-01 07:00:00 +01:00
       30 * 12 10 * / 30  [4] tests/Doubles/CallbackList.php:32.................... Next Due: 1970-10-12 00:30:00 +01:00

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

	public function testNext(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * 2 *'));
		$scheduler->addJob($job, new CronExpression('* * 3 * *'));
		$scheduler->addJob($job, new CronExpression('* 3 * * *'));
		$scheduler->addJob($job, new CronExpression('2 * * * *'));
		$scheduler->addJob($job, new CronExpression('* * * * *'));
		$scheduler->addJob($job, new CronExpression('* * * * *'), null, 1);
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=100');
		$code = $tester->execute([
			'--next' => null,
		]);

		self::assertSame(
			<<<'MSG'
  * * * * * / 1  [5] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........ Next Due: 1 second
  * * * * *      [4] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()...... Next Due: 59 seconds
  * * * * *      [6] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()...... Next Due: 59 seconds
  2 * * * *      [3] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........ Next Due: 1 minute
  * 3 * * *      [2] Tests\Orisai\Scheduler\Doubles\CallbackList::job1().......... Next Due: 1 hour
  * * 3 * *      [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........... Next Due: 1 day
  * * * 2 *      [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()......... Next Due: 1 month

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

		$code = $tester->execute([
			'--next' => '4',
		]);

		self::assertSame(
			<<<'MSG'
  * * * * * / 1  [5] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........ Next Due: 1 second
  * * * * *      [4] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()...... Next Due: 59 seconds
  * * * * *      [6] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()...... Next Due: 59 seconds
  2 * * * *      [3] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........ Next Due: 1 minute

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

	public function testNextOverlap(): void
	{
		$clock = new FrozenClock(59, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * * *'), null, 1);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=100');
		$code = $tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
  * * * * * / 1  [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 1970-01-01 01:01:00 +01:00

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

	/**
	 * @dataProvider provideInvalidNext
	 */
	public function testInvalidNext(string $next): void
	{
		$scheduler = new SimpleScheduler();

		$command = new ListCommand($scheduler);
		$tester = new CommandTester($command);

		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(
			"Command 'scheduler:list' option --next expects an int value larger than 0, '$next' given.",
		);

		$tester->execute([
			'--next' => $next,
		]);
	}

	public function provideInvalidNext(): Generator
	{
		yield ['not-a-number'];
		yield ['1.0'];
		yield ['0'];
	}

	public function testTimeZone(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('UTC'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('0 1 * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('0 1 * * *'),
			null,
			0,
			new DateTimeZone('Europe/Prague'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('0 1 * * *'),
			null,
			0,
			new DateTimeZone('Australia/Sydney'),
		);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
  0 1 * * *                     [0] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-01 01:00:00 +00:00
  0 1 * * * (Europe/Prague)     [1] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-01 01:00:00 +00:00
  0 1 * * * (Australia/Sydney)  [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-01 01:00:00 +00:00

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

		$code = $tester->execute([
			'--timezone' => 'Europe/Prague',
		], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
  0 1 * * * (UTC)               [0] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-02 01:00:00 +01:00
  0 1 * * *                     [1] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-02 01:00:00 +01:00
  0 1 * * * (Australia/Sydney)  [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-02 01:00:00 +01:00

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

	public function testExplain(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
			null,
			0,
			new DateTimeZone('Europe/Prague'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
			null,
			0,
			new DateTimeZone('America/New_York'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('* * * 4 *'),
			null,
			10,
		);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([
			'--explain' => true,
		]);

		self::assertSame(
			<<<'MSG'
          * * * 4 * / 10                     [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 2 months
At every 10 seconds in April.
          * * * * *                          [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 59 seconds
At every minute.
  */30 7-15 * * 1-5      (America/New_York)  [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 5 hours
At every 30th minute past every hour from 7 through 15 on every day-of-week from Monday through Friday in America/New_York time zone.

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
