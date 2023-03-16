<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Doubles;

use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobLock;

final class CustomNameJob implements Job
{

	private Job $job;

	private string $name;

	public function __construct(Job $job, string $name)
	{
		$this->job = $job;
		$this->name = $name;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function run(JobLock $lock): void
	{
		$this->job->run($lock);
	}

}
