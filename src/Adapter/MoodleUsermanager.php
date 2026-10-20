<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Group;
use Base3\Usermanager\Permission;
use Base3\Usermanager\Role;
use Base3\Usermanager\User;
use Base3\Usermanager\UserCriterionEvaluator;
use Base3Moodle\Service\MoodleGroupConfiguration;

/** Adapts Moodle identities, native roles and permissions to the single BASE3 authorization model. */
final class MoodleUsermanager implements IUsermanager {

	private ?array $groups = null;
	private ?array $roles = null;

	public function __construct(
		private readonly MoodleGroupConfiguration $groupConfiguration,
		private readonly UserCriterionEvaluator $evaluator
	) {}

	public function getUser() {
		global $USER;
		return isloggedin() && !isguestuser() ? $this->userObject($USER) : null;
	}

	public function getUserById(int|string $id): ?User {
		global $DB;
		$record = $DB->get_record('user', ['id' => (int) $id, 'deleted' => 0]);
		return $record ? $this->userObject($record) : null;
	}

	public function getGroups() {
		if ($this->groups !== null) return $this->groups;
		$user = $this->getUser();
		$groups = [];
		$entries = $this->groupConfiguration->getEntries();
		foreach ($entries as $entry) {
			if ($this->evaluator->matches($user, $entry['criteria'])) $groups[$entry['group']->name] = $entry['group'];
		}
		// The generic authenticated group does not count as an explicit audience assignment.
		// A fallback never grants development or administration membership.
		$matched = array_diff(array_keys($groups), ['authenticated', 'anonymous']);
		if ($user !== null && $matched === []) {
			$default = $this->groupConfiguration->getDefaultGroup();
			foreach ($entries as $entry) {
				if ($default !== '' && $entry['group']->name === $default) $groups[$default] = $entry['group'];
			}
		}
		return $this->groups = array_values($groups);
	}

	public function getRoles() {
		if ($this->roles !== null) return $this->roles;
		global $USER;
		if (!isloggedin() || isguestuser()) return $this->roles = [];
		// BASE3 global role selectors use system context. Course roles can be selected
		// explicitly with MoodleRoleCriterion::context_id and native parent resolution.
		$assignments = get_user_roles(\context_system::instance(), (int)$USER->id, true);
		$result = [];
		foreach ($assignments as $assignment) {
			$role = $this->roleObject((array)$assignment);
			$result[(string)$role->id] = $role;
		}
		if (is_siteadmin()) $result['admin'] = Role::fromArray([
			'id' => 'admin', 'name' => 'admin', 'label' => 'Administrator', 'permissions' => []
		]);
		return $this->roles = array_values($result);
	}

	public function getPermissions() {
		if (!isloggedin() || isguestuser()) return [];
		$caps = [];
		foreach (['moodle/site:config', 'moodle/course:view', 'moodle/user:update', 'moodle/role:assign'] as $cap) {
			if (has_capability($cap, \context_system::instance())) $caps[] = Permission::for('moodle', $cap);
		}
		if (is_siteadmin()) $caps[] = Permission::for('system', 'admin');
		return $caps;
	}

	public function hasRole(Role $role): bool {
		$name = (string) ($role->name ?? '');
		if (in_array($name, ['admin', 'administration'], true)) return is_siteadmin();
		foreach ($this->getRoles() as $candidate) {
			if ((string) $candidate->name === $name) return true;
		}
		return false;
	}

	public function registUser($userid, $password, $data = null) {
		if (!has_capability('moodle/user:create', \context_system::instance())) return false;
		if (!is_string($userid) || trim($userid) === '') return false;
		$props = is_array($data) ? $data : [];
		if (!isset($props['email']) || !filter_var($props['email'], FILTER_VALIDATE_EMAIL)) return false;
		$user = (object) [
			'username' => $userid,
			'password' => (string) $password,
			'email' => (string) $props['email'],
			'firstname' => (string) ($props['firstname'] ?? $userid),
			'lastname' => (string) ($props['lastname'] ?? $userid),
			'auth' => 'manual',
			'confirmed' => 1,
			'mnethostid' => (int) $GLOBALS['CFG']->mnet_localhost_id
		];
		require_once($GLOBALS['CFG']->dirroot . '/user/lib.php');
		return (int) user_create_user($user, true, false) > 0;
	}

	public function changePassword($oldpassword, $newpassword) {
		global $USER;
		if (!isloggedin() || isguestuser() || $USER->auth !== 'manual') return false;
		if (!validate_internal_user_password($USER, (string) $oldpassword)) return false;
		update_internal_user_password($USER, (string) $newpassword);
		return true;
	}

	public function getAllUsers() {
		global $DB;
		if (!has_capability('moodle/user:update', \context_system::instance())) return [];
		$records = $DB->get_records('user', ['deleted' => 0]);
		return array_values(array_filter(array_map([$this, 'userObject'], $records)));
	}

	public function getAllGroups() {
		return $this->groupConfiguration->getGroups();
	}

	public function getAllRoles() {
		global $DB;
		return array_values(array_map(fn($role) => $this->roleObject((array) $role), $DB->get_records('role')));
	}

	public function getAllPermissions() {
		global $DB;
		if (!is_siteadmin()) return [];
		return array_map(static fn($row) => Permission::for('moodle', (string) $row->name), array_values($DB->get_records('capabilities')));
	}

	public function assignRoleToUser($userid, Role $role): bool {
		if (!has_capability('moodle/role:assign', \context_system::instance())) return false;
		$id = $this->getRoleId($role);
		if ($id === 0) return false;
		role_assign($id, (int) $userid, \context_system::instance()->id);
		return true;
	}

	public function revokeRoleFromUser($userid, Role $role): bool {
		if (!has_capability('moodle/role:assign', \context_system::instance())) return false;
		$id = $this->getRoleId($role);
		if ($id === 0) return false;
		role_unassign($id, (int) $userid, \context_system::instance()->id);
		return true;
	}

	// Moodle course groups are not equivalent to BASE3 global role assignment.
	public function assignRoleToGroup($groupid, Role $role): bool { return false; }
	public function revokeRoleFromGroup($groupid, Role $role): bool { return false; }

	public function addPermissionToRole(Role $role, Permission $permission): bool {
		if (!is_siteadmin() || $permission->scope !== 'moodle') return false;
		$id = $this->getRoleId($role);
		if ($id === 0 || get_capability_info((string) $permission->permission) === false) return false;
		assign_capability((string) $permission->permission, CAP_ALLOW, $id, \context_system::instance()->id, true);
		return true;
	}

	public function removePermissionFromRole(Role $role, Permission $permission): bool {
		if (!is_siteadmin() || $permission->scope !== 'moodle') return false;
		$id = $this->getRoleId($role);
		if ($id === 0) return false;
		unassign_capability((string) $permission->permission, $id, \context_system::instance()->id);
		return true;
	}

	private function getRoleId(Role $role): int {
		global $DB;
		return (int) ($DB->get_field('role', 'id', ['shortname' => (string) $role->name]) ?: 0);
	}

	private function userObject(object $record): ?User {
		if (!isset($record->id) || !empty($record->deleted)) return null;
		return User::fromArray([
			'id' => (int) $record->id,
			'userid' => (string) ($record->username ?? $record->id),
			'name' => fullname($record),
			'email' => (string) ($record->email ?? ''),
			'lang' => (string) ($record->lang ?? current_language()),
			'role' => is_siteadmin((int) $record->id) ? 'admin' : 'member',
			'roles' => []
		]);
	}

	private function roleObject(array $role): Role {
		$name = (string) ($role['shortname'] ?? $role['name'] ?? '');
		return Role::fromArray(['id' => $role['roleid'] ?? $role['id'] ?? $name, 'name' => $name, 'label' => (string) ($role['name'] ?? $name), 'permissions' => []]);
	}
}
