<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use ResourceFoundation\Api\IFileStorage;
use ResourceFoundation\Api\IFileStorageFactory;
use RuntimeException;

final class MoodleFileStorageFactory implements IFileStorageFactory {

	public function openStorage(string $id, string $mode): IFileStorage {
		if (!preg_match('/^[a-f0-9]{64}$/', $id) || !in_array($mode, ['collection', 'container'], true)) {
			throw new RuntimeException('Invalid BASE3 file storage identifier.');
		}
		return new MoodleFileStorage($id, $mode);
	}
}
