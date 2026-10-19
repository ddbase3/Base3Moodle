<?php declare(strict_types=1);

namespace Base3Moodle\Api;

/** One authoritative catalog of Moodle project displays and a derived visibility view. */
interface IBase3MoodleSettings {

	/**
	 * Returns the complete project-owned display catalog, independently of current-user navigation.
	 * It is used by the Moodle runtime to identify administrative endpoints even when hidden.
	 * @return list<array<string,mixed>> Complete admin/display configurations, including non-admin feature displays
	 */
	public function getAdministrationConfig(): array;

	/**
	 * Filters the same catalog for the current user's navigation without granting endpoint access.
	 * Unauthorized direct calls remain guarded by the host runtime and by each display.
	 * @return list<array<string,mixed>> Current-user-visible navigation only
	 */
	public function getVisibleAdministrationConfig(): array;
}
