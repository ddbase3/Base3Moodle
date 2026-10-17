<?php declare(strict_types=1);

// Run with: php test/Adapter/Base3MoodleFileManagerOutputSmokeTest.php
// Isolated BASE3 dispatch/owner/permission checks without a Moodle installation.

namespace Base3\Api {
	interface IBase { public static function getName(): string; }
	interface IOutput extends IBase { public function getOutput(string $out = 'html', bool $final = false): string; }
	interface IRequest { public function get(string $key, $default = null); }
}

namespace Base3\Usermanager {
	final class Permission {
		public function __construct(public string $scope, public string $permission) {}
		public static function for(string $scope, string $permission): self { return new self($scope, $permission); }
	}
}

namespace Base3\Usermanager\Api {
	interface IUsermanager {
		public function getUser();
		public function can(\Base3\Usermanager\Permission $permission): bool;
	}
}

namespace Base3\LinkTarget\Api {
	interface ILinkTargetService { public function getLink(array $target, array $params = []): string; }
}

namespace Base3\Translation\Api {
	interface ITranslation { public function translate(string $scope, string $domain, string $key, string $fallback): string; }
}

namespace Base3Moodle\Api {
	interface IBase3MoodleSettings { public function getAdministrationConfig(): array; }
}

namespace Base3Moodle\Adapter {
	final class MoodleFileManagerHttpService {
		public static array $calls = [];
		public function handle(string $action, string $group, string $name, int $userId, int $maxFileSize): never {
			self::$calls[] = compact('action', 'group', 'name', 'userId', 'maxFileSize');
			throw new \RuntimeException('HTTP handler reached');
		}
	}
}

namespace {
	use Base3\Api\IRequest;
	use Base3\LinkTarget\Api\ILinkTargetService;
	use Base3\Translation\Api\ITranslation;
	use Base3\Usermanager\Api\IUsermanager;
	use Base3\Usermanager\Permission;
	use Base3Moodle\Adapter\Base3MoodleFileManagerOutput;
	use Base3Moodle\Adapter\MoodleFileManagerHttpService;
	use Base3Moodle\Adapter\MoodleLinkTargetService;
	use Base3Moodle\Api\IBase3MoodleSettings;
	use Base3MoodleLab\Service\Base3MoodleLabSettings;

	function sesskey(): string { return 'example-sesskey'; }
	function confirm_sesskey(): bool {
		$GLOBALS['sesskey_checks'] = ($GLOBALS['sesskey_checks'] ?? 0) + 1;
		return $GLOBALS['sesskey_valid'] ?? false;
	}

	final class moodle_url {
		public function __construct(private string $path, private array $params = []) {}
		public function out(bool $escaped = true): string { return $this->path . '?' . http_build_query($this->params); }
	}

	final class TestRequest implements IRequest {
		public function __construct(private array $query) {}
		public function get(string $key, $default = null) { return $this->query[$key] ?? $default; }
	}

	final class TestUsermanager implements IUsermanager {
		public function __construct(private bool $siteConfigurationAllowed, private ?object $user) {}
		public function getUser(): ?object { return $this->user; }
		public function can(Permission $permission): bool {
			return $this->siteConfigurationAllowed && $permission->scope === 'moodle'
				&& $permission->permission === 'moodle/site:config';
		}
	}

	final class TestTranslation implements ITranslation {
		public function translate(string $scope, string $domain, string $key, string $fallback): string { return $fallback; }
	}

	final class TestSettings implements IBase3MoodleSettings {
		public function __construct(private array $tabs) {}
		public function getAdministrationConfig(): array { return $this->tabs; }
	}

	function verify(bool $result, string $message): void {
		if (!$result) throw new \RuntimeException('FAILED: ' . $message);
		echo 'PASS: ' . $message . PHP_EOL;
	}

	require_once __DIR__ . '/../../src/Adapter/Base3MoodleFileManagerOutput.php';
	require_once __DIR__ . '/../../src/Adapter/MoodleLinkTargetService.php';
	require_once __DIR__ . '/../../../Base3MoodleLab/src/Service/Base3MoodleLabSettings.php';

	ob_start();
	$lab = new Base3MoodleLabSettings(new TestTranslation(), new MoodleLinkTargetService());
	$tabs = $lab->getAdministrationConfig();
	$config = null;
	foreach ($tabs as $tab) {
		foreach (($tab['displays'] ?? []) as $entry) {
			if (($entry['name'] ?? '') === 'chatbotconfigdisplay') $config = $entry['data'] ?? null;
		}
	}
	verify(is_array($config), 'project settings contain the chatbot configuration');
	verify(($config['group'] ?? null) === 'chatbot' && ($config['name'] ?? null) === 'default', 'file storage owner is unchanged');

	$actions = ['list', 'stat', 'mkdir', 'delete', 'rmdir', 'copy', 'move', 'uploadStart', 'uploadChunk', 'uploadStatus', 'uploadFinish', 'uploadAbort', 'download'];
	verify(count($config['resources']['endpoints'] ?? []) === count($actions), 'all 13 FileManager URLs are present');
	foreach ($actions as $action) {
		$url = $config['resources']['endpoints'][$action] ?? '';
		parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
		verify(
			parse_url($url, PHP_URL_PATH) === '/local/base3/ajax.php'
				&& ($params['name'] ?? null) === 'base3moodlefilemanageroutput'
				&& ($params['out'] ?? null) === 'json'
				&& ($params['fm_action'] ?? null) === $action
				&& ($params['sesskey'] ?? null) === sesskey(),
			'generic BASE3 dispatch for ' . $action
		);
	}
	verify(Base3MoodleFileManagerOutput::getName() === strtolower((new \ReflectionClass(Base3MoodleFileManagerOutput::class))->getShortName()), 'classmap name matches lowercase class');

	$settings = new TestSettings($tabs);
	$handler = new MoodleFileManagerHttpService();
	$request = new TestRequest(['fm_action' => 'uploadChunk']);
	$GLOBALS['sesskey_valid'] = true;
	$GLOBALS['sesskey_checks'] = 0;
	$output = new Base3MoodleFileManagerOutput($request, new TestUsermanager(false, (object) ['id' => 42]), $settings, $handler);
	http_response_code(200);
	verify(json_decode($output->getOutput('json', true), true) === ['message' => 'BASE3 access permission or sesskey missing.'] && http_response_code() === 403, 'missing site configuration permission denied');
	verify($GLOBALS['sesskey_checks'] === 0, 'permission checked before sesskey');

	$output = new Base3MoodleFileManagerOutput($request, new TestUsermanager(true, null), $settings, $handler);
	http_response_code(200);
	verify(json_decode($output->getOutput('json', true), true) === ['message' => 'BASE3 access permission or sesskey missing.'] && http_response_code() === 403, 'missing login identity denied');

	$GLOBALS['sesskey_valid'] = false;
	$output = new Base3MoodleFileManagerOutput($request, new TestUsermanager(true, (object) ['id' => 42]), $settings, $handler);
	http_response_code(200);
	verify(json_decode($output->getOutput('json', true), true) === ['message' => 'BASE3 access permission or sesskey missing.'] && http_response_code() === 403, 'invalid Moodle sesskey denied');

	$GLOBALS['sesskey_valid'] = true;
	$output = new Base3MoodleFileManagerOutput($request, new TestUsermanager(true, (object) ['id' => 42]), new TestSettings([]), $handler);
	$missingOwner = false;
	try { $output->getOutput('json', true); } catch (\RuntimeException $error) { $missingOwner = $error->getMessage() === 'Chatbot administration storage owner is not configured.'; }
	verify($missingOwner, 'no fallback for missing configured owner');

	$output = new Base3MoodleFileManagerOutput($request, new TestUsermanager(true, (object) ['id' => 42]), $settings, $handler);
	$invoked = false;
	try { $output->getOutput('json', true); } catch (\RuntimeException $error) { $invoked = $error->getMessage() === 'HTTP handler reached'; }
	verify($invoked, 'authorized upload reaches existing FileManager service');
	verify(MoodleFileManagerHttpService::$calls === [[
		'action' => 'uploadChunk', 'group' => 'chatbot', 'name' => 'default', 'userId' => 42, 'maxFileSize' => 50 * 1024 * 1024
	]], 'original owner, action, user and max size preserved');

	$output = new Base3MoodleFileManagerOutput(new TestRequest(['fm_action' => 'download']), new TestUsermanager(true, (object) ['id' => 42]), $settings, $handler);
	try { $output->getOutput('json', true); } catch (\RuntimeException $error) { verify($error->getMessage() === 'HTTP handler reached', 'download reaches existing FileManager service'); }
	verify((MoodleFileManagerHttpService::$calls[1]['action'] ?? '') === 'download', 'download retains action');

	$ajaxSource = file_get_contents(__DIR__ . '/../../../../moodle/local/base3/ajax.php');
	verify(is_string($ajaxSource) && !str_contains($ajaxSource, 'FileManager') && str_contains($ajaxSource, 'Runtime::render'), 'native AJAX entry remains generic');

	echo 'BASE3 Moodle generic FileManager dispatch smoke test passed.' . PHP_EOL;
	ob_end_flush();
}
