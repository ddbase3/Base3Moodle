<?php declare(strict_types=1);

namespace Base3\Base3Moodle\Group;

use Base3\CoreFoundation\Usermanager\Api\IGroup;
use Base3\CoreFoundation\Usermanager\Group;

final class MoodleAdministrationGroup implements IGroup {

	public static function getName(): string { return 'moodleadministrationgroup'; }
	public function getGroup(): Group { return Group::fromArray(['id' => 'administration', 'name' => 'administration', 'info' => 'Moodle site administrators', 'roles' => []]); }
	public function getCriteria(): array { return ['criterion' => 'moodlesiteadministratorcriterion', 'config' => []]; }
	public function isEditable(): bool { return true; }
}
