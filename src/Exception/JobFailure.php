<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Exception;

use Orisai\Exceptions\LogicalException;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Throwable;

/**
 * @method Throwable getPrevious()
 */
final class JobFailure extends LogicalException
{

	private JobInfo $info;

	private JobResult $result;

	public static function create(JobInfo $info, JobResult $result, Throwable $throwable): self
	{
		$self = new self();
		$self->info = $info;
		$self->result = $result;
		$self->withMessage('Job failed');
		$self->withPrevious($throwable);
		$self->withSuppressed([$throwable]);

		return $self;
	}

	public function getInfo(): JobInfo
	{
		return $this->info;
	}

	public function getResult(): JobResult
	{
		return $this->result;
	}

}
