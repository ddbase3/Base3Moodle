<?php declare(strict_types=1);

namespace Base3Moodle\Criterion;

use Base3\Usermanager\Api\IUserCriterion;
use Base3\Usermanager\User;
use InvalidArgumentException;

/** Delegates the native site-administrator identity check to Moodle. */
final class MoodleSiteAdministratorCriterion implements IUserCriterion {

	public static function getName(): string { return 'moodlesiteadministratorcriterion'; }

	public function getSchema(): array { return ['type' => 'object', 'properties' => [], 'additionalProperties' => false]; }

	public function matches(?User $user, array $config): bool {
		if ($config !== []) throw new InvalidArgumentException('Site administrator criterion accepts no configuration.');
		return $user !== null && ctype_digit((string)$user->id) && (int)$user->id > 0 && is_siteadmin((int)$user->id);
	}
}
