<?php declare(strict_types=1);

namespace Base3Moodle\Test\Support;

use RuntimeException;

/** Minimal native File API contract double, used only by the CLI smoke test. */
final class FakeMoodleFileStorage {

	private array $records = [];

	private function key(int $context, string $component, string $area, int $itemId, string $path, string $filename): string {
		return implode('|', [$context, $component, $area, $itemId, $path, $filename]);
	}

	public function get_file(int $context, string $component, string $area, int $itemId, string $path, string $filename): FakeMoodleStoredFile|false {
		$key = $this->key($context, $component, $area, $itemId, $path, $filename);
		return isset($this->records[$key]) ? new FakeMoodleStoredFile($this, $key, $this->records[$key]) : false;
	}

	public function create_directory(int $context, string $component, string $area, int $itemId, string $path, ?int $userid = null): FakeMoodleStoredFile {
		if (!str_starts_with($path, '/') || !str_ends_with($path, '/')) throw new RuntimeException('Incorrect Moodle directory path.');
		$parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
		$current = '/';
		$this->insert($context, $component, $area, $itemId, $current, '.', '');
		foreach ($parts as $part) {
			$current .= $part . '/';
			$this->insert($context, $component, $area, $itemId, $current, '.', '');
		}
		return $this->get_file($context, $component, $area, $itemId, $path, '.');
	}

	private function insert(int $context, string $component, string $area, int $itemId, string $path, string $filename, string $content): void {
		$key = $this->key($context, $component, $area, $itemId, $path, $filename);
		$this->records[$key] = ['filepath' => $path, 'filename' => $filename, 'content' => $content, 'modified' => time()];
	}

	public function create_file_from_string(array $record, string $content): FakeMoodleStoredFile {
		$this->create_directory($record['contextid'], $record['component'], $record['filearea'], $record['itemid'], $record['filepath']);
		$this->insert($record['contextid'], $record['component'], $record['filearea'], $record['itemid'], $record['filepath'], $record['filename'], $content);
		return $this->get_file($record['contextid'], $record['component'], $record['filearea'], $record['itemid'], $record['filepath'], $record['filename']);
	}

	public function create_file_from_pathname(array $record, string $pathname): FakeMoodleStoredFile {
		return $this->create_file_from_string($record, (string)file_get_contents($pathname));
	}

	public function create_file_from_storedfile(array $record, FakeMoodleStoredFile $source): FakeMoodleStoredFile {
		return $this->create_file_from_string($record, $source->get_content());
	}

	public function get_directory_files(int $context, string $component, string $area, int $itemId, string $path, bool $recursive = false, bool $includedirs = true): array {
		if (!$this->get_file($context, $component, $area, $itemId, $path, '.')) return [];
		$result = [];
		foreach ($this->records as $key => $entry) {
			if (!str_starts_with($key, implode('|', [$context, $component, $area, $itemId]) . '|')) continue;
			if (!str_starts_with($entry['filepath'], $path)) continue;
			if ($entry['filepath'] === $path && $entry['filename'] === '.') continue;
			if ($entry['filename'] === '.' && !$includedirs) continue;
			$suffix = substr($entry['filepath'], strlen($path));
			if (!$recursive && $suffix !== '' && !($entry['filename'] === '.' && substr_count($suffix, '/') === 1)) continue;
			$result[$key] = new FakeMoodleStoredFile($this, $key, $entry);
		}
		return $result;
	}

	public function delete(string $key): bool {
		if (!isset($this->records[$key])) return false;
		$entry = $this->records[$key];
		if ($entry['filename'] === '.') {
			$area = implode('|', array_slice(explode('|', $key), 0, 4)) . '|';
			foreach ($this->records as $otherKey => $other) {
				if ($otherKey !== $key && str_starts_with($otherKey, $area)
					&& str_starts_with($other['filepath'], $entry['filepath'])) return false;
			}
		}
		unset($this->records[$key]);
		return true;
	}
}
