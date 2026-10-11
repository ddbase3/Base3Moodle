<?php declare(strict_types=1);

/** A dedicated BASE3 CLI cycle using the regular Moodle and BASE3 bootstraps. */
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('CLI only.');
}

// Moodle requires CLI_SCRIPT before config.php is loaded.
define('CLI_SCRIPT', true);

$moodleRoot = dirname(__DIR__, 4);
$config = $moodleRoot . '/config.php';
$runtime = $moodleRoot . '/public/local/base3/classes/Runtime.php';

if (!is_file($config) || !is_file($runtime)) {
	fwrite(STDERR, "BASE3 Moodle worker: Moodle config or BASE3 runtime not found.\n");
	exit(1);
}

require_once $config;
require_once $runtime;

try {
	\local_base3\Runtime::runWorker();
} catch (\Throwable $error) {
	fwrite(STDERR, 'BASE3 Moodle worker failed: ' . $error->getMessage() . PHP_EOL);
	exit(1);
}
