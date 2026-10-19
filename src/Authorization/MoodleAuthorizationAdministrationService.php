<?php declare(strict_types=1);

namespace Base3Moodle\Authorization;

use Base3\Authorization\Api\IAuthorizationAdministrationService;
use Base3\Authorization\Dto\AuthorizationCapabilities;
use Base3\Authorization\Dto\AuthorizationGrant;
use Base3\Authorization\Dto\AuthorizationSubject;
use Base3\Authorization\Dto\ResourceReference;
use Base3\Authorization\Dto\SubjectReference;
use Base3\Usermanager\Permission;
use InvalidArgumentException;
use RuntimeException;

/** Read-only inspection of direct CAP_ALLOW assignments; Moodle retains complete native role editing. */
final class MoodleAuthorizationAdministrationService implements IAuthorizationAdministrationService {

	private const LIMITATIONS = [
		'Moodle role capabilities remain editable in Moodle administration, not here.',
		'Only direct CAP_ALLOW assignments in the selected context are displayed; CAP_PREVENT, CAP_PROHIBIT and effective inherited rights are not represented.'
	];

	public function describe(string $authority, ?ResourceReference $resource = null): AuthorizationCapabilities {
		$this->assertAuthority($authority);
		if ($resource !== null) $this->context($resource);
		return new AuthorizationCapabilities($authority, is_siteadmin(), false, true, ['role'], self::LIMITATIONS);
	}

	public function getPermissions(string $authority, ?ResourceReference $resource = null): array {
		$this->assertAuthority($authority);
		$this->requireAdministrator();
		if ($resource !== null) $this->context($resource);
		global $DB;
		$permissions = [];
		foreach ($DB->get_records('capabilities', [], 'name ASC', 'name') as $capability) {
			$permission = Permission::for('moodle', (string)$capability->name);
			$permission->label = (string)$capability->name;
			$permissions[] = $permission;
		}
		return $permissions;
	}

	public function getSubjects(ResourceReference $resource): array {
		$this->requireAdministrator();
		$context = $this->context($resource);
		$subjects = [];
		foreach (get_all_roles($context) as $role) {
			$subjects[] = new AuthorizationSubject(
				new SubjectReference(MoodleAuthorizationService::AUTHORITY, 'role', (int)$role->id),
				(string)$role->shortname, false
			);
		}
		return $subjects;
	}

	public function getGrants(ResourceReference $resource): array {
		$this->requireAdministrator();
		$this->context($resource);
		global $DB;
		$grants = [];
		foreach ($DB->get_records('role_capabilities', ['contextid' => (int)$resource->id, 'permission' => CAP_ALLOW]) as $record) {
			$grants[] = new AuthorizationGrant(
				new SubjectReference(MoodleAuthorizationService::AUTHORITY, 'role', (int)$record->roleid),
				Permission::for('moodle', (string)$record->capability), $resource, false, false
			);
		}
		return $grants;
	}

	public function replaceGrants(ResourceReference $resource, SubjectReference $subject, array $permissions): void {
		$this->context($resource);
		throw new RuntimeException('Use Moodle native role-permission administration for capability overrides.');
	}

	private function assertAuthority(string $authority): void {
		if ($authority !== MoodleAuthorizationService::AUTHORITY) throw new InvalidArgumentException('Unknown Moodle authorization authority.');
	}

	private function context(ResourceReference $resource): \context {
		$this->assertAuthority($resource->authority);
		if ($resource->type !== 'context_id' || !ctype_digit((string)$resource->id) || (int)$resource->id <= 0) {
			throw new InvalidArgumentException('Moodle resources must be native context IDs.');
		}
		$context = \context::instance_by_id((int)$resource->id, IGNORE_MISSING);
		if ($context === false) throw new InvalidArgumentException('Moodle context does not exist.');
		return $context;
	}

	private function requireAdministrator(): void {
		if (!is_siteadmin()) throw new RuntimeException('Native Moodle site administration required.');
	}
}
