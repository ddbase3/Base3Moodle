<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3\Usermanager\Role;
use Base3\Usermanager\User;
use Base3\Usermanager\UserCriterionEvaluator;
use Base3Moodle\Service\MoodleGroupConfiguration;

/** Exposes native Moodle identity and system-context role metadata to BASE3. */
final class MoodleUsermanager implements IUsermanager {

	public function __construct(
		private readonly MoodleGroupConfiguration $groupConfiguration,
		private readonly UserCriterionEvaluator $evaluator
	) {}

	/** Resolves the authenticated Moodle user with native system roles. */
	public function getUser(): ?User {
		global $USER;
		return isloggedin() && !isguestuser() ? $this->userObject($USER, true, $this->getRoles()) : null;
	}

	/** Looks up one native numeric user ID, excluding guests and deleted accounts. */
	public function getUserById(int|string $id): ?User {
		$userId = $this->normalizeUserId($id);
		if ($userId === null) return null;

		global $DB;
		$record = $DB->get_record('user', ['id' => $userId, 'deleted' => 0]);
		return $record && !isguestuser($record) ? $this->userObject($record) : null;
	}

	/** Evaluates BASE3 group criteria without modifying native Moodle role assignments. */
	public function getGroups(): array {
		$user = $this->getUser();
		$entries = $this->groupConfiguration->getEntries();
		$groups = [];
		$explicitMatch = false;

		foreach ($entries as $entry) {
			try {
				$matched = $this->evaluator->matches($user, $entry['criteria']);
			} catch (\InvalidArgumentException) {
				// Invalid criteria cannot establish group membership.
				$matched = false;
			}
			if (!$matched) continue;
			$groups[] = $entry['group'];
			if ($entry['editable']) $explicitMatch = true;
		}

		if ($user !== null && !$explicitMatch) {
			$default = $this->groupConfiguration->getDefaultGroup();
			foreach ($entries as $entry) {
				if (!$entry['editable'] || (string)$entry['group']->name !== $default) continue;
				$groups[] = $entry['group'];
				break;
			}
		}

		return $groups;
	}

	/** Returns current native role assignments in the Moodle system context only. */
	public function getRoles(): array {
		global $USER;
		if (!isloggedin() || isguestuser()) return [];
		return $this->rolesForUser((int)$USER->id, is_siteadmin());
	}

	/** Lists effective system-context Moodle capabilities as descriptive metadata. */
	public function getPermissions(): array {
		if (!isloggedin() || isguestuser()) return [];
		$context = \context_system::instance();
		$permissions = [];
		$names = array_keys(get_all_capabilities());
		sort($names);
		foreach ($names as $name) {
			if (has_capability($name, $context)) $permissions[] = Permission::for('moodle', $name);
		}
		if (is_siteadmin()) $permissions[] = Permission::for('system', 'admin');
		return $permissions;
	}

	/** Matches a native role ID or stable shortname; no BASE3 group implies a role. */
	public function hasRole(Role $role): bool {
		if (!isloggedin() || isguestuser()) return false;
		$id = trim((string)($role->id ?? ''));
		$name = trim((string)($role->name ?? ''));
		if ($id === '' && $name === '') return false;
		if ($id === '' && $name === 'admin') return is_siteadmin();

		foreach ($this->getRoles() as $assigned) {
			if ($id !== '' && $id !== (string)$assigned->id) continue;
			if ($name !== '' && $name !== (string)$assigned->name) continue;
			return true;
		}
		return false;
	}

	/** Creates a native manual-authentication account with Moodle validation and events. */
	public function registUser($userid, $password, $data = null): bool {
		if (!isloggedin() || isguestuser() || !has_capability('moodle/user:create', \context_system::instance())) return false;
		if (!is_string($userid) || !is_string($password)) return false;
		$login = trim($userid);
		if ($login === '' || $login !== $userid || clean_param($login, PARAM_USERNAME) !== $login || $password === '') return false;
		$props = is_array($data) ? $data : [];
		$email = (string)($props['email'] ?? '');
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

		global $CFG, $DB;
		if ($DB->record_exists('user', ['username' => $login, 'mnethostid' => (int)$CFG->mnet_localhost_id, 'deleted' => 0])) return false;
		$user = (object)[
			'username' => $login,
			'password' => $password,
			'email' => $email,
			'firstname' => (string)($props['firstname'] ?? $login),
			'lastname' => (string)($props['lastname'] ?? $login),
			'auth' => 'manual',
			'confirmed' => 1,
			'mnethostid' => (int)$CFG->mnet_localhost_id
		];
		$message = null;
		if (!check_password_policy($password, $message, $user)) return false;
		return (int)\core\user::create_user($user, true, true) > 0;
	}

	/** Verifies the old credential and delegates policy, history and update to Moodle. */
	public function changePassword($oldpassword, $newpassword): bool {
		global $CFG, $USER;
		if (!isloggedin() || isguestuser() || ($USER->auth ?? '') !== 'manual'
			|| \core\session\manager::is_loggedinas() || is_mnet_remote_user($USER)) return false;
		if (!has_capability('moodle/user:changeownpassword', \context_system::instance())
			&& !get_user_preferences('auth_forcepasswordchange', false)) return false;
		if (!is_string($oldpassword) || !is_string($newpassword) || $newpassword === '' || $newpassword === $oldpassword) return false;
		if (!validate_internal_user_password($USER, $oldpassword)) return false;
		if (\core\user::is_previously_used_password((int)$USER->id, $newpassword)) return false;
		$message = null;
		if (!check_password_policy($newpassword, $message, $USER)) return false;
		$auth = get_auth_plugin($USER->auth);
		if (!$auth->can_change_password() || !$auth->user_update_password($USER, $newpassword)) return false;

		\core\user::add_password_history((int)$USER->id, $newpassword);
		if (!empty($CFG->passwordchangelogout)) {
			\core\session\manager::destroy_user_sessions((int)$USER->id, session_id());
		}
		login_unlock_account($USER);
		unset_user_preference('auth_forcepasswordchange', $USER);
		unset_user_preference('create_password', $USER);
		return true;
	}

	/** Enumerates visible native identity fields without per-user RBAC lookups. */
	public function getAllUsers(): array {
		if (!has_capability('moodle/user:update', \context_system::instance())) return [];

		global $DB;
		$fullName = $DB->sql_fullname('u.firstname', 'u.lastname');
		$sql = "SELECT u.id, u.username, u.firstname, u.lastname,
		               u.firstnamephonetic, u.lastnamephonetic, u.middlename,
		               u.alternatename, u.email, u.lang, u.deleted
		          FROM {user} u
		         WHERE u.deleted = 0 AND u.username <> :guestusername
		      ORDER BY $fullName ASC, u.id ASC";
		$records = $DB->get_records_sql($sql, ['guestusername' => 'guest']);
		$users = [];
		foreach ($records as $record) {
			$user = $this->userObject($record, false);
			if ($user !== null) $users[] = $user;
		}
		return $users;
	}

	/**
	 * Searches the native user directory through one bounded Moodle database query.
	 *
	 * Queries are literal, include exact numeric IDs and both full-name orders,
	 * and exclude deleted or guest accounts. Directory results contain identity
	 * fields only; callers must also authorize their external API or UI endpoint.
	 *
	 * @param string $query Literal login, name, email or native user ID search text
	 * @param int $limit Page size from 1 through 100
	 * @param int $offset Non-negative result offset
	 * @return User[] Native Moodle identities ordered by full name and ID
	 * @throws \InvalidArgumentException For invalid pagination
	 * @throws \Throwable For a native Moodle database failure
	 */
	public function searchUsers(string $query, int $limit = 20, int $offset = 0): array {
		if ($limit < 1 || $limit > 100 || $offset < 0) {
			throw new \InvalidArgumentException('Invalid user search pagination.');
		}
		$query = trim($query);
		if ($query === '') return [];
		if (!has_capability('moodle/user:update', \context_system::instance())) return [];

		global $DB;
		$fullName = $DB->sql_fullname('u.firstname', 'u.lastname');
		$reverseName = $DB->sql_fullname('u.lastname', 'u.firstname');
		$like = '%' . $DB->sql_like_escape($query) . '%';
		$params = ['guestusername' => 'guest'];
		$conditions = [];
		foreach ([
			'u.username' => 'username',
			'u.firstname' => 'firstname',
			'u.lastname' => 'lastname',
			$fullName => 'fullname',
			$reverseName => 'reversename',
			'u.email' => 'email'
		] as $field => $param) {
			$conditions[] = $DB->sql_like($field, ':' . $param, false);
			$params[$param] = $like;
		}
		if (ctype_digit($query)) {
			$id = filter_var(ltrim($query, '0') ?: '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($id !== false) {
				$conditions[] = 'u.id = :userid';
				$params['userid'] = $id;
			}
		}

		$sql = "SELECT u.id, u.username, u.firstname, u.lastname,
		               u.firstnamephonetic, u.lastnamephonetic, u.middlename,
		               u.alternatename, u.email, u.lang, u.deleted
		          FROM {user} u
		         WHERE u.deleted = 0
		           AND u.username <> :guestusername
		           AND (" . implode(' OR ', $conditions) . ")
		      ORDER BY $fullName ASC, u.id ASC";
		$records = $DB->get_records_sql($sql, $params, $offset, $limit);
		$users = [];
		foreach ($records as $record) {
			$user = $this->userObject($record, false);
			if ($user !== null) $users[] = $user;
		}
		return $users;
	}

	/** Lists canonical BASE3 group definitions rather than native Moodle course groups. */
	public function getAllGroups(): array {
		return $this->groupConfiguration->getGroups();
	}

	/** Lists native Moodle role definitions independently of current membership. */
	public function getAllRoles(): array {
		global $DB;
		return array_values(array_map(
			fn($role): Role => $this->roleObject((array)$role),
			$DB->get_records('role', [], 'sortorder ASC, id ASC')
		));
	}

	/** Returns the native capability catalog, never an effective permission decision. */
	public function getAllPermissions(): array {
		if (!is_siteadmin()) return [];
		$names = array_keys(get_all_capabilities());
		sort($names);
		$permissions = array_map(static fn(string $name): Permission => Permission::for('moodle', $name), $names);
		$permissions[] = Permission::for('system', 'admin');
		return $permissions;
	}

	/** Adds a manually assigned system role only when Moodle marks it assignable. */
	public function assignRoleToUser($userid, Role $role): bool {
		$context = \context_system::instance();
		if (!isloggedin() || isguestuser() || !has_capability('moodle/role:assign', $context)) return false;
		$userId = $this->normalizeUserId($userid);
		$roleId = $this->nativeRoleId($role);
		if ($userId === null || $roleId === null || !array_key_exists($roleId, get_assignable_roles($context))) return false;
		if (!$this->nativeUserExists($userId)) return false;
		return (int)role_assign($roleId, $userId, $context->id) > 0;
	}

	/** Removes only a manually assigned system role, never plugin-owned enrolment roles. */
	public function revokeRoleFromUser($userid, Role $role): bool {
		$context = \context_system::instance();
		if (!isloggedin() || isguestuser() || !has_capability('moodle/role:assign', $context)) return false;
		$userId = $this->normalizeUserId($userid);
		$roleId = $this->nativeRoleId($role);
		global $USER, $DB;
		if ($userId === null || $roleId === null || $userId === (int)$USER->id
			|| !array_key_exists($roleId, get_assignable_roles($context)) || !$this->nativeUserExists($userId)) return false;
		$match = ['roleid' => $roleId, 'userid' => $userId, 'contextid' => $context->id, 'component' => '', 'itemid' => 0];
		if (!$DB->record_exists('role_assignments', $match)) return false;
		role_unassign($roleId, $userId, $context->id);
		return !$DB->record_exists('role_assignments', $match);
	}

	/** Moodle course groups do not confer native role assignments. */
	public function assignRoleToGroup($groupid, Role $role): bool { return false; }

	/** Moodle course groups do not confer native role assignments. */
	public function revokeRoleFromGroup($groupid, Role $role): bool { return false; }

	/** Writes only global Moodle CAP_ALLOW defaults through the native roles API. */
	public function addPermissionToRole(Role $role, Permission $permission): bool {
		if (!is_siteadmin() || $permission->scope !== 'moodle') return false;
		$roleId = $this->nativeRoleId($role);
		$capability = (string)($permission->permission ?? '');
		if ($roleId === null || $capability === '' || get_capability_info($capability) === null) return false;
		return assign_capability($capability, CAP_ALLOW, $roleId, \context_system::instance()->id, true);
	}

	/** Removes only an existing system-context capability override. */
	public function removePermissionFromRole(Role $role, Permission $permission): bool {
		if (!is_siteadmin() || $permission->scope !== 'moodle') return false;
		$roleId = $this->nativeRoleId($role);
		$capability = (string)($permission->permission ?? '');
		if ($roleId === null || $capability === '' || get_capability_info($capability) === null) return false;
		$context = \context_system::instance();
		global $DB;
		$match = ['roleid' => $roleId, 'contextid' => $context->id, 'capability' => $capability];
		if (!$DB->record_exists('role_capabilities', $match)) return false;
		unassign_capability($capability, $roleId, $context->id);
		return !$DB->record_exists('role_capabilities', $match);
	}

	/** Projects one detailed identity or a lightweight native directory record. */
	private function userObject(object $record, bool $detailed = true, ?array $roles = null): ?User {
		$id = (int)($record->id ?? 0);
		if ($id <= 0 || !empty($record->deleted) || (string)($record->username ?? '') === 'guest') return null;
		$administrator = $detailed && is_siteadmin($id);
		$resolvedRoles = $detailed ? ($roles ?? $this->rolesForUser($id, $administrator)) : [];
		$login = (string)($record->username ?? '');
		$name = trim(fullname($record));
		return User::fromArray([
			'id' => $id,
			'userid' => $login,
			'name' => $name !== '' ? $name : $login,
			'email' => (string)($record->email ?? ''),
			'lang' => (string)($record->lang ?? current_language()),
			'role' => $detailed ? ($administrator ? 'admin' : 'member') : '',
			'roles' => $resolvedRoles
		]);
	}

	/** Resolves native system assignments and the non-assignable site administrator marker. */
	private function rolesForUser(int $userId, bool $administrator): array {
		$roles = [];
		foreach (get_user_roles(\context_system::instance(), $userId, true) as $assignment) {
			$roleId = (int)($assignment->roleid ?? 0);
			if ($roleId <= 0) continue;
			$roles[$roleId] = $this->roleObject((array)$assignment);
		}
		if ($administrator) {
			$roles['base3:admin'] = Role::fromArray([
				'id' => 'base3:admin',
				'name' => 'admin',
				'label' => 'Administrator',
				'permissions' => [Permission::for('system', 'admin')]
			]);
		}
		return array_values($roles);
	}

	/** Represents stable native role identifiers, not effective resource permissions. */
	private function roleObject(array $role): Role {
		$id = (int)($role['roleid'] ?? $role['id'] ?? 0);
		$name = (string)($role['shortname'] ?? '');
		return Role::fromArray([
			'id' => $id,
			'name' => $name,
			'label' => (string)($role['name'] ?? $name),
			'permissions' => []
		]);
	}

	/** Resolves only a real native role, rejecting synthetic admin and contradictory IDs. */
	private function nativeRoleId(Role $role): ?int {
		$id = trim((string)($role->id ?? ''));
		$name = trim((string)($role->name ?? ''));
		if ($id === 'base3:admin' || ($id === '' && ($name === '' || $name === 'admin'))) return null;
		global $DB;
		if ($id !== '') {
			$roleId = $this->normalizeUserId($id);
			if ($roleId === null) return null;
			$record = $DB->get_record('role', ['id' => $roleId], 'id,shortname');
		} else {
			$record = $DB->get_record('role', ['shortname' => $name], 'id,shortname');
		}
		if (!$record || ($name !== '' && $name !== (string)$record->shortname)) return null;
		return (int)$record->id > 0 ? (int)$record->id : null;
	}

	/** Rejects absent, deleted or guest user accounts before assigning native roles. */
	private function nativeUserExists(int $userId): bool {
		global $DB;
		$record = $DB->get_record('user', ['id' => $userId, 'deleted' => 0], 'id,username');
		return $record !== false && $record !== null && (string)$record->username !== 'guest';
	}

	/** Normalizes positive Moodle IDs without accepting partial or overflowing numbers. */
	private function normalizeUserId(int|string $id): ?int {
		if (is_int($id)) return $id > 0 ? $id : null;
		if ($id === '' || !ctype_digit($id)) return null;
		$normalized = ltrim($id, '0');
		if ($normalized === '') return null;
		$value = filter_var($normalized, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		return $value === false ? null : $value;
	}
}
