<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use DateTimeImmutable;
use Orisai\Clock\SystemClock;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use function array_map;
use function escapeshellarg;
use function implode;
use function ltrim;
use function usleep;
use const PHP_BINARY;

final class WorkerCommand extends Command
{

	private ClockInterface $clock;

	public function __construct(?ClockInterface $clock = null)
	{
		parent::__construct();
		$this->clock = $clock ?? new SystemClock();
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
			'executable',
			'e',
			InputOption::VALUE_REQUIRED,
			'Executable file for executing console commands',
			'bin/console',
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('<info>Running scheduled tasks every minute.</info>');

		$command = implode(' ', array_map(static fn (string $arg) => escapeshellarg($arg), [
			PHP_BINARY,
			$input->getOption('executable'),
			'scheduler:run',
		]));

		$lastExecutionStartedAt = $this->nullSeconds($this->clock->now()->modify('-1 minute'));
		$executions = [];
		while (true) {
			usleep(100_000);

			$currentTime = $this->clock->now();

			if (
				(int) $currentTime->format('s') === 0
				&& $this->nullSeconds($this->clock->now()) != $lastExecutionStartedAt
			) {
				$executions[] = $execution = Process::fromShellCommandline($command);

				if (Process::isTtySupported()) {
					$execution->setTty(true);
				} elseif (Process::isPtySupported()) {
					$execution->setPty(true);
				}

				$execution->start();

				$lastExecutionStartedAt = $this->nullSeconds($this->clock->now());
			}

			foreach ($executions as $key => $execution) {
				$executionOutput = $execution->getIncrementalOutput()
					. $execution->getIncrementalErrorOutput();

				$output->write(ltrim($executionOutput, "\n"));

				if (!$execution->isRunning()) {
					unset($executions[$key]);
				}
			}
		}
	}

	private function nullSeconds(DateTimeImmutable $dt): DateTimeImmutable
	{
		return $dt->setTime(
			(int) $dt->format('H'),
			(int) $dt->format('i'),
		);
	}

}
