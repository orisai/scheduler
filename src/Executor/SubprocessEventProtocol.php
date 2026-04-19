<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

/**
 * Stdout protocol between ProcessJobExecutor and RunJobCommand subprocesses.
 * Framework events are emitted as single lines starting with EventMarker.
 * SOH control characters bracket the marker so it cannot collide with
 * human-readable echo output.
 *
 * @internal
 */
final class SubprocessEventProtocol
{

	public const EventMarker = "\x01orisai-event\x01";

	public const TypeStarted = 'started';

	public const TypeFinished = 'finished';

	public const TypeFailure = 'failure';

}
