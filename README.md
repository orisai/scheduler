<h1 align="center">
	<img src="https://github.com/orisai/.github/blob/main/images/repo_title.png?raw=true" alt="Orisai"/>
	<br/>
	Scheduler
</h1>

<p align="center">
    Cron job scheduler - with locks, parallelism and more
</p>

<p align="center">
	📄 Check out our <a href="docs/README.md">documentation</a>.
</p>

<p align="center">
	💸 If you like Orisai, please <a href="https://orisai.dev/sponsor">make a donation</a>. Thank you!
</p>

<p align="center">
	<a href="https://github.com/orisai/scheduler/actions?query=workflow%3ACI">
		<img src="https://github.com/orisai/scheduler/workflows/CI/badge.svg">
	</a>
	<a href="https://coveralls.io/r/orisai/scheduler">
		<img src="https://badgen.net/coveralls/c/github/orisai/scheduler/v1.x?cache=300">
	</a>
	<a href="https://dashboard.stryker-mutator.io/reports/github.com/orisai/scheduler/v1.x">
		<img src="https://badge.stryker-mutator.io/github.com/orisai/scheduler/v1.x">
	</a>
	<a href="https://packagist.org/packages/orisai/scheduler">
		<img src="https://badgen.net/packagist/dt/orisai/scheduler?cache=3600">
	</a>
	<a href="https://packagist.org/packages/orisai/scheduler">
		<img src="https://badgen.net/packagist/v/orisai/scheduler?cache=3600">
	</a>
	<a href="https://choosealicense.com/licenses/mpl-2.0/">
		<img src="https://badgen.net/badge/license/MPL-2.0/blue?cache=3600">
	</a>
<p>

##

Create script with scheduler setup (e.g. `bin/scheduler.php`)

```php
use Cron\CronExpression;
use Orisai\Scheduler\SimpleScheduler;

$scheduler = new SimpleScheduler();

// Add jobs
$scheduler->addJob(
	new CallbackJob(fn() => exampleTask()),
	new CronExpression('* * * * *'),
);

$scheduler->run();
```

Configure crontab to run your script each minute

```
* * * * * cd path/to/project && php bin/scheduler.php >> /dev/null 2>&1
```

Looking for more? Documentation is [here](docs/README.md).
