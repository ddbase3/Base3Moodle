<?php declare(strict_types=1);

/** Standalone smoke for the real BASE3 Moodle file dispatch and protocol contracts. */

$modules = dirname(__DIR__, 3);
spl_autoload_register(static function(string $class) use ($modules): void {
	$parts = explode('\\', $class, 2);
	$module = $parts[0] === 'Base3' ? 'Base3Framework' : $parts[0];
	$file = $modules . '/' . $module . '/src/' . str_replace('\\', '/', $parts[1] ?? '') . '.php';
	if (is_file($file)) require_once $file;
});

use Base3Moodle\Adapter\Base3MoodleFileManagerOutput;
use Base3Moodle\Adapter\MoodleFileManagerHttpService;

function verifyMoodleFileManager(bool $condition, string $label): void {
	if (!$condition) throw new RuntimeException('FAILED: ' . $label);
}

verifyMoodleFileManager(
	Base3MoodleFileManagerOutput::getName() === 'base3moodlefilemanageroutput',
	'existing generic AJAX dispatch name remains stable'
);
$constructor = (new ReflectionClass(Base3MoodleFileManagerOutput::class))->getConstructor();
verifyMoodleFileManager($constructor !== null, 'FileManager output uses injected dependencies');
$parameters = $constructor->getParameters();
verifyMoodleFileManager(array_map(static fn(ReflectionParameter $p): string => $p->getName(), $parameters) === [
	'request', 'usermanager', 'authorization', 'chatbotConfigDisplayData', 'fileManagerHttpService'
], 'project-owned chatbot display configuration replaces static tab catalog');
verifyMoodleFileManager((string)$parameters[3]->getType() === 'array', 'owner configuration is lazy project data');

verifyMoodleFileManager(MoodleFileManagerHttpService::normalizePath('course/docs') === 'course/docs', 'nested file paths remain accepted');
verifyMoodleFileManager(MoodleFileManagerHttpService::joinPath('course', 'doc.pdf') === 'course/doc.pdf', 'path combination remains stable');
$traversalDenied = false;
try { MoodleFileManagerHttpService::normalizePath('../secret'); }
catch (InvalidArgumentException) { $traversalDenied = true; }
verifyMoodleFileManager($traversalDenied, 'FileManager path traversal denied');
verifyMoodleFileManager(MoodleFileManagerHttpService::descriptor('docs/manual.pdf', [
	'name' => 'manual.pdf', 'type' => 'file', 'size' => 12, 'modified' => 1234
]) === [
	'id' => 'docs/manual.pdf', 'name' => 'manual.pdf', 'path' => 'docs/manual.pdf',
	'kind' => 'file', 'size' => 12, 'mimeType' => '', 'modifiedAt' => '1234'
], 'FileManager response descriptor remains compatible');

$source = file_get_contents(dirname(__DIR__, 2) . '/src/Adapter/Base3MoodleFileManagerOutput.php');
verifyMoodleFileManager(is_string($source) && str_contains($source, "Permission::for('moodle', 'moodle/site:config')")
	&& str_contains($source, 'confirm_sesskey()'), 'FileManager endpoint retains native capability and CSRF enforcement');
verifyMoodleFileManager(!is_file(dirname(__DIR__, 2) . '/src/Api/IBase3MoodleSettings.php'), 'obsolete static settings contract removed');
echo "BASE3 Moodle FileManager dispatch smoke passed.\n";
