<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use InvalidArgumentException;
use JsonException;
use ResourceFoundation\Api\IManagedFileStorageService;
use RuntimeException;
use Throwable;

/** Native Moodle HTTP endpoint implementing the existing ClientStack FileManager protocol. */
final class MoodleFileManagerHttpService {

	public function __construct(
		private readonly IManagedFileStorageService $storage,
		private readonly MoodleFileUploadService $uploads
	) {}

	public function handle(string $action, string $ownerGroup, string $ownerName, int $userId, int $maxFileSize): never {
		$mode = IManagedFileStorageService::MODE_COLLECTION;
		if ($action === 'download') {
			try {
				$this->download($ownerGroup, $ownerName, $mode);
			} catch (Throwable $error) {
				http_response_code(404); echo 'File not found.';
			}
			exit;
		}

		try {
			$body = $action === 'uploadChunk' ? [] : $this->jsonBody();
			$storage = $this->storage->openOrCreate($ownerGroup, $ownerName, $mode);
			$result = match ($action) {
				'list' => $this->listItems($ownerGroup, $ownerName, $mode, $body),
				'stat' => $this->statItem($storage, $body),
				'mkdir' => $this->changePath($storage, $body, 'mkdir'),
				'delete' => $this->changePath($storage, $body, 'delete'),
				'rmdir' => $this->changePath($storage, $body, 'rmdir'),
				'copy' => $this->changePath($storage, $body, 'copy'),
				'move' => $this->changePath($storage, $body, 'move'),
				'uploadStart' => $this->uploads->start($ownerGroup, $ownerName, $mode, $userId, $maxFileSize, $body),
				'uploadChunk' => $this->uploads->saveChunk($ownerGroup, $ownerName, $mode, $userId, $_POST, $_FILES),
				'uploadStatus' => $this->uploads->status($ownerGroup, $ownerName, $mode, $userId, (string) ($body['uploadId'] ?? '')),
				'uploadFinish' => $this->uploads->finish($ownerGroup, $ownerName, $mode, $userId, (string) ($body['uploadId'] ?? '')),
				'uploadAbort' => $this->uploads->abort($ownerGroup, $ownerName, $mode, $userId, (string) ($body['uploadId'] ?? '')),
				default => throw new InvalidArgumentException('Unknown FileManager action.')
			};
			$status = 200;
		} catch (InvalidArgumentException|JsonException $error) {
			$status = 400;
			$result = ['message' => $error->getMessage()];
		} catch (Throwable $error) {
			$status = 500;
			$result = ['message' => $error->getMessage()];
		}
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		exit;
	}

	private function listItems(string $group, string $name, string $mode, array $body): array {
		$path = self::normalizePath((string) ($body['path'] ?? ''));
		$storage = $this->storage->openOrCreate($group, $name, $mode);
		$items = [];
		foreach ($storage->list($path) as $entry) {
			$itemPath = self::joinPath($path, (string) ($entry['name'] ?? ''));
			$items[] = self::descriptor($itemPath, $entry);
		}
		return [
			'items' => $items,
			'path' => $path,
			'containerId' => hash('sha256', $group . "\0" . $name)
		];
	}

	private function statItem($storage, array $body): array {
		$path = self::normalizePath((string) ($body['path'] ?? ''));
		$descriptor = self::descriptor($path, $storage->stat($path));
		if ($descriptor === null) throw new InvalidArgumentException('File or directory not found.');
		return $descriptor;
	}

	private function changePath($storage, array $body, string $action): array {
		if ($action === 'copy' || $action === 'move') {
			$source = self::normalizePath((string) ($body['sourcePath'] ?? ''));
			$target = self::normalizePath((string) ($body['targetPath'] ?? ''));
			if ($source === '' || $target === '' || !$storage->{$action}($source, $target)) {
				throw new RuntimeException('File ' . $action . ' failed.');
			}
		} else {
			$path = self::normalizePath((string) ($body['path'] ?? ''));
			if ($path === '' || !$storage->{$action}($path)) {
				throw new RuntimeException('File operation ' . $action . ' failed.');
			}
		}
		return ['success' => true];
	}

	private function download(string $group, string $name, string $mode): void {
		$path = isset($_GET['path']) && is_scalar($_GET['path'])
			? self::normalizePath((string) $_GET['path'])
			: '';
		if ($path === '') throw new InvalidArgumentException('File path is required.');
		$storage = $this->storage->openOrCreate($group, $name, $mode);
		$stat = $storage->stat($path);
		if ($stat === null || ($stat['type'] ?? '') !== 'file') throw new InvalidArgumentException('File not found.');
		$content = $storage->read($path);
		$name = basename($path);
		$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';
		header('Content-Type: application/octet-stream');
		header('Content-Length: ' . strlen($content));
		header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
		header('X-Content-Type-Options: nosniff');
		echo $content;
	}

	private function jsonBody(): array {
		$raw = file_get_contents('php://input');
		if ($raw === false || trim($raw) === '') return [];
		$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) throw new InvalidArgumentException('JSON object expected.');
		return $data;
	}

	public static function normalizePath(string $path): string {
		$path = str_replace('\\', '/', trim($path));
		$path = ltrim($path, '/');
		if ($path === '.' || $path === '') return '';
		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') continue;
			if ($segment === '..' || str_contains($segment, "\0")) {
				throw new InvalidArgumentException('Invalid file path.');
			}
			$segments[] = $segment;
		}
		return implode('/', $segments);
	}

	public static function joinPath(string $path, string $name): string {
		$path = self::normalizePath($path);
		$name = self::normalizePath($name);
		return $path === '' ? $name : ($name === '' ? $path : $path . '/' . $name);
	}

	public static function descriptor(string $path, ?array $stat): ?array {
		if ($stat === null) return null;
		$type = (string) ($stat['type'] ?? '');
		if (!in_array($type, ['file', 'directory', 'dir'], true)) {
			throw new RuntimeException('Unsupported file storage entry type: ' . $type);
		}
		return [
			'id' => $path === '' ? '/' : $path,
			'name' => $path === '' ? '/' : basename($path),
			'path' => $path,
			'kind' => $type === 'file' ? 'file' : 'directory',
			'size' => $type === 'file' ? (int) ($stat['size'] ?? 0) : null,
			'mimeType' => '',
			'modifiedAt' => (string) ($stat['modified'] ?? '')
		];
	}
}
