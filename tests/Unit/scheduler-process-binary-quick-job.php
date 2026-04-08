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
		// Quick job - finishes immediately
	}),
	new CronExpression('* * * * *'),
	'quick-job',
);

$command = new RunJobCommand($scheduler);

$application = new Application();
$application->addCommands([$command]);

$application->run();
