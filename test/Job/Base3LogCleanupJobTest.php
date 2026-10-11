<?php declare(strict_types=1);

// Run with: php test/Job/Base3LogCleanupJobTest.php
// Uses the installed, unmodified BASE3 Framework contracts.
$framework = dirname(__DIR__, 3) . '/Base3Framework/src/';
foreach ([
	'Api/IBase.php',
	'Api/ISchemaProvider.php',
	'Database/Api/IDatabase.php',
	'Configuration/Api/IConfiguration.php',
	'Configuration/AbstractConfiguration.php',
	'Worker/Api/IJob.php',
	'Worker/Api/IJobExecutionPolicy.php',
	'Worker/Api/IPolicyControlledJob.php',
	'Worker/Policy/PolicyControlledJobTrait.php',
	'Worker/Policy/AbstractJobExecutionPolicy.php'
] as $file) {
	require_once $framework . $file;
}
require_once dirname(__DIR__, 2) . '/src/Job/Base3LogCleanupJob.php';

final class CleanupTestConfiguration extends \Base3\Configuration\AbstractConfiguration {
	public function __construct(private array $values = []) {}
	protected function load(): array { return ['job' => $this->values]; }
	protected function saveData(array $data): bool { return true; }
}

final class CleanupTestDatabase implements \Base3\Database\Api\IDatabase {
	public bool $exists = true;
	public bool $failDelete = false;
	public int $deletes = 0;
	public string $deleteSql = '';
	private bool $error = false;
	public function connect(): void {}
	public function connected(): bool { return true; }
	public function disconnect(): void {}
	public function beginTransaction(): void {}
	public function commit(): void {}
	public function rollback(): void {}
	public function nonQuery(string $query): void {
		$this->deleteSql = $query;
		$this->error = $this->failDelete;
		if (!$this->error) $this->deletes++;
	}
	public function scalarQuery(string $query): mixed { return null; }
	public function singleQuery(string $query): ?array { return $this->exists ? ['TABLE_NAME' => 'base3_log'] : null; }
	public function &listQuery(string $query): array { $rows = []; return $rows; }
	public function &multiQuery(string $query): array { $rows = []; return $rows; }
	public function affectedRows(): int { return $this->deletes ? 12 : 0; }
	public function insertId(): int|string { return 0; }
	public function escape(string $str): string { return str_replace("'", "''", $str); }
	public function isError(): bool { return $this->error; }
	public function errorNumber(): int { return $this->error ? 1 : 0; }
	public function errorMessage(): string { return $this->error ? 'simulated failure' : ''; }
}

final class CleanupTestPolicy extends \Base3\Worker\Policy\AbstractJobExecutionPolicy {
	public int $marks = 0;
	public static function getName(): string { return 'cleanuptestpolicy'; }
	public function shouldRun(string $jobName) { return true; }
	public function markRun(string $jobName) { $this->marks++; }
}

function check(bool $condition, string $message): void {
	if (!$condition) throw new \RuntimeException($message);
}

$config = new CleanupTestConfiguration();
$db = new CleanupTestDatabase();
$policy = new CleanupTestPolicy();
$job = new \Base3Moodle\Job\Base3LogCleanupJob($db, $config);
$job->setExecutionPolicy($policy);
check($job->isActive(), 'Cleanup is active by default.');
check($job->getPriority() === 1, 'Default cleanup priority.');
check($job->getPolicyDefinition()['policy'] === 'dailywindowjobpolicy', 'Daily policy discovery.');
check(str_contains($job->go(), 'deleted 12 rows'), 'Cleanup reports deleted rows.');
check($db->deletes === 1 && $policy->marks === 1, 'Successful cleanup marks its policy once.');
check(str_contains($db->deleteSql, 'LIMIT 100000'), 'Delete batch is bounded.');
check(str_contains($db->deleteSql, 'WHERE `timestamp` <'), 'Cleanup filters by timestamp.');

$db = new CleanupTestDatabase();
$db->exists = false;
$policy = new CleanupTestPolicy();
$job = new \Base3Moodle\Job\Base3LogCleanupJob($db, $config);
$job->setExecutionPolicy($policy);
check(str_contains($job->go(), 'Skip'), 'Missing log table is skipped.');
check($db->deletes === 0 && $policy->marks === 0, 'Missing table is not marked as run.');

$db = new CleanupTestDatabase();
$db->failDelete = true;
$policy = new CleanupTestPolicy();
$job = new \Base3Moodle\Job\Base3LogCleanupJob($db, $config);
$job->setExecutionPolicy($policy);
try {
	$job->go();
	throw new \RuntimeException('Expected cleanup failure.');
} catch (\RuntimeException $exception) {
	check(str_contains($exception->getMessage(), 'simulated failure'), 'Database failure is not hidden.');
}
check($policy->marks === 0, 'Failed deletion is not marked as run.');

$disabled = new CleanupTestConfiguration(['base3logcleanupjob.active' => false]);
$job = new \Base3Moodle\Job\Base3LogCleanupJob(new CleanupTestDatabase(), $disabled);
check(!$job->isActive(), 'Cleanup can be disabled by the normal configuration.');

echo "BASE3 Moodle log cleanup contract tests passed.\n";
