<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

use Orisai\Exceptions\Logic\InvalidState;
use Orisai\Exceptions\Message;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use function array_merge;
use function assert;
use function is_numeric;

final class SymfonyCommandJob implements Job
{

	private Command $command;

	private Application $application;

	/** @var array<int|string, mixed> */
	private array $parameters = [];

	private ?float $lockTtl = null;

	public function __construct(Command $command, Application $application)
	{
		$this->command = $command;
		$this->application = $application;
	}

	/**
	 * @param array<int|string, mixed> $parameters
	 */
	public function setCommandParameters(array $parameters): void
	{
		$this->parameters = $parameters;
	}

	/**
	 * Set lock time to live in seconds
	 */
	public function setLockTtl(float $lockTtl): void
	{
		$this->lockTtl = $lockTtl;
	}

	public function getName(): string
	{
		$name = $this->command->getName();
		assert($name !== null); // It must be set in constructor

		return 'symfony/console: ' . $this->createInput();
	}

	public function run(JobLock $lock): void
	{
		if ($this->lockTtl !== null) {
			$lock->refresh($this->lockTtl);
		}

		$input = $this->createInput();
		$output = new BufferedOutput();

		try {
			// Using doRun() to prevent auto-exiting and error-handling
			$exitCode = $this->application->doRun($input, $output);
		} catch (Throwable $commandException) {
			$exitCode = $this->getExceptionCode($commandException);
		}

		$outputString = $output->fetch();

		if ($exitCode !== 0) {
			$message = Message::create()
				->withContext("Running command '{$this->command->getName()}'.")
				->withProblem("Run failed with code '$exitCode'.");

			if ($outputString !== '') {
				$message->with('Output', $outputString);
			}

			$exception = InvalidState::create()
				->withCode($exitCode)
				->withMessage($message);

			if (isset($commandException)) {
				$exception->withPrevious($commandException)
					->withSuppressed([$commandException]);
			}

			throw $exception;
		}
	}

	private function createInput(): ArrayInput
	{
		return new ArrayInput(array_merge(
			[$this->command->getName()],
			$this->parameters,
		));
	}

	private function getExceptionCode(Throwable $exception): int
	{
		$exitCode = $exception->getCode();

		if (is_numeric($exitCode)) {
			$exitCode = (int) $exitCode;
			if ($exitCode <= 0) {
				$exitCode = 1;
			}

			return $exitCode;
		}

		/** @codeCoverageIgnore Hard to simulate, only php extensions can return non-int code */
		return 1;
	}

}
