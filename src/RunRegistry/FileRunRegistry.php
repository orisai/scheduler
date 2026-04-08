<?php declare(strict_types = 1);

namespace Orisai\Scheduler\RunRegistry;

use Orisai\Exceptions\Logic\InvalidState;
use Orisai\Exceptions\Message;
use function array_key_exists;
use function assert;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function function_exists;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function json_decode;
use function json_encode;
use function mkdir;
use function pathinfo;
use function posix_kill;
use function strrpos;
use function substr;
use function time;
use function touch;
use function unlink;
use const JSON_THROW_ON_ERROR;
use const PATHINFO_FILENAME;

final class FileRunRegistry implements RunRegistry
{

	private string $directory;

	private int $staleThresholdSeconds;

	public function __construct(string $directory, int $staleThresholdSeconds = 3_600)
	{
		$this->directory = $directory;
		$this->staleThresholdSeconds = $staleThresholdSeconds;
	}

	public function register(string $runId, int $pid): void
	{
		$this->ensureDirectory();
		file_put_contents($this->getFilePath($runId), json_encode([
			'pid' => $pid,
			'processStartTime' => self::getProcessStartTime($pid),
			'startTimestamp' => time(),
		], JSON_THROW_ON_ERROR));
	}

	public function deregister(string $runId): void
	{
		$path = $this->getFilePath($runId);

		if (is_file($path)) {
			unlink($path);
		}
	}

	public function refresh(string $runId): void
	{
		$path = $this->getFilePath($runId);

		if (is_file($path)) {
			touch($path);
		}
	}

	public function getActiveRuns(): array
	{
		if (!is_dir($this->directory)) {
			return [];
		}

		$files = glob($this->directory . '/*.run');
		assert($files !== false);

		$activeRuns = [];
		foreach ($files as $file) {
			$content = file_get_contents($file);

			if ($content === false || $content === '') {
				unlink($file);

				continue;
			}

			$data = json_decode($content, true);

			if (
				!is_array($data)
				|| !is_int($data['pid'] ?? null)
				|| !is_int($data['startTimestamp'] ?? null)
				|| !array_key_exists('processStartTime', $data)
				|| !(is_int($data['processStartTime']) || $data['processStartTime'] === null)
			) {
				unlink($file);

				continue;
			}

			$runId = pathinfo($file, PATHINFO_FILENAME);
			$pid = $data['pid'];
			$startTimestamp = $data['startTimestamp'];

			// Signal 0 is never delivered to the process - it only checks
			// whether the process exists, and we have permission to signal it.
			$processAlive = !function_exists('posix_kill') || posix_kill($pid, 0);

			if (!$processAlive) {
				unlink($file);

				continue;
			}

			// Compare process start time (from /proc on Linux) to detect PID reuse.
			// If the PID was reused by another process, the start times won't match.
			$storedStartTime = $data['processStartTime'];
			if ($storedStartTime !== null) {
				$currentStartTime = self::getProcessStartTime($pid);
				if ($currentStartTime !== null && $currentStartTime !== $storedStartTime) {
					unlink($file);

					continue;
				}
			}

			// Time-based fallback for systems without /proc (e.g. macOS)
			// guards against PID reuse when start time is not available.
			if ($storedStartTime === null) {
				$mtime = filemtime($file);
				if ($mtime !== false && (time() - $mtime) > $this->staleThresholdSeconds) {
					unlink($file);

					continue;
				}
			}

			$activeRuns[] = new ActiveRun($runId, $pid, $startTimestamp);
		}

		return $activeRuns;
	}

	private function getFilePath(string $runId): string
	{
		return $this->directory . '/' . $runId . '.run';
	}

	/**
	 * Reads the process start time from /proc/$pid/stat (Linux only).
	 * Field 22 (starttime) is in clock ticks since boot and is immutable per process,
	 * making it reliable for detecting PID reuse.
	 */
	private static function getProcessStartTime(int $pid): ?int
	{
		$stat = @file_get_contents("/proc/$pid/stat");
		if ($stat === false) {
			return null;
		}

		// Format: pid (comm) state ppid ... starttime ...
		// comm can contain spaces and parentheses, so find the last ')' first
		$pos = strrpos($stat, ')');
		if ($pos === false) {
			return null;
		}

		$fields = explode(' ', substr($stat, $pos + 2));

		// starttime is the 20th field after comm (0-indexed)
		return isset($fields[19]) ? (int) $fields[19] : null;
	}

	private function ensureDirectory(): void
	{
		if (is_dir($this->directory)) {
			return;
		}

		if (!mkdir($this->directory, 0_777, true) && !is_dir($this->directory)) {
			$message = Message::create()
				->withContext("Registering scheduler run in directory '{$this->directory}'.")
				->withProblem('Directory could not be created.');

			throw InvalidState::create()
				->withMessage($message);
		}
	}

}
