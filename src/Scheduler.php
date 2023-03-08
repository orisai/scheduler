<?php declare(strict_types = 1);

namespace Orisai\Scheduler;

use Orisai\Scheduler\Job\Job;

final class Scheduler
{

	/** @var list<Job> */
	private array $jobs = [];

	public function addJob(Job $job): void
	{
		$this->jobs[] = $job;
	}

	public function run(): void
	{
		foreach ($this->jobs as $job) {
			$job->run();
		}
	}

}
