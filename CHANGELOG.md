# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/scheduler/compare/1.0.0...v2.x)

### Added

- `Scheduler`
	- `runPromise()` - allows `scheduler:run` and `scheduler:work` commands to output job result as soon as it is
	  finished
	- `getScheduledJobs()` - added `repeatAfterSeconds` parameter into the returned array
- `SimpleScheduler`
	- `addJob()` accepts parameter `repeatAfterSeconds`
- `JobInfo`
	- `getSecond()`- returns for which second within a minute was job scheduled
- `JobManager`
	- `getScheduledJob()` - added `repeatAfterSeconds` parameter into the returned array
	- `getScheduledJobs()` - added `repeatAfterSeconds` parameter into the returned array
- `SimpleJobManager`
	- `addJob()` accepts parameter `repeatAfterSeconds`
- `CallbackJobManager`
	- `addJob()` accepts parameter `repeatAfterSeconds`

### Changed

- `JobManager`
	- `getPair()` -> `getScheduledJob()`
	- `getPairs()` -> `getScheduledJobs()`
- `Scheduler`
	- `getJobs()` -> `getScheduledJobs()`
- `RunSummary`
	- `getJobs()` -> `getJobSummaries()`
- `JobExecutor`
	- `runJobs()` returns `Generator<int, JobSummary, void, RunSummary>` instead of `RunSummary`
- `ProcessJobExecutor`
	- constructor requires `JobManager` as first parameter
