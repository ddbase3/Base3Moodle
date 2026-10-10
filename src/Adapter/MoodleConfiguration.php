<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Configuration\AbstractConfiguration;

final class MoodleConfiguration extends AbstractConfiguration {

	protected function load(): array {
		$stored = get_config('local_base3', 'configuration');
		$data = is_string($stored) && $stored !== '' ? json_decode($stored, true) : [];
		if (!is_array($data)) throw new \RuntimeException('Invalid BASE3 configuration stored in Moodle.');
		$data['directories'] = ['data' => DIR_LOCAL];
		$data['base'] = array_replace(['endpoint' => (new \moodle_url('/local/base3/ajax.php'))->out(false)], (array) ($data['base'] ?? []));
		return $data;
	}

	protected function saveData(array $data): bool {
		unset($data['directories']);
		$encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		return set_config('configuration', $encoded, 'local_base3');
	}
}
