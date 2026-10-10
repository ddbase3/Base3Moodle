<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Api\IModuleRegistry;
use Base3\Core\MvcView;
use Base3\Language\Api\ILanguage;

final class MoodleMvcView extends MvcView {

	public function __construct(ILanguage $language, private readonly IModuleRegistry $modules) {
		parent::__construct($language);
	}

	public function setPath(string $path = '.') {
		$normalized = rtrim(str_replace('\\', '/', $path), '/');
		$pluginRoot = rtrim(str_replace('\\', '/', DIR_PLUGIN), '/');
		if (str_starts_with($normalized, $pluginRoot . '/')) {
			$name = substr($normalized, strlen($pluginRoot) + 1);
			if ($name !== '' && !str_contains($name, '/')) {
				$resolved = $this->modules->getModulePath($name);
				if ($resolved !== null) { parent::setPath($resolved); return; }
			}
		}
		parent::setPath($path);
	}
}
