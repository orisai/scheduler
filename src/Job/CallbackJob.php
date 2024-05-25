<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Job;

use Closure;
use ReflectionFunction;
use function getcwd;
use function sprintf;
use function str_contains;
use function str_replace;
use const DIRECTORY_SEPARATOR;

final class CallbackJob implements Job
{

	/** @var Closure(JobLock): void */
	private Closure $callback;

	/**
	 * @param Closure(JobLock): void $callback
	 */
	public function __construct(Closure $callback)
	{
		$this->callback = $callback;
	}

	public function getName(): string
	{
		$ref = new ReflectionFunction($this->callback);
		$refName = $ref->getName();

		if (str_contains($refName, '{closure')) {
			$name = sprintf(
				'%s:%s',
				str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $ref->getFileName()),
				$ref->getStartLine(),
			);

			/** @infection-ignore-all */
			if (DIRECTORY_SEPARATOR === '\\') {
				$name = str_replace('\\', '/', $name);
			}

			return $name;
		}

		$class = $ref->getClosureScopeClass();
		if ($class !== null) {
			return "{$class->getName()}::$refName()";
		}

		return $refName;
	}

	public function run(JobLock $lock): void
	{
		($this->callback)($lock);
	}

}
