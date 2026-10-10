<?php declare(strict_types=1);

namespace Base3Moodle\Api;

interface IBase3MoodleSettings {
	public function getAdministrationConfig(): array;
}
