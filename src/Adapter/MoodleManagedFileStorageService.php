<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use ResourceFoundation\Api\IFileStorage;
use ResourceFoundation\Api\IFileStorageFactory;
use ResourceFoundation\Api\IManagedFileStorageService;
use RuntimeException;

/** Owner-scoped Moodle File API areas; no secondary file metadata or owner registry. */
final class MoodleManagedFileStorageService implements IManagedFileStorageService {

	public function __construct(private readonly IFileStorageFactory $factory) {}

	public function openOrCreate(string $ownerGroup, string $ownerName, string $mode): IFileStorage {
		$storage = $this->open($ownerGroup, $ownerName, $mode);
		if (!$storage->mkdir('')) throw new RuntimeException('Cannot create BASE3 storage root.');
		return $storage;
	}

	public function has(string $ownerGroup, string $ownerName, string $mode): bool {
		return $this->open($ownerGroup, $ownerName, $mode)->exists('');
	}

	public function delete(string $ownerGroup, string $ownerName, string $mode): void {
		$storage = $this->open($ownerGroup, $ownerName, $mode);
		$storage->deleteStorage();
	}

	public function copyOwner(string $sourceGroup, string $sourceName, string $targetGroup, string $targetName, string $mode): void {
		if (!$this->has($sourceGroup, $sourceName, $mode)) throw new RuntimeException('Source BASE3 storage does not exist.');
		if ($this->has($targetGroup, $targetName, $mode)) throw new RuntimeException('Target BASE3 storage already exists.');
		$source = $this->open($sourceGroup, $sourceName, $mode);
		$target = $this->openOrCreate($targetGroup, $targetName, $mode);
		$this->copyContent($source, $target);
	}

	private function copyContent(MoodleFileStorage $source, MoodleFileStorage $target, string $prefix = ''): void {
		foreach ($source->list($prefix) as $item) {
			$path = ($prefix === '' ? '' : $prefix . '/') . $item['name'];
			if ($item['type'] === 'directory') {
				if (!$target->mkdir($path)) throw new RuntimeException('Cannot create target BASE3 directory.');
				$this->copyContent($source, $target, $path);
			} elseif (!$source->copyTo($target, $path, $path)) {
				throw new RuntimeException('Cannot copy BASE3 stored file.');
			}
		}
	}

	private function open(string $ownerGroup, string $ownerName, string $mode): MoodleFileStorage {
		if ($ownerGroup === '' || $ownerName === '') throw new RuntimeException('BASE3 storage owner cannot be empty.');
		if (!in_array($mode, [self::MODE_COLLECTION, self::MODE_CONTAINER], true)) {
			throw new RuntimeException('Unknown BASE3 file storage mode: ' . $mode);
		}
		$storage = $this->factory->openStorage(hash('sha256', $ownerGroup . "\0" . $ownerName), $mode);
		if (!$storage instanceof MoodleFileStorage) throw new RuntimeException('Moodle File API storage is not configured.');
		return $storage;
	}
}
