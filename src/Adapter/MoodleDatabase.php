<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Database\Api\IDatabase;
use RuntimeException;

/** Executes BASE3 SQL using Moodle's existing DB connection. BASE3 SQL in the shipped modules is MariaDB SQL. */
final class MoodleDatabase implements IDatabase {

	private bool $open = false;
	private mixed $transaction = null;
	private int $affected = 0;
	private int|string $insertid = 0;
	private int $lastcode = 0;
	private string $lastmessage = '';
	private ?bool $backslashEscapes = null;
	private bool $ansiQuotes = false;

	public function connect(): void {
		global $DB;
		if ($this->open) return;
		if (!isset($DB) || !$DB instanceof \moodle_database) {
			throw new RuntimeException('The Moodle database must be ready before BASE3 starts.');
		}
		if ($DB->get_dbfamily() !== 'mysql') {
			throw new RuntimeException('This BASE3 integration requires the existing MariaDB/MySQL Moodle database.');
		}
		$this->open = true;
	}

	public function connected(): bool { return $this->open; }
	public function disconnect(): void {
		if ($this->transaction !== null) throw new RuntimeException('Cannot disconnect BASE3 inside an active transaction.');
		// Moodle owns and closes the underlying connection at the end of its request.
		$this->open = false;
	}

	public function beginTransaction(): void {
		global $DB;
		$this->connect();
		if ($this->transaction !== null) throw new RuntimeException('Nested BASE3 transactions are not supported.');
		$this->transaction = $this->perform(fn() => $DB->start_delegated_transaction());
	}

	public function commit(): void {
		if ($this->transaction === null) throw new RuntimeException('BASE3 transaction was not started.');
		$tx = $this->transaction;
		$this->transaction = null;
		$this->perform(static fn() => $tx->allow_commit());
	}

	public function rollback(): void {
		if ($this->transaction === null) throw new RuntimeException('BASE3 transaction was not started.');
		$tx = $this->transaction;
		$this->transaction = null;
		// Moodle's delegated transaction rollback rethrows the supplied reason.
		$reason = new RuntimeException('BASE3 requested a transaction rollback.');
		try {
			$tx->rollback($reason);
		} catch (\Throwable $error) {
			if ($error !== $reason) throw $error;
		}
	}

	public function nonQuery(string $query): void {
		global $DB;
		$this->connect();
		[$sql, $params] = $this->prepareSql($query);
		$this->perform(fn() => $DB->execute($sql, $params));
		// ROW_COUNT and LAST_INSERT_ID belong to the same existing Moodle connection.
		$this->affected = max(0, (int) $this->perform(fn() => $DB->get_field_sql('SELECT ROW_COUNT()')));
		$this->insertid = (int) $this->perform(fn() => $DB->get_field_sql('SELECT LAST_INSERT_ID()'));
	}

	public function scalarQuery(string $query): mixed {
		$rows = $this->multiQuery($query);
		return $rows === [] ? null : (array_values($rows[0])[0] ?? null);
	}

	public function singleQuery(string $query): ?array {
		$rows = $this->multiQuery($query);
		return $rows[0] ?? null;
	}

	public function &listQuery(string $query): array {
		$rows = $this->multiQuery($query);
		$result = array_map(static fn(array $row) => array_values($row)[0] ?? null, $rows);
		return $result;
	}

	public function &multiQuery(string $query): array {
		global $DB;
		$this->connect();
		[$sql, $params] = $this->prepareSql($query);
		$rows = $this->perform(function() use ($DB, $sql, $params): array {
			// get_records_sql would discard rows with nonunique first-column values.
			$recordset = $DB->get_recordset_sql($sql, $params);
			$result = [];
			try {
				foreach ($recordset as $record) $result[] = (array) $record;
			} finally {
				$recordset->close();
			}
			return $result;
		});
		return $rows;
	}

	public function affectedRows(): int { return $this->affected; }
	public function insertId(): int|string { return $this->insertid; }

	public function escape(string $str): string {
		global $DB;
		$this->connect();
		$this->loadSqlMode();
		if (!$this->backslashEscapes) {
			if (str_contains($str, "\0")) throw new RuntimeException('Cannot quote NUL in this SQL mode.');
			return str_replace("'", "''", $str);
		}
		return strtr($str, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z", "'" => "\\'", '"' => '\\"']);
	}

	public function isError(): bool { return $this->lastmessage !== ''; }
	public function errorNumber(): int { return $this->lastcode; }
	public function errorMessage(): string { return $this->lastmessage; }


	/**
	 * BASE3 accepts complete, already escaped SQL strings. Moodle instead scans
	 * SQL for placeholder syntax before sending it to MySQL, including syntax
	 * occurring inside quoted values (for example JSON containing :true).
	 *
	 * Move SQL string literals into Moodle parameters at this host boundary.
	 * This also keeps JSON and other literal data out of Moodle's table-name
	 * rewriting. No BASE3 module or SQL storage contract needs to change.
	 *
	 * @return array{0:string,1:array<int,string>}
	 */
	private function prepareSql(string $sql): array {
		$this->loadSqlMode();
		$statement = '';
		$params = [];
		$length = strlen($sql);

		for ($position = 0; $position < $length; $position++) {
			$char = $sql[$position];

			// Preserve comments and identifiers without trying to bind their contents.
			if ($char === '#' || ($char === '-' && ($sql[$position + 1] ?? '') === '-'
				&& preg_match('/\s/', $sql[$position + 2] ?? '') === 1)) {
				$end = strpos($sql, "\n", $position);
				$end = $end === false ? $length : $end;
				$statement .= substr($sql, $position, $end - $position);
				$position = $end - 1;
				continue;
			}
			if ($char === '/' && ($sql[$position + 1] ?? '') === '*') {
				$end = strpos($sql, '*/', $position + 2);
				if ($end === false) throw new RuntimeException('Unterminated SQL block comment.');
				$statement .= substr($sql, $position, $end + 2 - $position);
				$position = $end + 1;
				continue;
			}
			if ($char === '`' || ($char === '"' && $this->ansiQuotes)) {
				$start = $position;
				$this->skipQuotedIdentifier($sql, $position, $char);
				$statement .= substr($sql, $start, $position - $start + 1);
				continue;
			}
			if ($char === "'" || $char === '"') {
				// A MySQL literal introducer (X'AB', B'101', N'...') is not
				// an ordinary character value and must retain its native syntax.
				if ($char === "'" && preg_match('/(?:\\b[xXbBnN]|_[a-zA-Z0-9_]+)$/', $statement) === 1) {
					throw new RuntimeException('Unsupported typed SQL string literal in BASE3 Moodle adapter.');
				}
				$params[] = $this->readSqlString($sql, $position, $char);
				$statement .= '?';
				continue;
			}
			$statement .= $char;
		}

		return [$statement, $params];
	}

	/** Reads the exact value represented by an escaped MySQL string literal. */
	private function readSqlString(string $sql, int &$position, string $quote): string {
		$value = '';
		$length = strlen($sql);
		for ($position++; $position < $length; $position++) {
			$char = $sql[$position];
			if ($char === '\\' && $this->backslashEscapes) {
				if (++$position >= $length) throw new RuntimeException('Invalid trailing SQL escape.');
				$escaped = $sql[$position];
				$value .= match ($escaped) {
					'0' => "\0",
					'b' => "\x08",
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'Z' => "\x1a",
					'%', '_' => '\\' . $escaped,
					default => $escaped
				};
				continue;
			}
			if ($char === $quote) {
				if (($sql[$position + 1] ?? '') === $quote) {
					$value .= $quote;
					$position++;
					continue;
				}
				return $value;
			}
			$value .= $char;
		}
		throw new RuntimeException('Unterminated SQL string literal.');
	}

	private function skipQuotedIdentifier(string $sql, int &$position, string $quote): void {
		$length = strlen($sql);
		for ($position++; $position < $length; $position++) {
			if ($sql[$position] === $quote) {
				if (($sql[$position + 1] ?? '') === $quote) {
					$position++;
					continue;
				}
				return;
			}
		}
		throw new RuntimeException('Unterminated SQL identifier.');
	}

	private function loadSqlMode(): void {
		if ($this->backslashEscapes !== null) return;
		global $DB;
		$this->connect();
		$raw = (string) $this->perform(fn() => $DB->get_field_sql('SELECT @@SESSION.sql_mode'));
		$modes = array_map('trim', explode(',', strtoupper($raw)));
		$this->backslashEscapes = !in_array('NO_BACKSLASH_ESCAPES', $modes, true);
		$this->ansiQuotes = in_array('ANSI_QUOTES', $modes, true);
	}

	private function perform(callable $operation): mixed {
		$this->lastcode = 0;
		$this->lastmessage = '';
		try {
			return $operation();
		} catch (\Throwable $error) {
			$this->lastcode = (int) $error->getCode();
			$this->lastmessage = $error->getMessage();
			throw $error;
		}
	}
}
