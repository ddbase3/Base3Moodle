<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3Moodle\Api\IBase3MoodleSettings;
use RuntimeException;

/** Routes existing Moodle FileManager HTTP operations through the generic BASE3 output. */
final class Base3MoodleFileManagerOutput implements IOutput {

	private const MAX_FILE_SIZE = 50 * 1024 * 1024;

	public function __construct(
		private readonly IRequest $request,
		private readonly IUsermanager $usermanager,
		private readonly IBase3MoodleSettings $settings,
		private readonly MoodleFileManagerHttpService $fileManagerHttpService
	) {}

	public static function getName(): string {
		return 'base3moodlefilemanageroutput';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		$userId = (int) ($this->usermanager->getUser()?->id ?? 0);
		if ($userId <= 0
			|| !$this->usermanager->can(Permission::for('moodle', 'moodle/site:config'))
			|| !confirm_sesskey()) {
			return $this->errorResponse(403, 'BASE3 access permission or sesskey missing.');
		}

		[$group, $name] = $this->configuredOwner();
		$action = $this->request->get('fm_action', '');
		$this->fileManagerHttpService->handle(
			is_scalar($action) ? trim((string) $action) : '',
			$group,
			$name,
			$userId,
			self::MAX_FILE_SIZE
		);
	}

	/** @return array{0:string,1:string} */
	private function configuredOwner(): array {
		$config = null;
		foreach ($this->settings->getAdministrationConfig() as $tab) {
			foreach ($tab['displays'] ?? [] as $entry) {
				if (($entry['name'] ?? '') !== 'chatbotconfigdisplay') continue;
				if ($config !== null) throw new RuntimeException('Duplicate chatbot administration configuration.');
				$config = $entry['data'] ?? null;
			}
		}

		$group = is_array($config) ? trim((string) ($config['group'] ?? '')) : '';
		$name = is_array($config) ? trim((string) ($config['name'] ?? '')) : '';
		if ($group === '' || $name === '') {
			throw new RuntimeException('Chatbot administration storage owner is not configured.');
		}
		return [$group, $name];
	}

	private function errorResponse(int $status, string $message): string {
		http_response_code($status);
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			header('X-Content-Type-Options: nosniff');
		}
		return json_encode(['message' => $message], JSON_THROW_ON_ERROR);
	}
}
