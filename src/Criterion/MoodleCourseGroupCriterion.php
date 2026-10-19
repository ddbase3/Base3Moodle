<?php declare(strict_types=1);

namespace Base3Moodle\Criterion;

use Base3\Usermanager\Api\IUserCriterion;
use Base3\Usermanager\User;
use InvalidArgumentException;

/** Delegates current or explicitly provided user membership to Moodle's native course group service. */
final class MoodleCourseGroupCriterion implements IUserCriterion {

	public static function getName(): string { return 'moodlecoursegroupcriterion'; }

	public function getSchema(): array {
		return ['type' => 'object', 'required' => ['course_id', 'group_id'], 'properties' => [
			'course_id' => ['type' => 'integer', 'minimum' => 1],
			'group_id' => ['type' => 'integer', 'minimum' => 1]
		], 'additionalProperties' => false];
	}

	public function matches(?User $user, array $config): bool {
		$courseId = $config['course_id'] ?? null;
		$groupId = $config['group_id'] ?? null;
		if (!is_int($courseId) || $courseId <= 0 || !is_int($groupId) || $groupId <= 0) {
			throw new InvalidArgumentException('Moodle course group requires positive course and group IDs.');
		}
		if ($user === null || !ctype_digit((string)$user->id) || (int)$user->id <= 0) return false;
		$group = groups_get_group($groupId, 'id,courseid');
		if ($group === false || (int)$group->courseid !== $courseId) return false;
		return groups_is_member($groupId, (int)$user->id);
	}
}
