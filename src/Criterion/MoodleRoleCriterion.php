<?php declare(strict_types=1);

namespace Base3Moodle\Criterion;

use Base3\Usermanager\Api\IUserCriterion;
use Base3\Usermanager\User;
use InvalidArgumentException;

/** Matches Moodle-native role assignments in one explicit context, including its ancestor contexts. */
final class MoodleRoleCriterion implements IUserCriterion {

	/** @var array<string,list<int>> Native role identifiers, cached only for this PHP request. */
	private array $nativeRoles = [];

	public static function getName(): string { return 'moodlerolecriterion'; }

	public function getSchema(): array {
		return ['type' => 'object', 'required' => ['role_ids'], 'properties' => [
			'role_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'uniqueItems' => true],
			'context_id' => ['type' => 'integer', 'minimum' => 1]
		], 'additionalProperties' => false];
	}

	public function matches(?User $user, array $config): bool {
		$roles = $config['role_ids'] ?? null;
		$contextId = $config['context_id'] ?? null;
		if (!is_array($roles) || !array_is_list($roles) || ($contextId !== null && (!is_int($contextId) || $contextId <= 0))) {
			throw new InvalidArgumentException('Moodle role criterion requires role IDs and an optional positive context ID.');
		}
		foreach ($roles as $role) if (!is_int($role) || $role <= 0) throw new InvalidArgumentException('Invalid Moodle role ID.');
		if ($user === null || $roles === []) return false;
		if (!ctype_digit((string)$user->id) || (int)$user->id <= 0) return false;
		$context = $contextId === null ? \context_system::instance() : \context::instance_by_id($contextId, IGNORE_MISSING);
		if ($context === false) return false;
		$key = (int)$context->id . ':' . (int)$user->id;
		if (!isset($this->nativeRoles[$key])) {
			$this->nativeRoles[$key] = array_values(array_unique(array_map(
				static fn($assignment): int => (int)$assignment->roleid,
				get_user_roles($context, (int)$user->id, true)
			)));
		}
		return array_intersect($roles, $this->nativeRoles[$key]) !== [];
	}
}
