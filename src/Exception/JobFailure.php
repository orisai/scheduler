<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Exception;

use Orisai\Exceptions\LogicalException;
use Orisai\Scheduler\Status\JobSummary;
use Throwable;

/**
 * @method Throwable getPrevious()
 */
final class JobFailure extends LogicalException
{

	private JobSummary $summary;

	public static function create(JobSummary $summary, Throwable $throwable): self
	{
		$self = new self();
		$self->summary = $summary;
		$self->withMessage('Job failed');
		$self->withPrevious($throwable);
		$self->withSuppressed([$throwable]);

		return $self;
	}

	public function getSummary(): JobSummary
	{
		return $this->summary;
	}

}
