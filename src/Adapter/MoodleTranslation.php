<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Api\IModuleRegistry;
use Base3\Language\Api\ILanguage;
use Base3\Translation\Api\ITranslation;

final class MoodleTranslation implements ITranslation {

	private array $cache = [];

	public function __construct(private readonly ILanguage $language, private readonly IModuleRegistry $modules) {}

	public function translate(string $set, string $section, string $key, string $fallback = '', array $replacements = []): string {
		foreach (array_unique([$this->language->getLanguage(), 'en']) as $lang) {
			$parsed = $this->loadTranslations($set, $lang);
			if (isset($parsed[$section][$key]) && trim((string) $parsed[$section][$key]) !== '') {
				return $this->replace((string) $parsed[$section][$key], $replacements);
			}
		}
		return $this->replace($fallback !== '' ? $fallback : $key, $replacements);
	}

	private function loadTranslations(string $set, string $lang): array {
		$id = $set . ':' . $lang;
		if (isset($this->cache[$id])) return $this->cache[$id];
		$data = [];
		foreach ($this->modules->getModuleNames() as $name) {
			$path = $this->modules->requireModulePath($name) . '/lang/' . $set . '/' . $lang . '.ini';
			if (!is_file($path)) continue;
			$parsed = parse_ini_file($path, true, INI_SCANNER_RAW);
			if (is_array($parsed)) $data = array_replace_recursive($data, $parsed);
		}
		return $this->cache[$id] = $data;
	}

	private function replace(string $message, array $replacements): string {
		$map = [];
		foreach ($replacements as $key => $value) {
			$map[(string) $key] = (string) $value;
			$map['{' . $key . '}'] = (string) $value;
		}
		return strtr($message, $map);
	}
}
