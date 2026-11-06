<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use ResourceFoundation\Api\IFileStorage;
use RuntimeException;

/** Private local_base3 Moodle File API area, partitioned by logical storage identity. */
final class MoodleFileStorage implements IFileStorage {

	private const COMPONENT = 'local_base3';
	private const AREA = 'base3storage';
	private const ITEM_ID = 0;

	public function __construct(
		private readonly string $id,
		private readonly string $mode
	) {}

	public function list(string $path = ''): array {
		[$directory, $filename] = $this->location($path, true);
		if (!$this->fileAt($directory, $filename)) return [];

		$result = [];
		foreach ($this->files()->get_directory_files($this->contextId(), self::COMPONENT, self::AREA, self::ITEM_ID, $directory, false, true) as $entry) {
			$isDirectory = $entry->is_directory();
			$name = $isDirectory ? basename(rtrim($entry->get_filepath(), '/')) : $entry->get_filename();
			$result[] = [
				'name' => $name,
				'type' => $isDirectory ? 'directory' : 'file',
				'size' => $isDirectory ? 0 : $entry->get_filesize(),
				'modified' => $entry->get_timemodified()
			];
		}
		return $result;
	}

	public function read(string $path): string {
		$file = $this->getFile($path);
		if (!$file || $file->is_directory()) throw new RuntimeException('BASE3 file does not exist.');
		return $file->get_content();
	}

	public function write(string $path, string $content): bool {
		[$directory, $filename] = $this->location($path, false);
		$this->deleteExistingFile($directory, $filename);
		$this->files()->create_file_from_string($this->record($directory, $filename), $content);
		return true;
	}

	/** Stores an assembled upload without loading it into PHP memory. */
	public function writeFromPathname(string $path, string $pathname): bool {
		if (!is_file($pathname)) throw new RuntimeException('BASE3 upload source is missing.');
		[$directory, $filename] = $this->location($path, false);
		$this->deleteExistingFile($directory, $filename);
		$this->files()->create_file_from_pathname($this->record($directory, $filename), $pathname);
		return true;
	}

	public function copy(string $source, string $target): bool {
		return $this->copyTo($this, $source, $target);
	}

	/** Native stored-file copy for both same-owner and cross-owner operations. */
	public function copyTo(MoodleFileStorage $destination, string $source, string $target): bool {
		if ($this === $destination && $source === $target) return $this->getFile($source) !== false;
		$file = $this->getFile($source);
		if (!$file || $file->is_directory()) return false;
		[$directory, $filename] = $destination->location($target, false);
		$destination->deleteExistingFile($directory, $filename);
		$destination->files()->create_file_from_storedfile($destination->record($directory, $filename), $file);
		return true;
	}

	public function move(string $source, string $target): bool {
		if ($source === $target) return $this->getFile($source) !== false;
		if (!$this->copy($source, $target)) return false;
		return $this->delete($source);
	}

	public function delete(string $path): bool {
		if ($path === '') return false;
		$file = $this->getFile($path);
		return $file && !$file->is_directory() && $file->delete();
	}

	public function mkdir(string $path): bool {
		[$directory] = $this->location($path, true);
		return (bool)$this->files()->create_directory($this->contextId(), self::COMPONENT, self::AREA, self::ITEM_ID, $directory);
	}

	public function rmdir(string $path): bool {
		if ($path === '') return false;
		[$directory, $filename] = $this->location($path, true);
		$file = $this->fileAt($directory, $filename);
		if (!$file || !$file->is_directory()) return false;
		if ($this->files()->get_directory_files($this->contextId(), self::COMPONENT, self::AREA, self::ITEM_ID, $directory, false, true) !== []) return false;
		return $file->delete();
	}

	public function exists(string $path): bool {
		return $this->getFile($path) !== false;
	}

	public function stat(string $path): ?array {
		$file = $this->getFile($path);
		if (!$file) return null;
		$isDirectory = $file->is_directory();
		return [
			'name' => $path === '' ? '/' : basename($path),
			'type' => $isDirectory ? 'directory' : 'file',
			'size' => $isDirectory ? 0 : $file->get_filesize(),
			'modified' => $file->get_timemodified()
		];
	}

	/** Deletes only this owner's tree from the shared Moodle file area. */
	public function deleteStorage(): void {
		$root = $this->rootPath();
		if (!$this->exists('')) return;
		$entries = $this->files()->get_directory_files($this->contextId(), self::COMPONENT, self::AREA, self::ITEM_ID, $root, true, true);
		uasort($entries, static fn($a, $b): int =>
			(strlen($b->get_filepath()) <=> strlen($a->get_filepath()))
			?: ((int)$a->is_directory() <=> (int)$b->is_directory())
		);
		foreach ($entries as $entry) {
			if (!$entry->delete()) throw new RuntimeException('Cannot delete BASE3 stored file.');
		}
		if (!$this->fileAt($root, '.')->delete()) throw new RuntimeException('Cannot delete BASE3 storage root.');
	}

	/** Streams private content only after the calling Moodle endpoint authorized the request. */
	public function sendFile(string $path): never {
		$file = $this->getFile($path);
		if (!$file || $file->is_directory()) throw new RuntimeException('BASE3 file does not exist.');
		send_stored_file($file, 0, 0, true);
		exit;
	}

	private function getFile(string $path): mixed {
		if ($path === '') {
			[$directory, $filename] = $this->location('', true);
			return $this->fileAt($directory, $filename);
		}
		[$directory, $filename] = $this->location($path, false);
		$file = $this->fileAt($directory, $filename);
		if ($file) return $file;
		[$directory, $filename] = $this->location($path, true);
		return $this->fileAt($directory, $filename);
	}

	private function fileAt(string $directory, string $filename): mixed {
		return $this->files()->get_file($this->contextId(), self::COMPONENT, self::AREA, self::ITEM_ID, $directory, $filename);
	}

	private function deleteExistingFile(string $directory, string $filename): void {
		$existing = $this->fileAt($directory, $filename);
		if ($existing && ($existing->is_directory() || !$existing->delete())) {
			throw new RuntimeException('Cannot replace existing BASE3 stored file.');
		}
	}

	/** @return array{0:string,1:string} */
	private function location(string $path, bool $directory): array {
		if (str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\\x00-\\x1f\\x7f]/', $path)) {
			throw new RuntimeException('BASE3 storage expects a safe relative path.');
		}
		$parts = $path === '' ? [] : explode('/', trim($path, '/'));
		foreach ($parts as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				throw new RuntimeException('Invalid BASE3 storage path segment.');
			}
		}
		if ($directory) {
			return [$this->rootPath() . ($parts === [] ? '' : implode('/', $parts) . '/'), '.'];
		}
		if ($parts === []) throw new RuntimeException('BASE3 storage file name is required.');
		$filename = array_pop($parts);
		return [$this->rootPath() . ($parts === [] ? '' : implode('/', $parts) . '/'), $filename];
	}

	private function rootPath(): string {
		return '/' . $this->mode . '/' . $this->id . '/';
	}

	private function record(string $directory, string $filename): array {
		return [
			'contextid' => $this->contextId(),
			'component' => self::COMPONENT,
			'filearea' => self::AREA,
			'itemid' => self::ITEM_ID,
			'filepath' => $directory,
			'filename' => $filename,
			'userid' => (int)($GLOBALS['USER']->id ?? 0) ?: null
		];
	}

	private function contextId(): int {
		return (int)\context_system::instance()->id;
	}

	private function files(): mixed {
		return get_file_storage();
	}
}
