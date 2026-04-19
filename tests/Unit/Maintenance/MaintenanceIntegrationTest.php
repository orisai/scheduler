<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Maintenance;

use Cron\CronExpression;
use DateTimeZone;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\RunRegistry\FileRunRegistry;
use Orisai\Scheduler\SimpleScheduler;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\RunInfo;
use Orisai\Scheduler\Status\RunSummary;
use PHPUnit\Framework\TestCase;
use Tests\Orisai\Scheduler\Doubles\DelayedMaintenanceChecker;
use Tests\Orisai\Scheduler\Doubles\FileExistsMaintenanceChecker;
use function count;
use function file_put_contents;
use function iterator_to_array;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class MaintenanceIntegrationTest extends TestCase
{

	public function testMaintenanceAtRunStart(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		$i = 0;
		$scheduler->addJob(
			new CallbackJob(static function () use (&$i): void {
				$i++;
			}),
			new CronExpression('* * * * *'),
		);

		$summary = $scheduler->run();

		self::assertSame(0, $i);
		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(1, $summary->getJobSummaries());
		self::assertSame(
			JobResultState::maintenance(),
			$summary->getJobSummaries()[0]->getResult()->getState(),
		);

		// Verify run was deregistered after completion
		self::assertSame([], $registry->getActiveRuns());

		unlink($maintenanceFile);
	}

	public function testMaintenanceAtRunStartCallsBeforeRunCallback(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
		);

		$beforeRunInfo = null;
		$scheduler->addBeforeRunCallback(static function (RunInfo $info) use (&$beforeRunInfo): void {
			$beforeRunInfo = $info;
		});

		$scheduler->run();

		self::assertNotNull($beforeRunInfo);
		self::assertCount(1, $beforeRunInfo->getJobInfos());

		unlink($maintenanceFile);
	}

	public function testMaintenanceAtRunStartFiresAfterJobCallbacks(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
			'job-a',
		);
		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
			'job-b',
		);

		$afterStates = [];
		$scheduler->addAfterJobCallback(
			static function (JobInfo $info, JobResult $result) use (&$afterStates): void {
				$afterStates[$info->getJobId()] = $result->getState();
			},
		);

		$scheduler->run();

		self::assertCount(2, $afterStates);
		self::assertSame(JobResultState::maintenance(), $afterStates['job-a']);
		self::assertSame(JobResultState::maintenance(), $afterStates['job-b']);

		unlink($maintenanceFile);
	}

	public function testMaintenanceAtRunStartCallsAfterRunCallbacks(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
		);

		$afterRunSummary = null;
		$scheduler->addAfterRunCallback(static function (RunSummary $summary) use (&$afterRunSummary): void {
			$afterRunSummary = $summary;
		});

		$scheduler->run();

		self::assertNotNull($afterRunSummary);
		self::assertTrue($afterRunSummary->isMaintenanceActive());
		self::assertCount(1, $afterRunSummary->getJobSummaries());

		unlink($maintenanceFile);
	}

	public function testMaintenanceAtRunStartMultipleJobs(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
			'job-a',
		);
		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
			'job-b',
		);

		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(2, $summary->getJobSummaries());

		foreach ($summary->getJobSummaries() as $jobSummary) {
			self::assertSame(
				JobResultState::maintenance(),
				$jobSummary->getResult()->getState(),
			);
		}

		unlink($maintenanceFile);
	}

	public function testNoMaintenanceRunsNormally(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid());
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		self::assertFalse($manager->isMaintenance());
		self::assertSame([], $registry->getActiveRuns());

		$summary = $scheduler->run();

		self::assertFalse($summary->isMaintenanceActive());
		self::assertSame([], $summary->getJobSummaries());
		// Run deregistered
		self::assertSame([], $registry->getActiveRuns());
	}

	public function testRunSummaryMaintenanceFlagDefaultsFalse(): void
	{
		$scheduler = new SimpleScheduler();
		$summary = $scheduler->run();

		self::assertFalse($summary->isMaintenanceActive());
	}

	public function testRequestShutdown(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid());
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		$manager->requestShutdown();

		$scheduler->addJob(
			new CallbackJob(static function (): void {
				// noop
			}),
			new CronExpression('* * * * *'),
		);

		$generator = $scheduler->runPromise();
		iterator_to_array($generator);
		$runSummary = $generator->getReturn();

		self::assertTrue($runSummary->isMaintenanceActive());
		// Shutdown flag is reset after run
		self::assertSame([], $registry->getActiveRuns());
	}

	public function testRunRegistersAndDeregistersRun(): void
	{
		$checker = new FileExistsMaintenanceChecker(sys_get_temp_dir() . '/nonexistent-' . uniqid());
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		// Before run
		self::assertSame([], $registry->getActiveRuns());

		// Check that run is registered DURING execution via beforeRun callback
		$registeredDuringRun = false;
		$scheduler->addBeforeRunCallback(static function () use ($registry, &$registeredDuringRun): void {
			$registeredDuringRun = count($registry->getActiveRuns()) > 0;
		});

		$scheduler->run();

		// Verify it was registered during the run
		self::assertTrue($registeredDuringRun);

		// After run - deregistered
		self::assertSame([], $registry->getActiveRuns());
	}

	public function testRunRegisteredDuringMaintenanceSkip(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
		);

		// Check that run is registered during the afterRun callback even in maintenance path
		$registeredDuringAfterRun = false;
		$scheduler->addAfterRunCallback(static function () use ($registry, &$registeredDuringAfterRun): void {
			$registeredDuringAfterRun = count($registry->getActiveRuns()) > 0;
		});

		$scheduler->run();

		// Was registered during afterRun callback
		self::assertTrue($registeredDuringAfterRun);

		// After run - deregistered
		self::assertSame([], $registry->getActiveRuns());

		unlink($maintenanceFile);
	}

	public function testMaintenanceAtRunStartNoJobsDue(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$executor = new ProcessJobExecutor($clock);

		$scheduler = new SimpleScheduler(null, null, $executor, $clock, null, $manager, $registry);

		// No jobs added - maintenance with no due jobs
		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertSame([], $summary->getJobSummaries());

		unlink($maintenanceFile);
	}

	public function testBasicJobExecutorShutdownBetweenJobs(): void
	{
		// Call #1 in runPromise → false, call #2 before job-a → false, call #3 before job-b → true
		$checker = new DelayedMaintenanceChecker(2);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);

		$executedJobs = [];
		$scheduler = new SimpleScheduler(null, null, null, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function () use (&$executedJobs): void {
				$executedJobs[] = 'job-a';
			}),
			new CronExpression('* * * * *'),
			'job-a',
		);
		$scheduler->addJob(
			new CallbackJob(static function () use (&$executedJobs): void {
				$executedJobs[] = 'job-b';
			}),
			new CronExpression('* * * * *'),
			'job-b',
		);

		// DelayedMaintenanceChecker(1): call #1 in runPromise → false, call #2 in executor → true
		// First job runs, then shutdown fires before second job
		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertSame(['job-a'], $executedJobs);
		self::assertCount(2, $summary->getJobSummaries());
		self::assertSame(
			JobResultState::done(),
			$summary->getJobSummaries()[0]->getResult()->getState(),
		);
		self::assertSame(
			JobResultState::maintenance(),
			$summary->getJobSummaries()[1]->getResult()->getState(),
		);
	}

	public function testBasicJobExecutorMaintenanceAtRunStart(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
		);

		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(1, $summary->getJobSummaries());
		self::assertSame(
			JobResultState::maintenance(),
			$summary->getJobSummaries()[0]->getResult()->getState(),
		);

		unlink($maintenanceFile);
	}

	public function testGetStatus(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock, null, $manager, $registry);

		$status = $scheduler->getStatus();
		self::assertTrue($status->isMaintenanceEnabled());
		self::assertSame([], $status->getActiveRuns());
		self::assertTrue($status->isReadyForShutdown());

		unlink($maintenanceFile);
	}

	public function testGetStatusWithoutMaintenanceManager(): void
	{
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);

		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock, null, null, $registry);

		$status = $scheduler->getStatus();
		self::assertNull($status->isMaintenanceEnabled());
		self::assertSame([], $status->getActiveRuns());
		self::assertFalse($status->isReadyForShutdown());
	}

	public function testGetStatusWithoutRunRegistry(): void
	{
		$scheduler = new SimpleScheduler();

		$status = $scheduler->getStatus();
		self::assertNull($status->isMaintenanceEnabled());
		self::assertSame([], $status->getActiveRuns());
	}

	public function testMaintenanceAtRunStartWithTimezone(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1, new DateTimeZone('UTC'));
		$scheduler = new SimpleScheduler(null, null, null, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
			'tz-job',
			0,
			new DateTimeZone('America/New_York'),
		);

		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(1, $summary->getJobSummaries());

		$jobSummary = $summary->getJobSummaries()[0];
		self::assertSame(
			JobResultState::maintenance(),
			$jobSummary->getResult()->getState(),
		);
		// Job start and result end should be in the job's timezone
		self::assertSame('America/New_York', $jobSummary->getInfo()->getStart()->getTimezone()->getName());
		self::assertSame('America/New_York', $jobSummary->getResult()->getEnd()->getTimezone()->getName());

		unlink($maintenanceFile);
	}

	public function testBasicJobExecutorShutdownWithTimezone(): void
	{
		// Call #1 in runPromise → false, call #2 in executor before job → true
		$checker = new DelayedMaintenanceChecker(1);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1, new DateTimeZone('UTC'));
		$scheduler = new SimpleScheduler(null, null, null, $clock, null, $manager, $registry);

		$scheduler->addJob(
			new CallbackJob(static function (): void {
			}),
			new CronExpression('* * * * *'),
			'tz-job',
			0,
			new DateTimeZone('Europe/Prague'),
		);

		$summary = $scheduler->run();

		self::assertTrue($summary->isMaintenanceActive());
		self::assertCount(1, $summary->getJobSummaries());

		$jobSummary = $summary->getJobSummaries()[0];
		// Shutdown fired before the job ran - should have maintenance state with correct timezone
		self::assertSame(
			JobResultState::maintenance(),
			$jobSummary->getResult()->getState(),
		);
		self::assertSame('Europe/Prague', $jobSummary->getResult()->getEnd()->getTimezone()->getName());
	}

	public function testRunJobAlwaysRespectsMaintenance(): void
	{
		$maintenanceFile = sys_get_temp_dir() . '/maintenance-test-' . uniqid();
		file_put_contents($maintenanceFile, '');

		$checker = new FileExistsMaintenanceChecker($maintenanceFile);
		$dir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
		$registry = new FileRunRegistry($dir);
		$manager = new MaintenanceManager($checker);

		$clock = new FrozenClock(1);
		$scheduler = new SimpleScheduler(null, null, null, $clock, null, $manager, $registry);

		$execCount = 0;
		$scheduler->addJob(
			new CallbackJob(static function () use (&$execCount): void {
				$execCount++;
			}),
			new CronExpression('* * * * *'),
		);

		// Direct runJob() always respects maintenance — `$force` controls the due-time check
		// only. During maintenance, runJob() returns a JobSummary with state=maintenance
		// (rather than null) so callers can distinguish skip-reason from "not due".
		$nonForced = $scheduler->runJob(0, false);
		self::assertNotNull($nonForced);
		self::assertSame(JobResultState::maintenance(), $nonForced->getResult()->getState());
		self::assertSame(0, $execCount);

		$forced = $scheduler->runJob(0, true);
		self::assertSame(JobResultState::maintenance(), $forced->getResult()->getState());
		self::assertSame(0, $execCount);

		unlink($maintenanceFile);
	}

}
