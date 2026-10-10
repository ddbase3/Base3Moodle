<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Accesscontrol\Api\IAccesscontrol;

final class MoodleAccesscontrol implements IAccesscontrol {
	public function getUserId() { global $USER; return isloggedin() && !isguestuser() ? (int) $USER->id : 0; }
	public function authenticate(): void {
		// Moodle has already bootstrapped its session. The requested display owns
		// its business permission checks; admin endpoints use require_capability().
	}
}
