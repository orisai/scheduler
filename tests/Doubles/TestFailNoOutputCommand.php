<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TestFailNoOutputCommand extends Command
{

	protected function configure(): void
	{
		$this->setName('test:fail-no-output');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		return 1;
	}

}
