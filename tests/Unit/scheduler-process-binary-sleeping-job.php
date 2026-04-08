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
		// Long enough for the test to trigger force-kill.
		// Short enough that orphaned processes (from mutation testing) self-terminate.
		sleep(15);
	}),
	new CronExpression('* * * * *'),
);

$command = new RunJobCommand($scheduler);

$application = new Application();
$application->addCommands([$command]);

$application->run();
