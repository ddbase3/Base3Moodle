<?php declare(strict_types=1);

namespace Base3Moodle\Group;

use Base3\Usermanager\Api\IGroup;
use Base3\Usermanager\Group;

final class MoodleAnonymousGroup implements IGroup {

	public static function getName(): string { return 'moodleanonymousgroup'; }
	public function getGroup(): Group { return Group::fromArray(['id' => 'anonymous', 'name' => 'anonymous', 'info' => 'Anonymous visitor', 'roles' => []]); }
	public function getCriteria(): array { return ['criterion' => 'base3anonymouscriterion', 'config' => []]; }
	public function isEditable(): bool { return false; }
}
