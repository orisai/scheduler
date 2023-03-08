_: list

# Config

PHPCS_CONFIG=tools/phpcs.xml
PHPSTAN_CONFIG=tools/phpstan.neon
PHPSTAN_BASELINE_CONFIG=tools/phpstan.baseline.neon
PHPUNIT_CONFIG=tools/phpunit.xml
INFECTION_CONFIG=tools/infection.json

# QA

qa: ## Check code quality - coding style and static analysis
	make cs & make phpstan

cs: ## Check PHP files coding style
	mkdir -p var/tools/PHP_CodeSniffer
	$(PRE_PHP) "vendor/bin/phpcs" src tests --standard=$(PHPCS_CONFIG) --parallel=$(LOGICAL_CORES) $(ARGS)

csf: ## Fix PHP files coding style
	mkdir -p var/tools/PHP_CodeSniffer
	$(PRE_PHP) "vendor/bin/phpcbf" src tests --standard=$(PHPCS_CONFIG) --parallel=$(LOGICAL_CORES) $(ARGS)

phpstan: ## Analyse code with PHPStan
	mkdir -p var/tools
	$(PRE_PHP) "vendor/bin/phpstan" analyse src tests -c $(PHPSTAN_CONFIG) $(ARGS)

phpstan-baseline: ## Add PHPStan errors to baseline
	make phpstan ARGS="-b $(PHPSTAN_BASELINE_CONFIG)"

# Tests

.PHONY: tests
tests: ## Run all tests
	$(PRE_PHP) $(PHPUNIT_COMMAND) $(ARGS)

coverage-clover: ## Generate code coverage in XML format
	$(PRE_PHP) $(PHPUNIT_COVERAGE) --coverage-clover=var/coverage/clover.xml $(ARGS)

coverage-html: ## Generate code coverage in HTML format
	$(PRE_PHP) $(PHPUNIT_COVERAGE) --coverage-html=var/coverage/html $(ARGS)

mutations: ## Check code for mutants
	make mutations-tests
	make mutations-infection

mutations-tests:
	mkdir -p var/coverage
	$(PRE_PHP) $(PHPUNIT_COVERAGE) --coverage-xml=var/coverage/xml --log-junit=var/coverage/junit.xml

mutations-infection:
	$(PRE_PHP) vendor/bin/infection \
		--configuration=$(INFECTION_CONFIG) \
		--threads=$(LOGICAL_CORES) \
		--coverage=../var/coverage \
		--skip-initial-tests \
		$(ARGS)

# Utilities

.SILENT: $(shell grep -h -E '^[a-zA-Z_-]+:.*?$$' $(MAKEFILE_LIST) | sort -u | awk 'BEGIN {FS = ":.*?"}; {printf "%s ", $$1}')

LIST_PAD=20
list:
	awk 'BEGIN {FS = ":.*##"; printf "Usage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"}'
	grep -h -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort -u | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-$(LIST_PAD)s\033[0m %s\n", $$1, $$2}'

PRE_PHP=XDEBUG_MODE=off

PHPUNIT_COMMAND="vendor/bin/paratest" -c $(PHPUNIT_CONFIG) --runner=WrapperRunner -p$(LOGICAL_CORES)
PHPUNIT_COVERAGE=php -d pcov.enabled=1 -d pcov.directory=./src $(PHPUNIT_COMMAND)

LOGICAL_CORES=$(shell nproc || sysctl -n hw.logicalcpu || wmic cpu get NumberOfLogicalProcessors || echo 4)
