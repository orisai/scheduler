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
		$scheduler = new SimpleScheduler(null, $clock);

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
          * * * 4 *  Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 2 months
          * * * * *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 59 seconds
  */30 7-15 * * 1-5  Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 5 hours
       30 * 12 10 *  tests/Doubles/CallbackList.php:32...... Next Due: 9 months

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
          * * * 4 *  Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke().............. Next Due: 2 months
          * * * * *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()................ Next Due: 59 seconds
  */30 7-15 * * 1-5  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()................... Next Due: 5 hours
       30 * 12 10 *  tests/Doubles/CallbackList.php:32.................................... Next Due: 9 months

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
          * * * 4 *  Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke()....... Next Due: 1970-04-01 00:00:00 +01:00
          * * * * *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........... Next Due: 1970-01-01 01:01:00 +01:00
  */30 7-15 * * 1-5  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........... Next Due: 1970-01-01 07:00:00 +01:00
       30 * 12 10 *  tests/Doubles/CallbackList.php:32............................. Next Due: 1970-10-12 00:30:00 +01:00

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
		$scheduler = new SimpleScheduler(null, $clock);

		$cbs = new CallbackList();
		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * 4 *'));
		$scheduler->addJob($job, new CronExpression('* * * 4 *'));
		$scheduler->addJob($job, new CronExpression('* * * 2 *'));
		$scheduler->addJob($job, new CronExpression('* * * 7 *'));
		$scheduler->addJob($job, new CronExpression('* * * 1 *'));
		$scheduler->addJob($job, new CronExpression('* * * 6 *'));

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=100');
		$code = $tester->execute([
			'--next' => null,
		]);

		self::assertSame(
			<<<'MSG'
  * * * 1 *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1().............. Next Due: 59 seconds
  * * * 2 *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()................. Next Due: 1 month
  * * * 4 *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()................ Next Due: 2 months
  * * * 4 *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()................ Next Due: 2 months
  * * * 6 *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()................ Next Due: 5 months
  * * * 7 *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()................ Next Due: 6 months

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
			'--next' => '2',
		]);

		self::assertSame(
			<<<'MSG'
  * * * 1 *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1().............. Next Due: 59 seconds
  * * * 2 *  Tests\Orisai\Scheduler\Doubles\CallbackList::job1()................. Next Due: 1 month

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

}
