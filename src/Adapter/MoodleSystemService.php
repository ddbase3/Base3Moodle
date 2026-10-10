<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Api\ISystemService;

final class MoodleSystemService implements ISystemService {
	public function getHostSystemName(): string { return 'Moodle'; }
	public function getHostSystemVersion(): string { global $CFG; return (string) ($CFG->release ?? $CFG->version ?? ''); }
	public function getEmbeddedSystemName(): string { return 'BASE3'; }
	public function getEmbeddedSystemVersion(): string {
		$manifest = json_decode((string) file_get_contents(DIR_ROOT . 'base3.json'), true);
		return (string) ($manifest['version'] ?? '');
	}
}
