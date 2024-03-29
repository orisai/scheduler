<?php declare(strict_types = 1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Cron\CronExpression;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use Symfony\Component\Console\Application;

$errorHandler = static function (): void {
	// Noop
};
$clock = new FrozenClock(1_020, new DateTimeZone('Europe/Prague'));
$scheduler = new SimpleScheduler($errorHandler, null, null, $clock);
$scheduler->addJob(
	new CallbackJob(static function (): void {
		// Noop
	}),
	new CronExpression('* * * * *'),
);
$scheduler->addJob(
	new CallbackJob(static function (): void {
		throw new Exception();
	}),
	new CronExpression('* * * * *'),
);

$command = new RunCommand($scheduler);

$application = new Application();
$application->addCommands([$command]);

$application->run();
