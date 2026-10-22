<?php declare(strict_types=1);

/** Run with PHP CLI and the installed BASE3 Framework, without bootstrapping Moodle. */

namespace core {
	final class user {
		public static function create_user(object $user, bool $password = true, bool $event = true): int {
			$GLOBALS['createdUserEvent'] = $event;
			$id = ++$GLOBALS['lastUserId'];
			$user->id = $id;
			$user->deleted = 0;
			$user->lang = 'en';
			$GLOBALS['DB']->users[$id] = $user;
			return $id;
		}
		public static function add_password_history(int $userId, string $password): void {
			$GLOBALS['passwordHistory'][] = [$userId, $password];
		}
		public static function is_previously_used_password(int $userId, string $password): bool {
			return in_array($password, $GLOBALS['reusedPasswords'], true);
		}
	}
}

namespace core\session {
	final class manager {
		public static function destroy_user_sessions(int $userId, string $exclude): void {
			$GLOBALS['invalidatedSessions'][] = [$userId, $exclude];
		}
		public static function is_loggedinas(): bool { return $GLOBALS['impersonated']; }
	}
}

namespace {

$modules = dirname(__DIR__, 3);
spl_autoload_register(static function(string $class) use ($modules): void {
	foreach (['Base3\\' => 'Base3Framework', 'Base3Moodle\\' => 'Base3Moodle'] as $prefix => $module) {
		if (!str_starts_with($class, $prefix)) continue;
		$path = $modules . '/' . $module . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($path)) require_once $path;
		return;
	}
});

const PARAM_USERNAME = 1;
const CAP_ALLOW = 1;

class context_system {
	public int $id = 1;
	public static function instance(): self { return new self(); }
}

class MoodleTestDatabase {
	public array $users = [];
	public array $roles = [];
	public array $assignments = [];
	public array $grants = [];
	public array $search = [];
	public array $lastSearchParams = [];
	public string $lastSearchSql = '';
	public int $roleReads = 0;

	public function get_record(string $table, array $conditions, string $fields = '*'): object|false {
		$rows = match ($table) { 'user' => $this->users, 'role' => $this->roles, default => [] };
		foreach ($rows as $row) {
			$valid = true;
			foreach ($conditions as $key => $value) {
				if (!property_exists($row, $key) || (string)$row->$key !== (string)$value) $valid = false;
			}
			if ($valid) return $row;
		}
		return false;
	}

	public function get_records(string $table, array $conditions = [], string $order = '', string $fields = '*'): array {
		if ($table !== 'role') throw new \RuntimeException('Unexpected get_records table.');
		return $this->roles;
	}

	public function record_exists(string $table, array $conditions): bool {
		$rows = match ($table) {
			'user' => $this->users,
			'role_assignments' => $this->assignments,
			'role_capabilities' => $this->grants,
			default => []
		};
		foreach ($rows as $row) {
			$valid = true;
			foreach ($conditions as $key => $value) if ((string)($row->$key ?? '') !== (string)$value) $valid = false;
			if ($valid) return true;
		}
		return false;
	}

	public function sql_fullname(string $first, string $last): string {
		return "CONCAT($first, ' ', $last)";
	}

	public function sql_like_escape(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}

	public function sql_like(string $field, string $param, bool $caseSensitive): string {
		return "LOWER($field) LIKE LOWER($param)";
	}

	public function get_records_sql(string $sql, array $params = [], int $offset = 0, int $limit = 0): array {
		$this->lastSearchSql = $sql;
		$this->lastSearchParams = $params;
		$users = array_values(array_filter($this->users, static fn(object $u): bool => !$u->deleted && $u->username !== 'guest'));
		if (isset($params['username'])) {
			$query = strtolower($GLOBALS['queryForTest']);
			$users = array_values(array_filter($users, static function(object $user) use ($query): bool {
				$text = strtolower(implode(' ', [
					$user->username, $user->firstname, $user->lastname, $user->email,
					$user->firstname . ' ' . $user->lastname, $user->lastname . ' ' . $user->firstname
				]));
				return str_contains($text, $query) || (isset($GLOBALS['queryId']) && (int)$user->id === $GLOBALS['queryId']);
			}));
		}
		usort($users, static fn(object $a, object $b): int => strcmp($a->firstname . ' ' . $a->lastname, $b->firstname . ' ' . $b->lastname) ?: ($a->id <=> $b->id));
		return array_slice($users, $offset, $limit === 0 ? null : $limit);
	}
}

function isloggedin(): bool { return $GLOBALS['loggedIn']; }
function is_mnet_remote_user(object $user): bool { return $GLOBALS['remoteAccount']; }
function get_user_preferences(string $key, $default = false): mixed { return $GLOBALS['preferences'][$key] ?? $default; }
function unset_user_preference(string $key, object $user): void { unset($GLOBALS['preferences'][$key]); }
function login_unlock_account(object $user): void { $GLOBALS['unlockedAccounts'][] = $user->id; }
function isguestuser(?object $user = null): bool {
	if ($user !== null) return $user->username === 'guest';
	return !$GLOBALS['loggedIn'] || $GLOBALS['USER']->username === 'guest';
}
function is_siteadmin(int $userId = 0): bool {
	$id = $userId > 0 ? $userId : (int)$GLOBALS['USER']->id;
	$GLOBALS['siteAdminLookups'][] = $id;
	return in_array($id, $GLOBALS['administrators'], true);
}
function fullname(object $user): string { return (string)$user->firstname . ' ' . (string)$user->lastname; }
function current_language(): string { return 'en'; }
function get_user_roles(context_system $context, int $userId = 0, bool $parents = true): array {
	$GLOBALS['roleQueries'][] = [$context->id, $userId, $parents];
	$roles = [];
	foreach ($GLOBALS['DB']->assignments as $assignment) {
		if ($assignment->userid !== $userId || $assignment->contextid !== $context->id) continue;
		$role = $GLOBALS['DB']->roles[$assignment->roleid];
		$roles[] = (object)['roleid' => $role->id, 'id' => $assignment->id, 'name' => $role->name, 'shortname' => $role->shortname];
	}
	return $roles;
}
function get_all_capabilities(): array { return $GLOBALS['capabilityCatalog']; }
function has_capability(string $permission, context_system $context): bool {
	return $GLOBALS['loggedIn'] && in_array($permission, $GLOBALS['allowedCaps'], true);
}
function get_assignable_roles(context_system $context): array { return $GLOBALS['assignableRoles']; }
function role_assign(int $roleId, int $userId, int $contextId, string $component = '', int $itemId = 0): int {
	$GLOBALS['DB']->assignments[] = (object)[
		'id' => ++$GLOBALS['lastAssignmentId'], 'roleid' => $roleId, 'userid' => $userId,
		'contextid' => $contextId, 'component' => $component, 'itemid' => $itemId
	];
	return $GLOBALS['lastAssignmentId'];
}
function role_unassign(int $roleId, int $userId, int $contextId, string $component = '', int $itemId = 0): void {
	$GLOBALS['DB']->assignments = array_values(array_filter(
		$GLOBALS['DB']->assignments,
		static fn(object $row): bool => $row->roleid !== $roleId || $row->userid !== $userId
			|| $row->contextid !== $contextId || $row->component !== $component || $row->itemid !== $itemId
	));
}
function get_capability_info(string $name, bool $debug = true): ?object {
	return isset($GLOBALS['capabilityCatalog'][$name]) ? (object)['name' => $name] : null;
}
function assign_capability(string $name, int $permission, int $roleId, int $contextId, bool $overwrite = false): bool {
	$GLOBALS['DB']->grants[] = (object)['roleid' => $roleId, 'contextid' => $contextId,
		'capability' => $name, 'permission' => $permission];
	return true;
}
function unassign_capability(string $name, int $roleId, int $contextId): bool {
	$GLOBALS['DB']->grants = array_values(array_filter($GLOBALS['DB']->grants,
		static fn(object $grant): bool => $grant->roleid !== $roleId || $grant->contextid !== $contextId || $grant->capability !== $name));
	return true;
}
function clean_param(string $value, int $type): string { return preg_replace('/[^a-zA-Z0-9_.-]/', '', $value); }
function check_password_policy(string $password, ?string &$message, ?object $user = null): bool { return strlen($password) >= 8; }
function validate_internal_user_password(object $user, string $oldPassword): bool { return $user->password === $oldPassword; }
function get_auth_plugin(string $name): object {
	return new class {
		public function can_change_password(): bool { return true; }
		public function user_update_password(object $user, string $password): bool {
			$user->password = $password;
			return true;
		}
	};
}
function assertMoodle(bool $okay, string $message): void {
	if (!$okay) throw new \RuntimeException('FAIL: ' . $message);
	echo 'PASS: ' . $message . PHP_EOL;
}

$DB = new MoodleTestDatabase();
$DB->roles = [
	7 => (object)['id' => 7, 'shortname' => 'manager', 'name' => 'Manager'],
	8 => (object)['id' => 8, 'shortname' => 'teacher', 'name' => 'Teacher']
];
$DB->users = [
	1 => (object)['id' => 1, 'username' => 'administrator', 'firstname' => 'Ada', 'lastname' => 'Admin',
		'email' => 'admin@example.test', 'lang' => 'en', 'deleted' => 0, 'auth' => 'manual', 'password' => 'old-secret'],
	2 => (object)['id' => 2, 'username' => 'teacher', 'firstname' => 'Marie', 'lastname' => 'Teacher',
		'email' => 'teacher@example.test', 'lang' => 'de', 'deleted' => 0, 'auth' => 'manual', 'password' => 'secret-2'],
	3 => (object)['id' => 3, 'username' => 'guest', 'firstname' => 'Guest', 'lastname' => 'Account',
		'email' => 'guest@example.test', 'lang' => 'en', 'deleted' => 0, 'auth' => 'manual', 'password' => 'secret-3'],
	4 => (object)['id' => 4, 'username' => 'deleted', 'firstname' => 'Deleted', 'lastname' => 'Account',
		'email' => 'deleted@example.test', 'lang' => 'en', 'deleted' => 1, 'auth' => 'manual', 'password' => 'secret-4']
];
$DB->assignments = [
	(object)['id' => 10, 'roleid' => 7, 'userid' => 1, 'contextid' => 1, 'component' => '', 'itemid' => 0],
	(object)['id' => 11, 'roleid' => 8, 'userid' => 2, 'contextid' => 1, 'component' => 'enrol_manual', 'itemid' => 4]
];
$USER = $DB->users[1];
$CFG = (object)['mnet_localhost_id' => 1, 'passwordchangelogout' => true];
$GLOBALS['loggedIn'] = true;
$GLOBALS['administrators'] = [1];
$GLOBALS['allowedCaps'] = ['moodle/user:create', 'moodle/user:update', 'moodle/role:assign', 'moodle/site:config', 'moodle/user:changeownpassword'];
$GLOBALS['assignableRoles'] = [7 => 'Manager', 8 => 'Teacher'];
$GLOBALS['capabilityCatalog'] = [
	'moodle/user:create' => ['name' => 'moodle/user:create'],
	'moodle/user:update' => ['name' => 'moodle/user:update'],
	'moodle/role:assign' => ['name' => 'moodle/role:assign'],
	'moodle/site:config' => ['name' => 'moodle/site:config'],
	'moodle/course:view' => ['name' => 'moodle/course:view']
];
$GLOBALS['siteAdminLookups'] = [];
$GLOBALS['roleQueries'] = [];
$GLOBALS['passwordHistory'] = [];
$GLOBALS['reusedPasswords'] = ['previous-secret'];
$GLOBALS['impersonated'] = false;
$GLOBALS['remoteAccount'] = false;
$GLOBALS['preferences'] = [];
$GLOBALS['unlockedAccounts'] = [];
$GLOBALS['invalidatedSessions'] = [];
$GLOBALS['lastAssignmentId'] = 11;
$GLOBALS['lastUserId'] = 4;
$GLOBALS['queryForTest'] = '';
$GLOBALS['queryId'] = null;

$manager = (new \ReflectionClass(\Base3Moodle\Adapter\MoodleUsermanager::class))->newInstanceWithoutConstructor();
$role = \Base3\Usermanager\Role::named('manager');
$systemAdmin = \Base3\Usermanager\Role::named('admin');
assertMoodle($manager instanceof \Base3\Usermanager\Api\IUsermanager, 'implements current IUsermanager');
assertMoodle($manager->getUser()->role === 'admin' && count($manager->getUser()->roles) === 2, 'detailed current identity includes native and administrator roles');
assertMoodle($manager->getUserById('0002')->role === 'member' && count($manager->getUserById(2)->roles) === 1, 'target user includes their own native system role');
foreach (['', '0', '2x', '-2', '2.5', '9223372036854775808', 'teacher'] as $invalid) {
	assertMoodle($manager->getUserById($invalid) === null, 'reject malformed user ID: ' . $invalid);
}
assertMoodle($manager->getUserById(3) === null && $manager->getUserById(4) === null, 'reject guest and deleted accounts');
assertMoodle($manager->hasRole($role) && $manager->hasRole(\Base3\Usermanager\Role::fromArray(['id' => 7])), 'check native role name and ID');
assertMoodle(!$manager->hasRole(\Base3\Usermanager\Role::fromArray(['id' => 8, 'name' => 'manager'])), 'contradictory role metadata denies');
assertMoodle($manager->hasRole($systemAdmin) && !$manager->hasRole(\Base3\Usermanager\Role::named('administration')), 'administrator marker is not a BASE3 group role');
assertMoodle(count($manager->getAllRoles()) === 2 && count($manager->getAllRoles()[0]->permissions) === 0, 'catalog exposes native roles without fictitious effective permissions');
$permissionKeys = array_map(static fn($p): string => $p->scope . '/' . $p->permission, $manager->getPermissions());
assertMoodle(in_array('moodle/moodle/user:update', $permissionKeys, true) && in_array('system/admin', $permissionKeys, true), 'current system-context capability metadata is complete');
assertMoodle(!in_array('moodle/moodle/course:view', $permissionKeys, true), 'ungranted catalog capability does not appear as current metadata');
assertMoodle(count($manager->getAllPermissions()) === 6, 'native catalog includes denied definitions and administrator marker');

$GLOBALS['roleQueries'] = [];
$GLOBALS['siteAdminLookups'] = [];
$directory = $manager->getAllUsers();
assertMoodle(count($directory) === 2 && $directory[0]->id === 1 && $directory[1]->id === 2, 'directory has active non-guest users in stable order');
assertMoodle($directory[0]->roles === [] && $directory[0]->role === '' && $directory[1]->lang === 'de'
	&& $GLOBALS['roleQueries'] === [] && $GLOBALS['siteAdminLookups'] === [], 'bulk identity does not resolve roles or admin status');
foreach ([[0, 0], [101, 0], [20, -1]] as [$limit, $offset]) {
	try { $manager->searchUsers('teacher', $limit, $offset); throw new \RuntimeException('Invalid paging accepted.'); }
	catch (\InvalidArgumentException) { }
}
assertMoodle($manager->searchUsers('   ') === [], 'empty search short-circuits');
$GLOBALS['queryForTest'] = 'teacher';
assertMoodle(count($manager->searchUsers('teacher')) === 1, 'search filters user records without RBAC expansion');
$GLOBALS['queryForTest'] = '%_';
$manager->searchUsers(' %_ ');
assertMoodle($DB->lastSearchParams['username'] === '%\\%\\_%' && $DB->lastSearchSql !== '', 'literal LIKE special characters escaped');
$GLOBALS['queryId'] = 2;
$GLOBALS['queryForTest'] = '2';
$manager->searchUsers('0002');
assertMoodle($DB->lastSearchParams['userid'] === 2, 'numeric search uses an exact native user ID');

assertMoodle($manager->assignRoleToUser(2, $role), 'assign allowed native system role');
assertMoodle($DB->record_exists('role_assignments', ['roleid' => 7, 'userid' => 2, 'contextid' => 1, 'component' => '']), 'new assignment is manual');
assertMoodle($manager->revokeRoleFromUser(2, $role), 'revoke manual system role');
assertMoodle(!$manager->revokeRoleFromUser(2, \Base3\Usermanager\Role::named('teacher')), 'do not remove plugin-owned enrolment assignment');
assertMoodle($manager->revokeRoleFromUser(1, $role) === false, 'do not remove own system role');
assertMoodle(!$manager->assignRoleToUser('2broken', $role) && !$manager->assignRoleToUser(3, $role), 'invalid and guest users denied');
assertMoodle(!$manager->assignRoleToUser(2, $systemAdmin), 'administrator marker cannot be granted as native role');
$GLOBALS['assignableRoles'] = [8 => 'Teacher'];
assertMoodle(!$manager->assignRoleToUser(2, $role), 'native assignability is mandatory');
$GLOBALS['assignableRoles'] = [7 => 'Manager', 8 => 'Teacher'];
assertMoodle(!$manager->assignRoleToGroup(100, $role) && !$manager->revokeRoleFromGroup(100, $role), 'no invented group-role mapping');
$permission = \Base3\Usermanager\Permission::for('moodle', 'moodle/course:view');
assertMoodle($manager->addPermissionToRole($role, $permission), 'native capability grant uses system context');
assertMoodle($manager->removePermissionFromRole($role, $permission), 'native capability removal uses system context');
assertMoodle(!$manager->removePermissionFromRole($role, $permission), 'absent native capability removal rejected');
assertMoodle(!$manager->addPermissionToRole($role, \Base3\Usermanager\Permission::for('system', 'admin'))
	&& !$manager->addPermissionToRole($role, \Base3\Usermanager\Permission::for('moodle', 'unknown')), 'reject unsupported permission scopes and capabilities');

assertMoodle(!$manager->registUser('new', 'short', ['email' => 'new@example.test']), 'registration enforces Moodle password policy');
assertMoodle(!$manager->registUser('bad login', 'good-secret', ['email' => 'new@example.test']), 'registration rejects invalid Moodle usernames');
assertMoodle($manager->registUser('new-account', 'good-secret', ['email' => 'new@example.test']), 'native user creation succeeds');
assertMoodle(!$manager->registUser('new-account', 'good-secret', ['email' => 'new@example.test']), 'duplicate native account cannot be registered');
assertMoodle($GLOBALS['createdUserEvent'] === true, 'native account creation emits host event');
$GLOBALS['impersonated'] = true;
assertMoodle(!$manager->changePassword('old-secret', 'changed-secret'), 'impersonated sessions cannot change credentials');
$GLOBALS['impersonated'] = false;
$GLOBALS['remoteAccount'] = true;
assertMoodle(!$manager->changePassword('old-secret', 'changed-secret'), 'remote accounts cannot change credentials');
$GLOBALS['remoteAccount'] = false;
assertMoodle(!$manager->changePassword('old-secret', 'old-secret'), 'new and old credentials must differ');
assertMoodle(!$manager->changePassword('old-secret', 'previous-secret'), 'previously used credentials denied');
assertMoodle(!$manager->changePassword('incorrect', 'changed-secret'), 'old credential must match');
assertMoodle(!$manager->changePassword('old-secret', 'weak'), 'new password must satisfy policy');
assertMoodle($manager->changePassword('old-secret', 'changed-secret'), 'native auth plugin changes current password');
assertMoodle(count($GLOBALS['passwordHistory']) === 1 && count($GLOBALS['invalidatedSessions']) === 1 && $GLOBALS['unlockedAccounts'] === [1], 'password history, account unlock and configured session invalidation');

$GLOBALS['administrators'] = [];
assertMoodle($manager->getAllPermissions() === [] && !$manager->addPermissionToRole($role, $permission), 'non-administrator cannot read privileged catalog or change role capabilities');
$GLOBALS['allowedCaps'] = [];
assertMoodle(!$manager->changePassword('changed-secret', 'third-secret'), 'password update requires native own-password capability');
$GLOBALS['preferences']['auth_forcepasswordchange'] = true;
assertMoodle($manager->changePassword('changed-secret', 'third-secret'), 'forced password change follows Moodle password preference');
assertMoodle(!isset($GLOBALS['preferences']['auth_forcepasswordchange']), 'successful change clears forced password preference');
assertMoodle($manager->getAllUsers() === [] && !$manager->assignRoleToUser(2, $role), 'missing native capability denies directory and role changes');
assertMoodle(!$manager->registUser('another', 'good-secret', ['email' => 'a@example.test']), 'native user-create capability required');
$GLOBALS['loggedIn'] = false;
assertMoodle($manager->getUser() === null && $manager->getRoles() === [] && $manager->getPermissions() === [], 'anonymous user has no identity, roles or permissions');

echo "MoodleUsermanagerSmokeTest OK\n";
}
