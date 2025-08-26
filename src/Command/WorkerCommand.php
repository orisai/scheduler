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
use function assert;
use function is_bool;
use function trim;
use function usleep;

/**
 * @infection-ignore-all
 */
final class WorkerCommand extends Command
{

	private Clock $clock;

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
		$binary = (new PhpExecutableFinder())->find();
		$script = $input->getOption('script') ?? $this->script;
		$command = $input->getOption('command') ?? $this->command;
		$force = $input->getOption('force');
		assert(is_bool($force));

		if ($binary === false) {
			throw InvalidState::create()
				->withMessage('PHP executable could not be found, subprocess cannot be executed.');
		}

		if (!$force && !$input->isInteractive()) {
			$output->writeln(
				'<error>CLI is non-interactive. If you are sure you can terminate the worker and want to'
				. ' bypass limitation, then use the --force option.</error>',
			);

			return self::FAILURE;
		}

		$output->writeln('<info>Running scheduled tasks every minute.</info>');

		$lastExecutionStartedAt = $this->nullSeconds($this->clock->now()->modify('-1 minute'));
		$executions = [];
		while (true) {
			usleep(100_000);

			$currentTime = $this->clock->now();

			if (
				(int) $currentTime->format('s') === 0
				&& $this->nullSeconds($currentTime) != $lastExecutionStartedAt
				&& $this->testRuns !== 0
			) {
				$executions[] = $execution = new Process([
					$binary,
					$script,
					$command,
				]);

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

			foreach ($executions as $key => $execution) {
				$this->writeOutput($output, $execution);

				if (!$execution->isRunning()) {
					$this->writeOutput($output, $execution); // Process may write right before finish
					unset($executions[$key]);
				}
			}

			if ($this->testRuns === 0 && $executions === []) {
				break;
			}
		}

		return self::SUCCESS;
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

}
