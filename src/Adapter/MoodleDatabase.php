<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Database\Api\IDatabase;
use RuntimeException;
use Throwable;

/**
 * The BASE3 SQL connection for a Moodle installation using MySQL or MariaDB.
 *
 * BASE3 sends complete SQL statements and expects native MySQL result shapes.
 * Moodle's DML API transforms both SQL and returned column names and therefore
 * cannot implement that contract. This adapter uses a single lazy mysqli
 * connection for all BASE3 database operations.
 *
 * The connection uses Moodle's configured database credentials, but it does
 * not participate in transactions started through Moodle's $DB connection.
 */
final class MoodleDatabase implements IDatabase {

	private ?\mysqli $connection = null;
	private bool $inTransaction = false;
	private int $affected = 0;
	private int|string $insertid = 0;
	private int $lastcode = 0;
	private string $lastmessage = '';

	public function connect(): void {
		if ($this->connection !== null) return;

		global $CFG;
		$connection = null;

		try {
			if (!isset($CFG) || !is_object($CFG)) {
				throw new RuntimeException('Moodle must be initialized before BASE3 database access.');
			}
			if (!in_array($CFG->dbtype ?? '', ['mariadb', 'mysqli', 'mysql'], true)) {
				throw new RuntimeException('BASE3 SQL requires a Moodle MySQL or MariaDB database.');
			}
			if (!function_exists('mysqli_init')) {
				throw new RuntimeException('The PHP mysqli extension is required for BASE3 database access.');
			}

			$options = $CFG->dboptions ?? [];
			if (!is_array($options)) {
				throw new RuntimeException('Moodle database options must be an array.');
			}

			$host = (string) ($CFG->dbhost ?? '');
			$user = (string) ($CFG->dbuser ?? '');
			$name = (string) ($CFG->dbname ?? '');
			$password = (string) ($CFG->dbpass ?? '');
			if ($host === '' || $user === '' || $name === '') {
				throw new RuntimeException('Moodle database host, user and name must be configured.');
			}

			$port = $options['dbport'] ?? '';
			$port = $port === '' || $port === null ? (int) ini_get('mysqli.default_port') : (int) $port;
			if ($port === 0) $port = 3306;
			if ($port < 1 || $port > 65535) {
				throw new RuntimeException('Invalid Moodle database port.');
			}

			$socket = $options['dbsocket'] ?? null;
			if ($socket === true) $socket = ini_get('mysqli.default_socket') ?: null;
			if ($socket === false || $socket === '') $socket = null;
			if ($socket !== null && !is_string($socket)) {
				throw new RuntimeException('Invalid Moodle database socket.');
			}

			if (!empty($options['dbpersist'])) $host = 'p:' . $host;

			$flags = !empty($options['clientcompress']) ? MYSQLI_CLIENT_COMPRESS : 0;
			$ssl = (string) ($options['ssl'] ?? '');
			if ($ssl !== '') {
				if (!in_array($ssl, ['require', 'verify-full'], true)) {
					throw new RuntimeException('Unsupported Moodle MySQL SSL mode: ' . $ssl);
				}
				$flags |= MYSQLI_CLIENT_SSL;
				if ($ssl === 'verify-full') {
					if (!defined('MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT')) {
						throw new RuntimeException('This mysqli client cannot verify the database server certificate.');
					}
					$flags |= MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT;
				}
			}

			$connection = \mysqli_init();
			if (!$connection instanceof \mysqli) {
				throw new RuntimeException('Unable to initialize the mysqli connection.');
			}
			if (!$connection->real_connect($host, $user, $password, $name, $port, $socket, $flags)) {
				throw new RuntimeException(
					'Unable to connect BASE3 to the Moodle database: ' . $connection->connect_error,
					(int) $connection->connect_errno
				);
			}
			if (!$connection->set_charset('utf8mb4')) {
				throw $this->connectionError($connection, 'Unable to set BASE3 database charset');
			}

			$this->connection = $connection;
			$this->affected = 0;
			$this->insertid = 0;
			$this->clearError();
		} catch (Throwable $error) {
			$this->recordError($error);
			if ($connection instanceof \mysqli) {
				try {
					$connection->close();
				} catch (Throwable) {
					// Keep the original connection error.
				}
			}
			throw $error;
		}
	}

	public function connected(): bool {
		return $this->connection !== null;
	}

	public function disconnect(): void {
		if ($this->inTransaction) {
			throw new RuntimeException('Cannot disconnect BASE3 during an active transaction.');
		}
		if ($this->connection === null) return;
		$connection = $this->connection;
		$this->attempt(static fn() => $connection->close());
		$this->connection = null;
		$this->affected = 0;
		$this->insertid = 0;
	}

	public function beginTransaction(): void {
		$connection = $this->getConnection();
		if ($this->inTransaction) throw new RuntimeException('Nested BASE3 transactions are not supported.');
		$this->attempt(function() use ($connection): void {
			if (!$connection->begin_transaction()) {
				throw $this->connectionError($connection, 'Unable to begin BASE3 transaction');
			}
		});
		$this->inTransaction = true;
	}

	public function commit(): void {
		if (!$this->inTransaction) throw new RuntimeException('No BASE3 transaction is active.');
		$connection = $this->getConnection();
		$this->attempt(function() use ($connection): void {
			if (!$connection->commit()) {
				throw $this->connectionError($connection, 'Unable to commit BASE3 transaction');
			}
		});
		$this->inTransaction = false;
	}

	public function rollback(): void {
		if (!$this->inTransaction) throw new RuntimeException('No BASE3 transaction is active.');
		$connection = $this->getConnection();
		$this->attempt(function() use ($connection): void {
			if (!$connection->rollback()) {
				throw $this->connectionError($connection, 'Unable to roll back BASE3 transaction');
			}
		});
		$this->inTransaction = false;
	}

	public function nonQuery(string $query): void {
		$connection = $this->getConnection();
		$this->affected = 0;
		$this->insertid = 0;
		$this->attempt(function() use ($connection, $query): void {
			$result = $connection->query($query);
			if ($result === false) {
				throw $this->connectionError($connection, 'BASE3 database query failed');
			}
			if ($result instanceof \mysqli_result) $result->free();
			$this->affected = max(0, (int) $connection->affected_rows);
			$this->insertid = $connection->insert_id;
		});
	}

	public function scalarQuery(string $query): mixed {
		return $this->read($query, static function(\mysqli_result $result): mixed {
			$row = $result->fetch_row();
			if ($row === false) throw new RuntimeException('Unable to fetch BASE3 database result.');
			return $row === null ? null : ($row[0] ?? null);
		});
	}

	public function singleQuery(string $query): ?array {
		return $this->read($query, static function(\mysqli_result $result): ?array {
			$row = $result->fetch_assoc();
			if ($row === false) throw new RuntimeException('Unable to fetch BASE3 database result.');
			return $row;
		});
	}

	public function &listQuery(string $query): array {
		$list = $this->read($query, static function(\mysqli_result $result): array {
			$values = [];
			while (($row = $result->fetch_row()) !== null) {
				if ($row === false) throw new RuntimeException('Unable to fetch BASE3 database result.');
				$values[] = $row[0] ?? null;
			}
			return $values;
		});
		return $list;
	}

	public function &multiQuery(string $query): array {
		$rows = $this->read($query, static function(\mysqli_result $result): array {
			$values = [];
			while (($row = $result->fetch_assoc()) !== null) {
				if ($row === false) throw new RuntimeException('Unable to fetch BASE3 database result.');
				$values[] = $row;
			}
			return $values;
		});
		return $rows;
	}

	public function affectedRows(): int { return $this->affected; }
	public function insertId(): int|string { return $this->insertid; }

	public function escape(string $str): string {
		$connection = $this->getConnection();
		return $this->attempt(static fn(): string => $connection->real_escape_string($str));
	}

	public function isError(): bool { return $this->lastmessage !== ''; }
	public function errorNumber(): int { return $this->lastcode; }
	public function errorMessage(): string { return $this->lastmessage; }

	private function getConnection(): \mysqli {
		$this->connect();
		return $this->connection;
	}

	private function read(string $query, callable $reader): mixed {
		$connection = $this->getConnection();
		return $this->attempt(function() use ($connection, $query, $reader): mixed {
			$result = $connection->query($query);
			if (!$result instanceof \mysqli_result) {
				if ($result === false) {
					throw $this->connectionError($connection, 'BASE3 database query failed');
				}
				throw new RuntimeException('BASE3 expected a row result from the database query.');
			}
			try {
				return $reader($result);
			} finally {
				$result->free();
			}
		});
	}

	private function attempt(callable $operation): mixed {
		$this->clearError();
		try {
			return $operation();
		} catch (Throwable $error) {
			$this->recordError($error);
			throw $error;
		}
	}

	private function connectionError(\mysqli $connection, string $context): RuntimeException {
		$detail = trim((string) $connection->error);
		return new RuntimeException($context . ($detail === '' ? '' : ': ' . $detail), (int) $connection->errno);
	}

	private function clearError(): void {
		$this->lastcode = 0;
		$this->lastmessage = '';
	}

	private function recordError(Throwable $error): void {
		$this->lastcode = (int) $error->getCode();
		$this->lastmessage = $error->getMessage();
	}
}
