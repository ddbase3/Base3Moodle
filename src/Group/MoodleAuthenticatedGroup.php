<?php declare(strict_types=1);

namespace Base3Moodle\Group;

use Base3\Usermanager\Api\IGroup;
use Base3\Usermanager\Group;

final class MoodleAuthenticatedGroup implements IGroup {

	public static function getName(): string { return 'moodleauthenticatedgroup'; }
	public function getGroup(): Group { return Group::fromArray(['id' => 'authenticated', 'name' => 'authenticated', 'info' => 'Authenticated Moodle user', 'roles' => []]); }
	public function getCriteria(): array { return ['criterion' => 'base3authenticatedcriterion', 'config' => []]; }
	public function isEditable(): bool { return false; }
}
