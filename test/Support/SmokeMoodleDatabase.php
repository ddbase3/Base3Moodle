<?php declare(strict_types=1);

namespace Base3Moodle\Test\Support;

final class SmokeMoodleDatabase {
	public function get_records(string $table, array $conditions = [], string $sort = '', string $fields = '*'): array {
		if ($table === 'role') return [(object)['id' => 7, 'shortname' => 'manager']];
		throw new \RuntimeException('Unexpected Moodle database read in audience smoke test.');
	}
}
