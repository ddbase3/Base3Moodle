<?php declare(strict_types=1);

namespace Base3Moodle\Job;

use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use Base3\Worker\Api\IPolicyControlledJob;
use Base3\Worker\Policy\PolicyControlledJobTrait;
use RuntimeException;

/** Remove expired BASE3 database log entries once per daily execution window. */
final class Base3LogCleanupJob implements IPolicyControlledJob {

	use PolicyControlledJobTrait;

	private const DEFAULT_RETENTION_HOURS = 48;
	private const DEFAULT_DELETE_BATCH = 100000;

	public function __construct(
		private readonly IDatabase $database,
		private readonly IConfiguration $configuration
	) {}

	public static function getName(): string {
		return 'base3logcleanupjob';
	}

	public function isActive(): bool {
		return $this->configuration->getBool('job', 'base3logcleanupjob.active', true);
	}

	public function getPriority(): int {
		return min(100, max(1, $this->configuration->getInt('job', 'base3logcleanupjob.priority', 1)));
	}

	public function getPolicyDefinition(): array {
		return [
			'policy' => 'dailywindowjobpolicy',
			'data' => ['from' => '02:00', 'to' => '04:00']
		];
	}

	public function go(): string {
		$this->database->connect();
		if (!$this->database->connected()) {
			throw new RuntimeException('BASE3 log cleanup requires a database connection.');
		}

		$exists = $this->database->singleQuery(
			"SELECT TABLE_NAME FROM information_schema.TABLES " .
			"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base3_log'"
		);
		$this->assertDatabaseSuccess('inspect BASE3 log table');
		if ($exists === null) {
			return 'Skip (base3_log does not exist)';
		}

		$retentionHours = max(1, $this->configuration->getInt(
			'job', 'base3logcleanupjob.retention_hours', self::DEFAULT_RETENTION_HOURS
		));
		$deleteBatch = min(self::DEFAULT_DELETE_BATCH, max(1, $this->configuration->getInt(
			'job', 'base3logcleanupjob.delete_batch', self::DEFAULT_DELETE_BATCH
		)));
		$cutoff = date('Y-m-d H:i:s', time() - $retentionHours * 3600);

		$this->database->nonQuery(
			"DELETE FROM base3_log WHERE `timestamp` < '" . $this->database->escape($cutoff) . "' " .
			"ORDER BY id ASC LIMIT " . $deleteBatch
		);
		$this->assertDatabaseSuccess('delete expired BASE3 logs');
		$deleted = $this->database->affectedRows();
		$this->markRun();

		return 'BASE3 log cleanup: deleted ' . $deleted . ' rows before ' . $cutoff;
	}

	private function assertDatabaseSuccess(string $operation): void {
		if ($this->database->isError()) {
			throw new RuntimeException('Failed to ' . $operation . ': ' . $this->database->errorMessage());
		}
	}
}
