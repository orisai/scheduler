<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TestFailOutputCommand extends Command
{

	protected function configure(): void
	{
		$this->setName('test:fail-output');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Failure!');
		$output->writeln('New line!');

		return 256;
	}

}
