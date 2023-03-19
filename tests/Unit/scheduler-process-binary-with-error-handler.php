<?php declare(strict_types = 1);

use Orisai\Scheduler\Command\RunJobCommand;
use Symfony\Component\Console\Application;
use Tests\Orisai\Scheduler\Unit\SchedulerProcessSetup;

require_once __DIR__ . '/../../vendor/autoload.php';

$application = new Application();
$scheduler = SchedulerProcessSetup::createWithErrorHandler();

$command = new RunJobCommand($scheduler);

$application = new Application();
$application->addCommands([$command]);

$application->run();
