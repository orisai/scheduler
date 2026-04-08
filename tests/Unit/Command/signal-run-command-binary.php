<?php declare(strict_types = 1);

use Cron\CronExpression;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\RunRegistry\FileRunRegistry;
use Orisai\Scheduler\SimpleScheduler;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Tests\Orisai\Scheduler\Doubles\FileExistsMaintenanceChecker;

require_once __DIR__ . '/../../../vendor/autoload.php';

$maintenanceFile = $argv[1] ?? '/tmp/nonexistent';
$registryDir = $argv[2] ?? '/tmp/scheduler-signal-test';
$gracePeriod = (int) ($argv[3] ?? '30');
$readyFile = $argv[4] ?? null;

$checker = new FileExistsMaintenanceChecker($maintenanceFile);
$registry = new FileRunRegistry($registryDir);
$manager = new MaintenanceManager($checker, $gracePeriod);

$executor = new ProcessJobExecutor();
$executor->setExecutable(__DIR__ . '/../scheduler-process-binary-sleeping-job.php');

$scheduler = new SimpleScheduler(null, null, $executor, null, null, $manager, $registry);
$scheduler->addJob(
	new CallbackJob(static function (): void {
		sleep(15);
	}),
	new CronExpression('* * * * *'),
);

// Signal readiness to parent via beforeRun callback - at this point signal handlers are registered
if ($readyFile !== null) {
	$scheduler->addBeforeRunCallback(static function () use ($readyFile): void {
		touch($readyFile);
	});
}

$command = new RunCommand($scheduler, null, $manager);

$application = new Application();
$application->setAutoExit(false);
$application->addCommands([$command]);

$exitCode = $application->run(
	new ArrayInput(['command' => 'scheduler:run']),
	new ConsoleOutput(),
);

exit($exitCode);
