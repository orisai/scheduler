<?php declare(strict_types = 1);

// Simulates a subprocess killed by SIGINT (exit code 130 = 128 + 2).
// Used to test that shutdown treats signal-killed subprocesses as maintenance, not errors.

require_once __DIR__ . '/../../vendor/autoload.php';

exit(130);
