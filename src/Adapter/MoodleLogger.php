<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Logger\AbstractLogger;

final class MoodleLogger extends AbstractLogger {
	public function logLevel(string $level, string|\Stringable $message, array $context = []): void {
		$scope = preg_replace('/[^a-z0-9_.-]/i', '_', (string) ($context['scope'] ?? 'default'));
		$line = '[BASE3][' . strtoupper($level) . '][' . $scope . '] ' . (string) $message;
		// Avoid logging passwords, tokens, and other user-provided data.
		error_log($line);
	}
}
