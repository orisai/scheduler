<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Exception;

use Orisai\Exceptions\LogicalException;
use Orisai\Scheduler\Status\RunSummary;
use Throwable;

final class JobsExecutionFailure extends LogicalException
{

	private RunSummary $summary;

	/**
	 * @param list<Throwable> $suppressed
	 */
	public static function create(RunSummary $summary, array $suppressed): self
	{
		$self = new self();
		$self->summary = $summary;
		$self->withMessage('Executed jobs failed');
		$self->withSuppressed($suppressed);

		return $self;
	}

	public function getSummary(): RunSummary
	{
		return $this->summary;
	}

}
