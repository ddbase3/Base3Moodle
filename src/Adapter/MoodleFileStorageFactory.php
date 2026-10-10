<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use ResourceFoundation\Api\IFileStorage;
use ResourceFoundation\Api\IFileStorageFactory;
use RuntimeException;

final class MoodleFileStorageFactory implements IFileStorageFactory {

	public function openStorage(string $id, string $mode): IFileStorage {
		if (!preg_match('/^[a-zA-Z0-9_-]{1,160}$/', $id) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $mode)) {
			throw new RuntimeException('Invalid BASE3 file storage identifier.');
		}
		return new MoodleFileStorage(DIR_LOCAL . 'storage/' . $mode . '/' . $id);
	}
}
