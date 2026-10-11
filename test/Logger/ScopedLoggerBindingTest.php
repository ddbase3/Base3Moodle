<?php declare(strict_types=1);

// Run with: php test/Logger/ScopedLoggerBindingTest.php
// Exercises the existing Framework logger contract without a live database.
$framework = dirname(__DIR__, 3) . '/Base3Framework/src/';
foreach ([
	'Database/Api/IDatabase.php',
	'Logger/Api/ILogger.php',
	'Logger/LoggerBridgeTrait.php',
	'Logger/AbstractLogger.php',
	'Logger/ScopedDatabaseLogger/ScopedDatabaseLogger.php'
] as $file) {
	require_once $framework . $file;
}

final class LoggerTestDatabase implements \Base3\Database\Api\IDatabase {
	public string $insert = '';
	public int $writes = 0;
	public function connect(): void {}
	public function connected(): bool { return true; }
	public function disconnect(): void {}
	public function beginTransaction(): void {}
	public function commit(): void {}
	public function rollback(): void {}
	public function nonQuery(string $query): void {
		if (str_contains($query, 'INSERT INTO base3_log')) {
			$this->insert = $query;
			$this->writes++;
		}
	}
	public function scalarQuery(string $query): mixed { return null; }
	public function singleQuery(string $query): ?array { return null; }
	public function &listQuery(string $query): array { $result = []; return $result; }
	public function &multiQuery(string $query): array {
		$result = str_contains($query, 'SELECT DISTINCT scope')
			? [['scope' => 'masterworker']]
			: [['timestamp' => '2026-10-11 21:00:00', 'scope' => 'masterworker', 'level' => 'info', 'log' => 'worker cycle']];
		return $result;
	}
	public function affectedRows(): int { return 1; }
	public function insertId(): int|string { return 1; }
	public function escape(string $str): string { return str_replace("'", "''", $str); }
	public function isError(): bool { return false; }
	public function errorNumber(): int { return 0; }
	public function errorMessage(): string { return ''; }
}

$db = new LoggerTestDatabase();
$logger = new \Base3\Logger\ScopedDatabaseLogger\ScopedDatabaseLogger($db);
$logger->info('worker cycle', ['scope' => 'masterworker']);
if ($db->writes !== 1 || !str_contains($db->insert, "'masterworker'")) {
	throw new \RuntimeException('Scoped logger did not write to base3_log.');
}
if ($logger->getScopes() !== ['masterworker']) {
	throw new \RuntimeException('Scoped logger did not return log scopes.');
}
$rows = $logger->getLogs('masterworker');
if (count($rows) !== 1 || ($rows[0]['log'] ?? '') !== 'worker cycle') {
	throw new \RuntimeException('Scoped logger did not return entries for the log display.');
}
echo "BASE3 scoped logger contract tests passed.\n";
