<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use ResourceFoundation\Api\IFileStorage;
use ResourceFoundation\Api\IFileStorageFactory;
use ResourceFoundation\Api\IManagedFileStorageService;
use RuntimeException;

/** Deterministic owner-scoped storages, without a second owner registry or state layer. */
final class MoodleManagedFileStorageService implements IManagedFileStorageService {

	public function __construct(private readonly IFileStorageFactory $factory) {}

	public function openOrCreate(string $ownerGroup, string $ownerName, string $mode): IFileStorage {
		$this->validateMode($mode);
		return $this->factory->openStorage($this->id($ownerGroup, $ownerName), $mode);
	}

	public function has(string $ownerGroup, string $ownerName, string $mode): bool {
		$this->validateMode($mode);
		return is_dir(DIR_LOCAL . 'storage/' . $mode . '/' . $this->id($ownerGroup, $ownerName));
	}

	public function delete(string $ownerGroup, string $ownerName, string $mode): void {
		$this->validateMode($mode);
		$path = DIR_LOCAL . 'storage/' . $mode . '/' . $this->id($ownerGroup, $ownerName);
		if (!is_dir($path)) return;
		$this->removeTree($path);
	}

	public function copyOwner(string $sourceGroup, string $sourceName, string $targetGroup, string $targetName, string $mode): void {
		$this->validateMode($mode);
		if (!$this->has($sourceGroup, $sourceName, $mode)) throw new RuntimeException('Source BASE3 storage does not exist.');
		if ($this->has($targetGroup, $targetName, $mode)) throw new RuntimeException('Target BASE3 storage already exists.');
		$source = $this->openOrCreate($sourceGroup, $sourceName, $mode);
		$target = $this->openOrCreate($targetGroup, $targetName, $mode);
		$this->copyContent($source, $target);
	}

	private function copyContent(IFileStorage $source, IFileStorage $target, string $prefix = ''): void {
		foreach ($source->list($prefix) as $item) {
			$path = ($prefix !== '' ? $prefix . '/' : '') . $item['name'];
			if ($item['type'] === 'directory') {
				if (!$target->mkdir($path)) throw new RuntimeException('Cannot create target BASE3 directory.');
				$this->copyContent($source, $target, $path);
			} elseif (!$target->write($path, $source->read($path))) {
				throw new RuntimeException('Cannot copy BASE3 storage file.');
			}
		}
	}

	private function removeTree(string $path): void {
		foreach (new \DirectoryIterator($path) as $item) {
			if ($item->isDot()) continue;
			if ($item->isLink()) throw new RuntimeException('Symbolic links are not allowed in BASE3 file storage.');
			$itemPath = $item->getPathname();
			if ($item->isDir()) $this->removeTree($itemPath);
			elseif (!unlink($itemPath)) throw new RuntimeException('Cannot delete BASE3 storage file.');
		}
		if (!rmdir($path)) throw new RuntimeException('Cannot remove BASE3 storage directory.');
	}

	private function id(string $ownerGroup, string $ownerName): string {
		if ($ownerGroup === '' || $ownerName === '') throw new RuntimeException('BASE3 storage owner cannot be empty.');
		return hash('sha256', $ownerGroup . "\0" . $ownerName);
	}

	private function validateMode(string $mode): void {
		if (!in_array($mode, [self::MODE_COLLECTION, self::MODE_CONTAINER], true)) {
			throw new RuntimeException('Unknown BASE3 file storage mode: ' . $mode);
		}
	}
}
