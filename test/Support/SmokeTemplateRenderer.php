<?php declare(strict_types=1);

namespace Base3Moodle\Test\Support;

final class SmokeTemplateRenderer {
	public array $_ = [];
	public function render(string $file, array $data): string {
		$this->_ = $data;
		ob_start();
		try { require $file; return (string)ob_get_clean(); }
		catch (\Throwable $error) { ob_end_clean(); throw $error; }
	}
}
