<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Job;

use Generator;
use Orisai\Exceptions\Logic\InvalidState;
use Orisai\Exceptions\Logic\NotImplemented;
use Orisai\Scheduler\Job\JobLock;
use Orisai\Scheduler\Job\SymfonyCommandJob;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Lock\NoLock;
use Tests\Orisai\Scheduler\Doubles\TestExceptionCommand;
use Tests\Orisai\Scheduler\Doubles\TestFailNoOutputCommand;
use Tests\Orisai\Scheduler\Doubles\TestFailOutputCommand;
use Tests\Orisai\Scheduler\Doubles\TestParametrizedCommand;
use Tests\Orisai\Scheduler\Doubles\TestSuccessCommand;
use Tests\Orisai\Scheduler\Helpers\CommandOutputHelper;

final class SymfonyCommandJobTest extends TestCase
{

	public function testSuccess(): void
	{
		$command = new TestSuccessCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyCommandJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:success%c', $job->getName());

		// No output, no need to assert
		$job->run(new JobLock(new NoLock()));
	}

	public function testFailNoOutput(): void
	{
		$command = new TestFailNoOutputCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyCommandJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:fail-no-output%c', $job->getName());

		$e = null;
		try {
			$job->run(new JobLock(new NoLock()));
		} catch (InvalidState $e) {
			// Handled bellow
		}

		self::assertNotNull($e);
		self::assertSame(1, $e->getCode());
		self::assertSame(
			<<<'MSG'
Context: Running command 'test:fail-no-output'.
Problem: Run failed with code '1'.
MSG,
			$e->getMessage(),
		);
		self::assertNull($e->getPrevious());
	}

	public function testFailOutput(): void
	{
		$command = new TestFailOutputCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyCommandJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:fail-output%c', $job->getName());

		$e = null;
		try {
			$job->run(new JobLock(new NoLock()));
		} catch (InvalidState $e) {
			// Handled bellow
		}

		self::assertNotNull($e);
		self::assertSame(256, $e->getCode());
		self::assertSame(
			<<<'MSG'
Context: Running command 'test:fail-output'.
Problem: Run failed with code '256'.
Output: Failure!
        New line!

MSG,
			CommandOutputHelper::getCommandOutput($e->getMessage()),
		);
		self::assertNull($e->getPrevious());
	}

	/**
	 * @dataProvider provideException
	 */
	public function testException(int $exceptionCode, int $commandCode): void
	{
		$command = new TestExceptionCommand($exceptionCode);
		$application = new Application();
		$application->add($command);
		$job = new SymfonyCommandJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:exception%c', $job->getName());

		$e = null;
		try {
			$job->run(new JobLock(new NoLock()));
		} catch (InvalidState $e) {
			// Handled bellow
		}

		self::assertNotNull($e);
		self::assertSame($commandCode, $e->getCode());
		self::assertStringMatchesFormat(
			<<<MSG
Context: Running command 'test:exception'.
Problem: Run failed with code '$commandCode'.
Output: Failure!

Suppressed errors:
- Orisai\Exceptions\Logic\NotImplemented created at %s with code $exceptionCode
  Message
MSG,
			CommandOutputHelper::getCommandOutput($e->getMessage()),
		);
		self::assertInstanceOf(NotImplemented::class, $e->getPrevious());
	}

	public function provideException(): Generator
	{
		yield [0, 1];
		yield [-1, 1];
		yield [2, 2];
		yield [256, 256];
	}

	/**
	 * @dataProvider provideApplicationSettingsHaveNoEffect
	 */
	public function testApplicationSettingsHaveNoEffect(bool $autoExit, bool $catchExceptions): void
	{
		$command = new TestExceptionCommand(1);
		$application = new Application();
		$application->setAutoExit($autoExit);
		$application->setCatchExceptions($catchExceptions);
		$application->add($command);
		$job = new SymfonyCommandJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:exception%c', $job->getName());

		$e = null;
		try {
			$job->run(new JobLock(new NoLock()));
		} catch (InvalidState $e) {
			// Handled bellow
		}

		self::assertNotNull($e);
		self::assertSame(1, $e->getCode());
		self::assertInstanceOf(NotImplemented::class, $e->getPrevious());
	}

	public function provideApplicationSettingsHaveNoEffect(): Generator
	{
		yield [true, true];
		yield [false, false];
		yield [true, false];
		yield [false, true];
	}

	public function testCommandNameCannotBeChanged(): void
	{
		$command = new TestSuccessCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyCommandJob($command, $application);
		// Is ignored
		$job->setCommandParameters(['command' => 'non-existent']);

		// No output, no need to assert
		$job->run(new JobLock(new NoLock()));

		/** @phpstan-ignore-next-line */
		self::assertTrue(true);
	}

	public function testCommandParameters(): void
	{
		$command = new TestParametrizedCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyCommandJob($command, $application);
		$job->setCommandParameters([
			'argument' => 'a',
			'--option' => 'b',
			'--bool-option' => true,
		]);

		self::assertStringMatchesFormat(
			'symfony/console: %ctest:parameters%c a --option=b --bool-option=1',
			$job->getName(),
		);

		// No output, no need to assert
		$job->run(new JobLock(new NoLock()));
	}

}
