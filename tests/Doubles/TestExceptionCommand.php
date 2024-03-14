<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Orisai\Exceptions\Logic\NotImplemented;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TestExceptionCommand extends Command
{

	private int $code;

	public function __construct(int $code)
	{
		parent::__construct();
		$this->code = $code;
	}

	protected function configure(): void
	{
		$this->setName('test:exception');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Failure!');

		throw NotImplemented::create()
			->withMessage('Message')
			->withCode($this->code);
	}

}
