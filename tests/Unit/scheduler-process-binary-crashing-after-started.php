<?php declare(strict_types = 1);

use Cron\CronExpression;
use Orisai\Scheduler\Command\RunJobCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use Symfony\Component\Console\Application;

require_once __DIR__ . '/../../vendor/autoload.php';

// Simulates a subprocess that emits `started` (via runInternal's onJobStarted hook)
// then exits abruptly before `finished` — e.g. fatal error, OOM, segfault, explicit
// exit() from user code. The parent must still fire `afterJob` for this job
// (with matching executionId) and surface the crash via RunFailure.
$scheduler = new SimpleScheduler();
$scheduler->addJob(
	new CallbackJob(static function (): void {
		exit(1);
	}),
	new CronExpression('* * * * *'),
);

$command = new RunJobCommand($scheduler);

$application = new Application();
$application->addCommands([$command]);

$application->run();
