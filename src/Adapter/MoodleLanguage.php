<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Language\Api\ILanguage;

final class MoodleLanguage implements ILanguage {
	private ?string $language = null;
	public function getLanguage(): string {
		return $this->language ?? strtolower(explode('_', str_replace('-', '_', current_language()))[0]);
	}
	public function setLanguage(string $language) { $this->language = strtolower(trim($language)); }
	public function getLanguages(): array { return array_values(array_unique([$this->getLanguage(), 'de', 'en'])); }
}
