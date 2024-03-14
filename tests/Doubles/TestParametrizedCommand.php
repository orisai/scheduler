<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class TestParametrizedCommand extends Command
{

	protected function configure(): void
	{
		$this->setName('test:parameters');
		$this->addArgument('argument', InputArgument::REQUIRED);
		$this->addOption('option', null, InputOption::VALUE_REQUIRED);
		$this->addOption('bool-option', null, InputOption::VALUE_NONE);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		return 0;
	}

}
