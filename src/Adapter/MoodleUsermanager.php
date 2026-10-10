<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Group;
use Base3\Usermanager\Permission;
use Base3\Usermanager\Role;
use Base3\Usermanager\User;

/** Adapts Moodle identities, site roles, current-course groups and capabilities to BASE3 RBAC. */
final class MoodleUsermanager implements IUsermanager {

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
		global $USER, $COURSE;
		if (!isloggedin() || isguestuser()) return [$this->groupObject('anonymous')];
		$groups = [$this->groupObject('authenticated')];
		if (is_siteadmin()) $groups[] = $this->groupObject('administration');
		if (isset($COURSE->id) && (int) $COURSE->id > SITEID) {
			foreach (groups_get_user_groups((int) $COURSE->id, (int) $USER->id)[0] ?? [] as $id) {
				$groups[] = $this->groupObject('course:' . $COURSE->id . ':group:' . $id);
			}
		}
		return $groups;
	}

	public function getRoles() {
		global $USER;
		if (!isloggedin() || isguestuser()) return [];
		$roles = get_user_roles(\context_system::instance(), (int) $USER->id, true);
		$result = array_map(fn($role) => $this->roleObject((array) $role), $roles);
		if (is_siteadmin()) $result[] = Role::fromArray(['id' => 'admin', 'name' => 'admin', 'label' => 'Administrator', 'permissions' => []]);
		return $result;
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

	public function can(Permission $permission): bool {
		$scope = strtolower((string) $permission->scope);
		$capability = (string) $permission->permission;
		if (($scope === 'system' || $scope === 'entry') && $capability === 'admin') return is_siteadmin();
		if ($scope !== 'moodle' || $capability === '') return false;
		$context = $permission->target !== null
			? \context::instance_by_id((int) $permission->target, IGNORE_MISSING)
			: \context_system::instance();
		return $context !== false && has_capability($capability, $context);
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
		return array_map([$this, 'groupObject'], ['anonymous', 'authenticated', 'administration']);
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
		if (!is_siteadmin() || $permission->scope !== 'moodle' || $permission->target !== null) return false;
		$id = $this->getRoleId($role);
		if ($id === 0 || get_capability_info((string) $permission->permission) === false) return false;
		assign_capability((string) $permission->permission, CAP_ALLOW, $id, \context_system::instance()->id, true);
		return true;
	}

	public function removePermissionFromRole(Role $role, Permission $permission): bool {
		if (!is_siteadmin() || $permission->scope !== 'moodle' || $permission->target !== null) return false;
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

	private function groupObject(string $name): Group {
		return Group::fromArray(['id' => $name, 'name' => $name, 'info' => 'Moodle derived group', 'roles' => []]);
	}

	private function roleObject(array $role): Role {
		$name = (string) ($role['shortname'] ?? $role['name'] ?? '');
		return Role::fromArray(['id' => $role['id'] ?? $name, 'name' => $name, 'label' => (string) ($role['name'] ?? $name), 'permissions' => []]);
	}
}
