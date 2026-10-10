<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Api\IAssetResolver;
use Base3\Api\IModuleRegistry;

/** Asset URLs preserve the module's static directory structure for CSS and JS imports. */
final class MoodleAssetResolver implements IAssetResolver {

	public function __construct(private readonly IModuleRegistry $modules) {}

	public function resolve(string $path): string {
		if (!preg_match('~^plugin/([a-zA-Z_][a-zA-Z0-9_]*)/assets/(.+)$~D', $path, $match)) return $path;
		$module = $match[1];
		$relative = $match[2];
		$root = $this->modules->getModulePath($module);
		if ($root === null || str_contains($relative, "\\") || str_contains($relative, "\0") || in_array('..', explode('/', $relative), true)) return $path;
		$assetroot = realpath($root . '/assets');
		$file = realpath($root . '/assets/' . $relative);
		if ($assetroot === false || $file === false || !str_starts_with($file, $assetroot . '/') || !is_file($file)) return $path;
		// The deployment provides one public symlink for each installed module asset directory.
		// Static path addressing is required by relative ES module imports and CSS url() assets.
		return (new \moodle_url('/local/base3/assets/' . $module . '/' . $relative, [
			'v' => substr(hash_file('sha256', $file), 0, 12)
		]))->out(false);
	}
}
