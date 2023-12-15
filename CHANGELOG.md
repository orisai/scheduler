# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/scheduler/compare/1.0.0...v2.x)

### Added

- `Scheduler`
	- `runPromise()` - allows `scheduler:run` and `scheduler:work` commands to output job result as soon as it is
	  finished
- `SimpleScheduler`
	- `addJob()` accepts parameter `repeatAfterSeconds`
- `JobInfo`
	- `getSecond()`- returns for which second within a minute was job scheduled
- `JobSchedule` - contains info about the scheduled job
- `SimpleJobManager`
	- `addJob()` accepts parameter `repeatAfterSeconds`
- `CallbackJobManager`
	- `addJob()` accepts parameter `repeatAfterSeconds`

### Changed

- `JobManager`
	- `getPair()` -> `getJobSchedule()`
		- returns `JobSchedule` instead of an array
	- `getPairs()` -> `getJobSchedules()`
		- returns array of `JobSchedule` instead of an array of arrays
- `Scheduler`
	- `getJobs()` -> `getJobSchedules()`
		- returns array of `JobSchedule` instead of an array of arrays
- `RunSummary`
	- `getJobs()` -> `getJobSummaries()`
- `JobExecutor`
	- `runJobs()` returns `Generator<int, JobSummary, void, RunSummary>` instead of `RunSummary`
- `ProcessJobExecutor`
	- constructor requires `JobManager` as first parameter
	- uses microseconds instead of milliseconds for start and end times
- `ManagedScheduler`
	- acquired job locks are scoped just to their id - changing run frequency or job name will not make process loose
	  the lock

### Removed

- `JobManager`
	- `getExpressions()` - replaced by `getJobSchedules()`

### Fixed

- `ListCommand`
	- Fix numeric job ids in case option `--next` is used
