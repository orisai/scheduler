<?php declare(strict_types = 1);

use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\WorkerCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__ . '/../../../vendor/autoload.php';

$readyFile = $argv[1] ?? null;

// FrozenClock at a minute boundary (seconds == 0) so the worker spawns immediately.
$clock = new FrozenClock(1_020);

$command = new WorkerCommand($clock);
$command->setExecutable('tests/Unit/Command/worker-binary.php');

// testCb fires after subprocess is spawned AND after signal handlers are registered.
// First call creates the ready file, then advances the clock so worker doesn't re-spawn.
$command->enableTestMode(99, static function () use ($readyFile, $clock): void {
	static $signaled = false;
	if (!$signaled) {
		$signaled = true;
		$clock->sleep(60);
		if ($readyFile !== null) {
			touch($readyFile);
		}
	}
});

$application = new Application();
$application->setAutoExit(false);
$application->addCommands([$command]);

$exitCode = $application->run(
	new ArrayInput(['command' => 'scheduler:worker', '--force' => true]),
	new ConsoleOutput(),
);

exit($exitCode);
