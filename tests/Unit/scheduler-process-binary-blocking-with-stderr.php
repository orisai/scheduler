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
		fwrite(STDERR, 'job error output');
	}),
	new CronExpression('* * * * *'),
);

$command = new RunJobCommand($scheduler);

$application = new Application();
$application->setAutoExit(false);
$application->addCommands([$command]);
$application->run();

// Block to keep the process alive after writing JSON + stderr
sleep(15);
