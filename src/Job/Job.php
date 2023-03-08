<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

interface Job
{

	public function run(): void;

}
