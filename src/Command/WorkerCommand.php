<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Closure;
use DateTimeImmutable;
use Orisai\Clock\Adapter\ClockAdapterFactory;
use Orisai\Clock\Clock;
use Orisai\Clock\SystemClock;
use Orisai\Exceptions\Logic\InvalidState;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use function array_merge;
use function assert;
use function function_exists;
use function is_bool;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function trim;
use function usleep;
use const SIGINT;
use const SIGTERM;

/**
 * @infection-ignore-all
 */
final class WorkerCommand extends Command
{

	private Clock $clock;

	private bool $shouldStop = false;

	private ?int $testRuns = null;

	/** @var Closure(): void|null */
	private ?Closure $testCb = null;

	private string $script = 'bin/console';

	private string $command = 'scheduler:run';

	public function __construct(?ClockInterface $clock = null)
	{
		parent::__construct();
		$this->clock = ClockAdapterFactory::create($clock ?? new SystemClock());
	}

	public function setExecutable(string $script, string $command = 'scheduler:run'): void
	{
		$this->script = $script;
		$this->command = $command;
	}

	public static function getDefaultName(): string
	{
		return 'scheduler:worker';
	}

	public static function getDefaultDescription(): string
	{
		return 'Start the scheduler worker';
	}

	protected function configure(): void
	{
		parent::configure();
		$this->addOption(
			'script',
			's',
			InputOption::VALUE_REQUIRED,
			'Executable file for executing console commands',
		);
		$this->addOption(
			'command',
			'c',
			InputOption::VALUE_REQUIRED,
			'Name of executed command',
		);
		$this->addOption(
			'force',
			null,
			InputOption::VALUE_NONE,
			'Force run in non-interactive environment',
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$previousHandlers = $this->registerSignalHandlers($output);

		$finder = new PhpExecutableFinder();
		$binary = $finder->find(false);
		$script = $input->getOption('script') ?? $this->script;
		$command = $input->getOption('command') ?? $this->command;
		$force = $input->getOption('force');
		assert(is_bool($force));

		// @codeCoverageIgnoreStart
		if ($binary === false) {
			throw InvalidState::create()
				->withMessage('PHP executable could not be found, subprocess cannot be executed.');
		}

		// @codeCoverageIgnoreEnd

		$phpCommand = array_merge([$binary], $finder->findArguments(), [$script, $command]);

		if (!$force && !$input->isInteractive()) {
			$output->writeln(
				'<error>CLI is non-interactive. If you are sure you can terminate the worker and want to'
				. ' bypass limitation, then use the --force option.</error>',
			);

			return self::FAILURE;
		}

		$output->writeln('<info>Running scheduled tasks every minute.</info>');

		// First iteration spawns immediately — subsequent iterations wait for the next
		// minute boundary. The minute-scoped lock (see Multi-server protection) prevents
		// duplicate runs if another server is already handling the current minute.
		$immediate = true;
		$lastExecutionStartedAt = $this->nullSeconds($this->clock->now()->modify('-1 minute'));
		$executions = [];
		while (true) {
			usleep(100_000);

			if ($this->shouldStop) {
				break;
			}

			$currentTime = $this->clock->now();

			$minuteBoundaryReached = (int) $currentTime->format('s') === 0
				&& $this->nullSeconds($currentTime)->format('U') !== $lastExecutionStartedAt->format('U');

			if (($immediate || $minuteBoundaryReached) && $this->testRuns !== 0) {
				$immediate = false;
				$executions[] = $execution = new Process($phpCommand);

				// @codeCoverageIgnoreStart
				if (Process::isTtySupported()) {
					$execution->setTty(true);
				} elseif (Process::isPtySupported()) {
					$execution->setPty(true);
				}

				// @codeCoverageIgnoreEnd

				$execution->start();
				$lastExecutionStartedAt = $this->nullSeconds($this->clock->now());

				if ($this->testRuns !== null) {
					$this->testRuns--;
					assert($this->testCb !== null);
					($this->testCb)();
				}
			}

			$this->processExecutions($executions, $output);

			if ($this->testRuns === 0 && $executions === []) {
				break;
			}
		}

		// Wait for running subprocesses to finish
		while ($executions !== []) {
			$this->processExecutions($executions, $output);

			usleep(100_000);
		}

		if ($this->shouldStop) {
			$output->writeln('<info>Scheduler worker stopped.</info>');
		}

		$this->restoreSignalHandlers($previousHandlers);

		return self::SUCCESS;
	}

	/**
	 * @param array<Process> $executions
	 * @param-out array<Process> $executions
	 */
	private function processExecutions(array &$executions, OutputInterface $output): void
	{
		foreach ($executions as $key => $execution) {
			$this->writeOutput($output, $execution);

			if (!$execution->isRunning()) {
				$this->writeOutput($output, $execution); // Process may write right before finish
				unset($executions[$key]);
			}
		}
	}

	/**
	 * @return array{(callable(): mixed)|int, (callable(): mixed)|int}|null
	 */
	private function registerSignalHandlers(OutputInterface $output): ?array
	{
		if (!function_exists('pcntl_async_signals')) {
			return null;
		}

		pcntl_async_signals(true);

		$previousTermHandler = pcntl_signal_get_handler(SIGTERM);
		$previousIntHandler = pcntl_signal_get_handler(SIGINT);

		$handler = function (): void {
			// @codeCoverageIgnoreStart
			// Tested via real subprocess in SignalHandlingTest, but coverage is not collected from subprocesses
			if ($this->shouldStop) {
				exit(1);
			}

			// @codeCoverageIgnoreEnd
			$this->shouldStop = true;
		};

		pcntl_signal(SIGTERM, $handler);
		pcntl_signal(SIGINT, $handler);

		return [$previousTermHandler, $previousIntHandler];
	}

	/**
	 * @param array{(callable(): mixed)|int, (callable(): mixed)|int}|null $previousHandlers
	 */
	private function restoreSignalHandlers(?array $previousHandlers): void
	{
		if ($previousHandlers === null) {
			return; // @codeCoverageIgnore
		}

		pcntl_signal(SIGTERM, $previousHandlers[0]);
		pcntl_signal(SIGINT, $previousHandlers[1]);
	}

	private function writeOutput(OutputInterface $output, Process $process): void
	{
		$stdout = trim($process->getIncrementalOutput());
		if ($stdout !== '') {
			$output->writeln($stdout);
		}

		$stderr = trim($process->getIncrementalErrorOutput());
		if ($stderr !== '') {
			$output->writeln($stderr);
		}
	}

	private function nullSeconds(DateTimeImmutable $dt): DateTimeImmutable
	{
		return $dt->setTime(
			(int) $dt->format('H'),
			(int) $dt->format('i'),
		);
	}

	/**
	 * @param Closure(): void $cb
	 * @param-later-invoked-callable $cb
	 *
	 * @internal
	 */
	public function enableTestMode(int $runs, Closure $cb): void
	{
		$this->testRuns = $runs;
		$this->testCb = $cb;
	}

	/**
	 * @internal
	 */
	public function requestStop(): void
	{
		$this->shouldStop = true;
	}

}
