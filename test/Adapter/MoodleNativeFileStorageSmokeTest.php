<?php declare(strict_types=1);

/** CLI-only test of the Moodle storage API boundary and owner isolation. */

use Base3Moodle\Adapter\MoodleFileManagerHttpService;
use Base3Moodle\Adapter\MoodleFileStorageFactory;
use Base3Moodle\Adapter\MoodleFileUploadService;
use Base3Moodle\Adapter\MoodleManagedFileStorageService;
use Base3Moodle\Test\Support\FakeMoodleFileStorage;
use ResourceFoundation\Api\IManagedFileStorageService;

require_once __DIR__ . '/../../../ResourceFoundation/src/Api/IFileStorage.php';
require_once __DIR__ . '/../../../ResourceFoundation/src/Api/IFileStorageFactory.php';
require_once __DIR__ . '/../../../ResourceFoundation/src/Api/IManagedFileStorageService.php';
require_once __DIR__ . '/../Support/context.php';
require_once __DIR__ . '/../Support/context_system.php';
require_once __DIR__ . '/../Support/FakeMoodleStoredFile.php';
require_once __DIR__ . '/../Support/FakeMoodleFileStorage.php';
require_once __DIR__ . '/../../src/Adapter/MoodleFileStorage.php';
require_once __DIR__ . '/../../src/Adapter/MoodleFileStorageFactory.php';
require_once __DIR__ . '/../../src/Adapter/MoodleManagedFileStorageService.php';
require_once __DIR__ . '/../../src/Adapter/MoodleFileManagerHttpService.php';
require_once __DIR__ . '/../../src/Adapter/MoodleFileUploadService.php';

$GLOBALS['moodleFakeStorage'] = new FakeMoodleFileStorage();
function get_file_storage(): FakeMoodleFileStorage { return $GLOBALS['moodleFakeStorage']; }
function verifyStorage(bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}

$home = sys_get_temp_dir() . '/base3-moodle-storage-test-' . bin2hex(random_bytes(6)) . '/';
mkdir($home, 0700, true);
define('DIR_TMP', $home);
define('DIR_LOCAL', $home);
$managed = new MoodleManagedFileStorageService(new MoodleFileStorageFactory());
$mode = IManagedFileStorageService::MODE_COLLECTION;
verifyStorage(!$managed->has('chatbot', 'default', $mode), 'owner must be absent before creation');
$store = $managed->openOrCreate('chatbot', 'default', $mode);
verifyStorage($managed->has('chatbot', 'default', $mode), 'storage root is native Moodle directory');
verifyStorage($store->mkdir('reports/2026'), 'nested directory creation');
verifyStorage($store->write('reports/2026/a.pdf', 'aaa'), 'native file write');
verifyStorage($store->read('reports/2026/a.pdf') === 'aaa', 'native read');
verifyStorage(($store->stat('reports/2026')['type'] ?? '') === 'directory', 'directory stat');
verifyStorage(count($store->list('reports/2026')) === 1, 'native directory listing');
verifyStorage(($store->list('reports')[0]['name'] ?? '') === '2026', 'child directory name');
verifyStorage($store->copy('reports/2026/a.pdf', 'reports/2026/b.pdf'), 'native same-owner copy');
verifyStorage($store->move('reports/2026/b.pdf', 'reports/2026/c.pdf'), 'native same-owner move');
verifyStorage(!$store->exists('reports/2026/b.pdf'), 'move removes source');
verifyStorage(!$store->rmdir('reports/2026'), 'nonempty directory cannot be removed');
$other = $managed->openOrCreate('chatbot', 'other', $mode);
verifyStorage(!$other->exists('reports/2026/a.pdf'), 'different owner cannot read source file');
$managed->copyOwner('chatbot', 'default', 'chatbot', 'copy', $mode);
verifyStorage($managed->openOrCreate('chatbot', 'copy', $mode)->read('reports/2026/a.pdf') === 'aaa', 'cross-owner native file copy');
$managed->delete('chatbot', 'copy', $mode);
verifyStorage(!$managed->has('chatbot', 'copy', $mode), 'delete removes only target owner');
verifyStorage($store->read('reports/2026/a.pdf') === 'aaa', 'delete preserves unrelated owner');
$rejected = false;
try { $store->write('../outside', 'bad'); } catch (RuntimeException) { $rejected = true; }
verifyStorage($rejected, 'path traversal rejected');

$uploader = new MoodleFileUploadService($managed);
$started = $uploader->start('chatbot', 'default', $mode, 42, 1024, [
	'name' => 'uploaded.pdf', 'path' => 'reports/2026', 'size' => 6, 'chunkSize' => 3, 'totalChunks' => 2
]);
$id = $started['uploadId'];
foreach (['abc', 'def'] as $index => $chunk) {
	$tmp = $home . 'uploadpart-' . $index;
	file_put_contents($tmp, $chunk);
	$uploader->saveChunk('chatbot', 'default', $mode, 42, ['uploadId' => $id, 'index' => $index], [
		'chunk' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp]
	]);
	unlink($tmp);
}
verifyStorage($uploader->status('chatbot', 'default', $mode, 42, $id)['uploadedChunks'] === [0, 1], 'chunk status');
$result = $uploader->finish('chatbot', 'default', $mode, 42, $id);
verifyStorage($result['kind'] === 'file', 'upload returns file descriptor');
verifyStorage($store->read('reports/2026/uploaded.pdf') === 'abcdef', 'chunked upload stored in Moodle File API');
$denied = false;
try { $uploader->status('chatbot', 'other', $mode, 42, $id); } catch (Throwable) { $denied = true; }
verifyStorage($denied, 'upload metadata scoped to owner');
$managed->delete('chatbot', 'default', $mode);
verifyStorage(!$managed->has('chatbot', 'default', $mode), 'recursive owner deletion');
@rmdir($home . 'filemanager-upload/' . hash('sha256', "chatbot\0default"));
@rmdir($home . 'filemanager-upload');
@rmdir($home);
echo "Moodle native File API smoke test passed.\n";
