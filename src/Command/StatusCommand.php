<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\RunRegistry\ActiveRun;
use Orisai\Scheduler\RunRegistry\RunRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function count;
use function implode;
use function json_encode;
use function time;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class StatusCommand extends Command
{

	private RunRegistry $runRegistry;

	private ?MaintenanceManager $maintenanceManager;

	public function __construct(RunRegistry $runRegistry, ?MaintenanceManager $maintenanceManager = null)
	{
		parent::__construct();
		$this->runRegistry = $runRegistry;
		$this->maintenanceManager = $maintenanceManager;
	}

	public static function getDefaultName(): string
	{
		return 'scheduler:status';
	}

	public static function getDefaultDescription(): string
	{
		return 'Show scheduler maintenance status';
	}

	protected function configure(): void
	{
		$this->addOption('json', null, InputOption::VALUE_NONE, 'Output in json format');
		$this->addOption(
			'fail-when-not-ready-for-shutdown',
			null,
			InputOption::VALUE_NONE,
			'Exit with non-zero code when not ready for shutdown (for deploy scripts)',
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$activeRuns = $this->runRegistry->getActiveRuns();
		$failWhenNotReady = (bool) $input->getOption('fail-when-not-ready-for-shutdown');

		$maintenance = $this->maintenanceManager !== null
			? $this->maintenanceManager->isMaintenance()
			: null;

		$readyForShutdown = $maintenance === true && $activeRuns === [];

		if ((bool) $input->getOption('json')) {
			$output->writeln(json_encode([
				'maintenance' => $maintenance,
				'activeRuns' => $this->serializeActiveRuns($activeRuns),
				'readyForShutdown' => $readyForShutdown,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

			return $failWhenNotReady && !$readyForShutdown ? self::FAILURE : self::SUCCESS;
		}

		if ($maintenance === null) {
			$output->writeln('Maintenance: <fg=yellow>UNAVAILABLE</>');
		} elseif ($maintenance) {
			$output->writeln('Maintenance: <fg=red>ACTIVE</>');
		} else {
			$output->writeln('Maintenance: <fg=green>INACTIVE</>');
		}

		$output->writeln('Active runs: ' . count($activeRuns));

		$now = time();
		foreach ($activeRuns as $run) {
			$parts = [];

			$pid = $run->getPid();
			if ($pid !== null) {
				$parts[] = "PID $pid";
			}

			$startTimestamp = $run->getStartTimestamp();
			if ($startTimestamp !== null) {
				$elapsed = $now - $startTimestamp;
				$parts[] = "started {$elapsed}s ago";
			}

			$detail = $parts !== [] ? ' (' . implode(', ', $parts) . ')' : '';
			$output->writeln("  - {$run->getId()}$detail");
		}

		$readyLabel = $readyForShutdown
			? '<fg=green>YES</>'
			: '<fg=red>NO</>';
		$output->writeln("Ready for shutdown: $readyLabel");

		return $failWhenNotReady && !$readyForShutdown ? self::FAILURE : self::SUCCESS;
	}

	/**
	 * @param list<ActiveRun> $activeRuns
	 * @return list<array{id: string, pid: int|null, startTimestamp: int|null}>
	 */
	private function serializeActiveRuns(array $activeRuns): array
	{
		$result = [];
		foreach ($activeRuns as $run) {
			$result[] = [
				'id' => $run->getId(),
				'pid' => $run->getPid(),
				'startTimestamp' => $run->getStartTimestamp(),
			];
		}

		return $result;
	}

}
