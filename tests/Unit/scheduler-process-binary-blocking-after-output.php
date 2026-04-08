<?php declare(strict_types = 1);

use Cron\CronExpression;
use Orisai\Scheduler\Command\RunJobCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use Symfony\Component\Console\Application;

require_once __DIR__ . '/../../vendor/autoload.php';

$scheduler = new SimpleScheduler();
$scheduler->addJob(
	new CallbackJob(static function (): void {
		// Job finishes instantly
	}),
	new CronExpression('* * * * *'),
);

$command = new RunJobCommand($scheduler);

$application = new Application();
$application->addCommands([$command]);
$application->setAutoExit(false);
$application->run();

// Process stays alive after writing JSON output.
// Force-kill test verifies JSON is readable from a killed-but-complete process.
// sleep() blocks reliably and is terminated by SIGTERM from Process::stop().
// Short duration so orphaned processes (from mutation testing) self-terminate.
sleep(15);
