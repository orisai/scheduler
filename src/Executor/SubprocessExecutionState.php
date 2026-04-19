<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobInfo;
use Symfony\Component\Process\Process;

/**
 * Mutable state tracking a single subprocess execution for ProcessJobExecutor.
 *
 * @internal
 */
final class SubprocessExecutionState
{

	public Process $process;

	public JobSchedule $schedule;

	/** @var int|string */
	public $id;

	public bool $startedDispatched = false;

	/**
	 * JobInfo built from the subprocess's `started` event. Stored so synthetic
	 * summaries (maintenance or fail) for subprocesses that didn't emit `finished`
	 * can reuse it — preserving `executionId` pairing between beforeJob and afterJob.
	 */
	public ?JobInfo $startedInfo = null;

	/** @var array<mixed>|null */
	public ?array $finishedEvent = null;

	/** @var array<mixed>|null */
	public ?array $failureEvent = null;

	public string $unexpectedStdout = '';

	public string $unparsedBuffer = '';

	/**
	 * @param int|string $id
	 */
	public function __construct(Process $process, JobSchedule $schedule, $id)
	{
		$this->process = $process;
		$this->schedule = $schedule;
		$this->id = $id;
	}

}
