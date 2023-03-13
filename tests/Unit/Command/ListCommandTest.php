<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Cron\CronExpression;
use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\ListCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
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
		$scheduler = new Scheduler();

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
		$scheduler = new Scheduler($clock);

		$job = new CallbackJob(static function (): void {
			// Noop
		});
		$scheduler->addJob($job, new CronExpression('* * * * *'));
		$scheduler->addJob($job, new CronExpression('*/30 7-15 * * 1-5'));
		$scheduler->addJob($job, new CronExpression('* * * 4 *'));
		$scheduler->addJob($job, new CronExpression('30 * 12 10 *'));

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
          * * * * *  Tests\Orisai\Scheduler\Unit\Command\{closure} Next Due: 59 seconds
  */30 7-15 * * 1-5  Tests\Orisai\Scheduler\Unit\Command\{closure} Next Due: 5 hours
          * * * 4 *  Tests\Orisai\Scheduler\Unit\Command\{closure} Next Due: 2 months
       30 * 12 10 *  Tests\Orisai\Scheduler\Unit\Command\{closure} Next Due: 9 months

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

		putenv('COLUMNS=100');
		$code = $tester->execute([]);

		self::assertSame(
			<<<'MSG'
          * * * * *  Tests\Orisai\Scheduler\Unit\Command\{closure}............ Next Due: 59 seconds
  */30 7-15 * * 1-5  Tests\Orisai\Scheduler\Unit\Command\{closure}............... Next Due: 5 hours
          * * * 4 *  Tests\Orisai\Scheduler\Unit\Command\{closure}.............. Next Due: 2 months
       30 * 12 10 *  Tests\Orisai\Scheduler\Unit\Command\{closure}.............. Next Due: 9 months

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

		putenv('COLUMNS=80');
		$code = $tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
          * * * * *  Tests\Orisai\Scheduler\Unit\Command\{closure} Next Due: 1970-01-01 01:01:00 +01:00
  */30 7-15 * * 1-5  Tests\Orisai\Scheduler\Unit\Command\{closure} Next Due: 1970-01-01 07:00:00 +01:00
          * * * 4 *  Tests\Orisai\Scheduler\Unit\Command\{closure} Next Due: 1970-04-01 00:00:00 +01:00
       30 * 12 10 *  Tests\Orisai\Scheduler\Unit\Command\{closure} Next Due: 1970-10-12 00:30:00 +01:00

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
