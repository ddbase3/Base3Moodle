<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use ResourceFoundation\Api\IFileStorage;
use RuntimeException;

/** Sandboxed private filesystem implementation of the shared ResourceFoundation contract. */
final class MoodleFileStorage implements IFileStorage {

	public function __construct(private readonly string $root) {
		if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
			throw new RuntimeException('Cannot create BASE3 storage directory: ' . $root);
		}
	}

	public function list(string $path = ''): array {
		$directory = $this->target($path);
		if (!is_dir($directory)) return [];
		$result = [];
		foreach (new \DirectoryIterator($directory) as $item) {
			if ($item->isDot() || $item->isLink()) continue;
			$result[] = ['name' => $item->getFilename(), 'type' => $item->isDir() ? 'directory' : 'file', 'size' => $item->isFile() ? $item->getSize() : 0, 'modified' => $item->getMTime()];
		}
		return $result;
	}

	public function read(string $path): string {
		$file = $this->target($path);
		if (!is_file($file)) throw new RuntimeException('BASE3 file does not exist.');
		$result = file_get_contents($file);
		if ($result === false) throw new RuntimeException('Cannot read BASE3 storage file.');
		return $result;
	}

	public function write(string $path, string $content): bool {
		$file = $this->target($path);
		$parent = dirname($file);
		if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) return false;
		return file_put_contents($file, $content, LOCK_EX) !== false;
	}

	public function copy(string $source, string $target): bool {
		$src = $this->target($source);
		$dst = $this->target($target);
		$parent = dirname($dst);
		if (!is_file($src)) return false;
		if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) return false;
		return copy($src, $dst);
	}

	public function move(string $source, string $target): bool {
		$src = $this->target($source);
		$dst = $this->target($target);
		$parent = dirname($dst);
		if (!is_file($src)) return false;
		if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) return false;
		return rename($src, $dst);
	}

	public function delete(string $path): bool {
		$file = $this->target($path);
		return is_file($file) && unlink($file);
	}

	public function mkdir(string $path): bool {
		$directory = $this->target($path);
		return is_dir($directory) || mkdir($directory, 0770, true);
	}

	public function rmdir(string $path): bool {
		$directory = $this->target($path);
		return $directory !== rtrim($this->root, '/\\') && is_dir($directory) && rmdir($directory);
	}

	public function exists(string $path): bool { return file_exists($this->target($path)); }
	public function stat(string $path): ?array {
		$file = $this->target($path);
		if (!file_exists($file)) return null;
		return ['name' => basename($file), 'type' => is_dir($file) ? 'directory' : 'file', 'size' => is_file($file) ? filesize($file) : 0, 'modified' => filemtime($file)];
	}

	private function target(string $relative): string {
		if (str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, "\0")) {
			throw new RuntimeException('BASE3 storage expects a relative path.');
		}
		$segments = explode('/', $relative);
		$current = rtrim($this->root, '/\\');
		foreach ($segments as $segment) {
			if ($segment === '') continue;
			if ($segment === '.' || $segment === '..') throw new RuntimeException('BASE3 storage path traversal is forbidden.');
			$current .= '/' . $segment;
			if (is_link($current)) throw new RuntimeException('BASE3 storage symbolic links are forbidden.');
		}
		return $current;
	}
}
