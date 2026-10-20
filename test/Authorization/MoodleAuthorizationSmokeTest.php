<?php declare(strict_types=1);

/** Run without Moodle: php modules/Base3Moodle/test/Authorization/MoodleAuthorizationSmokeTest.php */

$modules = dirname(__DIR__, 3);
spl_autoload_register(static function(string $class) use ($modules): void {
	foreach (['Base3\\' => 'Base3Framework', 'Base3Moodle\\' => 'Base3Moodle',
		'Base3MoodleLab\\' => 'Base3MoodleLab', 'PulseCore\\' => 'PulseCore',
		'UiFoundation\\' => 'UiFoundation', 'ClientStack\\' => 'ClientStack'] as $prefix => $module) {
		if (!str_starts_with($class, $prefix)) continue;
		$file = $modules . '/' . $module . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) require_once $file;
		return;
	}
});

const SITEID = 1;
const IGNORE_MISSING = false;
class context { public function __construct(public int $id) {} public static function instance_by_id(int $id, $strict = false) { return in_array($id, [1, 77], true) ? new self($id) : false; } }
class context_system extends context { public static function instance(): self { return new self(1); } }
function isloggedin(): bool { return $GLOBALS['loggedin']; }
function isguestuser(): bool { return !$GLOBALS['loggedin']; }
function is_siteadmin(int $id = 0): bool { return $GLOBALS['siteAdmin'] && ($id === 0 || $id === 42); }
function fullname(object $user): string { return (string)$user->firstname . ' ' . (string)$user->lastname; }
function current_language(): string { return 'en'; }
function get_user_roles(context $context, int $userId = 0, bool $includeParents = true): array {
	$GLOBALS['roleCalls'][] = [$context->id, $userId, $includeParents];
	return $userId === 42 ? [(object)['roleid' => 7, 'id' => 1001, 'name' => 'Manager', 'shortname' => 'manager']] : [];
}
function has_capability(string $capability, context $context): bool { return $capability === 'moodle/course:view' && $context->id === 77; }
function groups_get_user_groups(int $courseId, int $userId): array { return [[]]; }
function groups_get_group(int $groupId, string $fields = '*') { return $groupId === 20 ? (object)['id' => 20, 'courseid' => 5] : false; }
function groups_is_member(int $groupId, int $userId): bool { return $groupId === 20 && $userId === 42; }

class TestMap implements \Base3\Api\IClassMap {
	public array $registry = [];
	public array $names = [];
	public function instantiate(string $class) { return null; }
	public function instantiateWith(string $class, array $arguments = []) { return null; }
	public function generate($regenerate = false): void {}
	public function getApps() { return []; }
	public function &getInstances(array $criteria = []) { $a=[]; return $a; }
	public function &getInstancesByInterface($interface) { $a = $this->registry[$interface] ?? []; return $a; }
	public function &getInstancesByAppInterface($app, $interface, $retry = false) { $a=[]; return $a; }
	public function &getInstanceByAppName($app, $name, $retry = false) { $a=null;return $a; }
	public function getClassByInterfaceName(string $interface, string $name): ?string { return null; }
	public function &getInstanceByInterfaceName($interface, $name, $retry = false) {
		if (isset($this->names[$name])) return $this->names[$name];
		$none = null;
		return $none;
	}
	public function &getInstanceByAppInterfaceName($app, $interface, $name, $retry = false) { $a=null;return $a; }
	public function getPlugins() { return []; }
}
class TestSettingsStore implements \Base3\Settings\Api\ISettingsStore {
	public array $data = [];
	public int $saves = 0;
	public function get(string $group, string $name, array $default = []): array { return $this->data[$group][$name] ?? $default; }
	public function set(string $group, string $name, array $settings): void { $this->data[$group][$name] = $settings; }
	public function has(string $group, string $name): bool { return isset($this->data[$group][$name]); }
	public function remove(string $group, string $name): void { unset($this->data[$group][$name]); }
	public function getGroup(string $group): array { return $this->data[$group] ?? []; }
	public function save(): void { $this->saves++; }
	public function reload(): void {}
}
function check(bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException('FAIL: ' . $message);
	echo 'PASS: ', $message, PHP_EOL;
}

$USER = (object)['id' => 42, 'username' => 'maintainer', 'firstname' => 'Moodle', 'lastname' => 'Tester',
	'deleted' => 0, 'email' => 'maintainer@example.org', 'lang' => 'en'];
$COURSE = (object)['id' => SITEID];
$GLOBALS['loggedin'] = true;
$GLOBALS['siteAdmin'] = true;
$GLOBALS['roleCalls'] = [];
$map = new TestMap();
foreach ([
	new \Base3\Usermanager\Criterion\Base3AnonymousCriterion(),
	new \Base3\Usermanager\Criterion\Base3AuthenticatedCriterion(),
	new \Base3\Usermanager\Criterion\Base3UserIdCriterion(),
	new \Base3\Usermanager\Criterion\Base3UserIdentityCriterion(),
	new \Base3Moodle\Criterion\MoodleRoleCriterion(),
	new \Base3Moodle\Criterion\MoodleCourseGroupCriterion(),
	new \Base3Moodle\Criterion\MoodleSiteAdministratorCriterion()
] as $criterion) $map->names[$criterion::getName()] = $criterion;
$map->registry[\Base3\Usermanager\Api\IUserCriterion::class] = array_values($map->names);
$map->registry[\Base3\Usermanager\Api\IGroup::class] = [
	new \Base3Moodle\Group\MoodleAnonymousGroup(), new \Base3Moodle\Group\MoodleAuthenticatedGroup(),
	new \Base3Moodle\Group\MoodleAdministrationGroup(), new \Base3MoodleLab\Group\MoodleLabDevelopmentGroup()
];
$map->registry[\Base3\Authorization\Api\IFeature::class] = [new \PulseCore\Feature\PulseCorePlayFeature()];
$evaluator = new \Base3\Usermanager\UserCriterionEvaluator($map);
$store = new TestSettingsStore();
$features = new \Base3Moodle\Service\MoodleFeatureConfiguration($store, $evaluator, $map);
$groups = new \Base3Moodle\Service\MoodleGroupConfiguration($store, $map, $evaluator, $features);
$users = new \Base3Moodle\Adapter\MoodleUsermanager($groups, $evaluator);
$service = new \Base3Moodle\Authorization\MoodleAuthorizationService($users, $map, $evaluator, $features);
$checkFeature = new \Base3\Authorization\Dto\AuthorizationRequest('moodle', \Base3\Usermanager\Permission::for('feature', 'pulsecoreplayfeature'));
check($service->decide($checkFeature)->isAllowed(), 'PulseCore default audience allows an authenticated Moodle user');
check(in_array('authenticated', array_map(static fn($g) => $g->name, $users->getGroups()), true), 'native authenticated group is discovered through IGroup');
check(in_array('administration', array_map(static fn($g) => $g->name, $users->getGroups()), true), 'site administrator criterion resolves through native Moodle');
check(!in_array('development', array_map(static fn($g) => $g->name, $users->getGroups()), true), 'site administrator is not automatically a developer');
$editor = new \Base3Moodle\Service\MoodleAudienceEditor($map, $evaluator);
$expr = $editor->parse([
	['kind' => 'role', 'value' => '7'],
	['kind' => 'group', 'value' => 'development']
], ['development']);
check($expr['any'][0]['criterion'] === 'moodlerolecriterion', 'Moodle feature roles use native role criterion');
check($evaluator->matches($users->getUser(), $expr, ['authenticated']), 'native role criterion matches user');
check($evaluator->matches($users->getUser(), ['criterion' => 'moodlecoursegroupcriterion', 'config' => ['course_id' => 5, 'group_id' => 20]]), 'course group criterion delegates to Moodle');
check(!$evaluator->matches($users->getUser(), ['criterion' => 'moodlecoursegroupcriterion', 'config' => ['course_id' => 6, 'group_id' => 20]]), 'foreign course group cannot match');
$features->replaceAll(['pulsecoreplayfeature' => []], array_map(static fn($g): string => $g->name, $groups->getGroups()));
check(!$service->decide($checkFeature)->isAllowed(), 'explicitly empty feature audience denies PulseCore');
$native = new \Base3\Authorization\Dto\AuthorizationRequest('moodle', \Base3\Usermanager\Permission::for('moodle','moodle/course:view'),
	new \Base3\Authorization\Dto\ResourceReference('moodle','context_id',77));
check($service->decide($native)->isAllowed(), 'native Moodle capabilities resolved against explicit context');
$invalid = new \Base3\Authorization\Dto\AuthorizationRequest('moodle', \Base3\Usermanager\Permission::for('moodle','moodle/course:view'),
	new \Base3\Authorization\Dto\ResourceReference('moodle','context_id',10000));
check(!$service->decide($invalid)->isAllowed(), 'unknown Moodle contexts fail closed');
$spoof = new \Base3\Authorization\Dto\AuthorizationRequest('moodle', \Base3\Usermanager\Permission::for('moodle','moodle/course:view'), null,
	new \Base3\Authorization\Dto\SubjectReference('moodle','user',41));
check(!$service->decide($spoof)->isAllowed(), 'client-selected subject denied');
check(!$service->decide(new \Base3\Authorization\Dto\AuthorizationRequest('foreign', \Base3\Usermanager\Permission::for('system','admin')))->isAllowed(), 'unknown authority denied');
$batch = $service->decideMany(['allowed' => $native, 13 => $invalid]);
check(array_keys($batch) === ['allowed',13] && $batch['allowed']->isAllowed() && !$batch[13]->isAllowed(), 'batched decisions preserve keys');
$groups->create('marketing', 'Marketing team');
check(in_array('marketing', array_map(static fn($g) => $g->name, $groups->getGroups()), true), 'custom group appears in canonical catalog');
$features->replaceAll(['pulsecoreplayfeature' => ['group' => 'marketing']], array_map(static fn($g) => $g->name, $groups->getGroups()));
try { $groups->remove('marketing'); throw new RuntimeException('Missing reference enforcement.'); }
catch (InvalidArgumentException) { echo "PASS: referenced groups cannot be removed\n"; }
check($store->saves === 3, 'feature and group updates use one native-backed dataset write each');
// An explicit native role criterion grants the BASE3 development group. The role does
// not turn a user into a Moodle administrator, and UI visibility is not a permission grant.
$groups->replaceAll(['development' => ['criterion' => 'moodlerolecriterion', 'config' => ['role_ids' => [7]]]]);
$GLOBALS['siteAdmin'] = false;
$roleMatchedUsermanager = new \Base3Moodle\Adapter\MoodleUsermanager($groups, $evaluator);
$roleMatchedAuthorization = new \Base3Moodle\Authorization\MoodleAuthorizationService($roleMatchedUsermanager, $map, $evaluator, $features);
check(in_array('development', array_map(static fn($group): string => $group->name, $roleMatchedUsermanager->getGroups()), true),
    'Moodle native role can explicitly grant BASE3 development membership');
check(!$roleMatchedAuthorization->decide(new \Base3\Authorization\Dto\AuthorizationRequest('moodle', \Base3\Usermanager\Permission::for('system', 'admin')))->isAllowed(),
    'BASE3 development membership does not bypass native Moodle administrator rights');
$features->replaceAll(['pulsecoreplayfeature' => ['group' => 'development']],
    array_map(static fn($group): string => $group->name, $groups->getGroups()));
check($roleMatchedAuthorization->decide($checkFeature)->isAllowed(),
    'Feature audience can reference the explicitly granted development group');
check($store->saves === 5, 'role-group and feature audience assignments each persist as one host settings write');
echo "MoodleAuthorizationSmokeTest OK.\n";

// Host administration adapter and the exact shared ClientStack template contracts.
$DB = new class {
	public function get_records(string $table, array $conditions = [], string $sort = '', string $fields = '*'): array {
		if ($table === 'role') return [(object)['id' => 7, 'shortname' => 'manager']];
		throw new RuntimeException('Unexpected Moodle database read in audience smoke test.');
	}
};
$forbidden = new \Base3Moodle\Authorization\MoodleAudienceAdministrationService(
	$service, $users, $groups, $features, $editor, $map
);
$GLOBALS['siteAdmin'] = true;
try { $forbidden->getGroupView(); throw new RuntimeException('Missing BASE3 development guard.'); }
catch (RuntimeException $error) {
	check($error->getMessage() === 'BASE3 development membership is required.', 'site administrator without development membership cannot inspect groups');
}
$GLOBALS['siteAdmin'] = false;
$admin = new \Base3Moodle\Authorization\MoodleAudienceAdministrationService(
	$roleMatchedAuthorization, $roleMatchedUsermanager, $groups, $features, $editor, $map
);
try { $admin->replaceFeatureRules(['pulsecoreplayfeature' => []]); throw new RuntimeException('Missing Moodle administrator guard.'); }
catch (RuntimeException $error) {
	check($error->getMessage() === 'Moodle site administration is required.', 'developer without native Moodle administration cannot save features');
}
$GLOBALS['siteAdmin'] = true;
$admin->assertCanAdminister();
check($admin instanceof \UiFoundation\Api\IAuthorizationAudienceAdministrationService, 'Moodle uses unchanged UiFoundation administration contract');
$groupView = $admin->getGroupView();
$groupRows = array_column($groupView['entries'], null, 'name');
check(isset($groupRows['development']) && $groupRows['development']['codeCriteria'] !== []
	&& $groupRows['development']['source'] === 'code' && $groupRows['development']['editable'], 'existing code-owned group criteria remain visible and separate');
check(($groupView['defaultGroup'] ?? null) === ''
	&& ($groupView['roleOptions'][0] ?? null) === ['id' => 7, 'label' => 'manager']
	&& isset($groupView['criterionOptions'][0]['schema']), 'group data uses shared template shape with Moodle role IDs and criterion schemas');
$featureView = $admin->getFeatureView();
check(($featureView['rows'][0]['name'] ?? null) === 'pulsecoreplayfeature'
	&& ($featureView['rows'][0]['rules'][0]['value'] ?? null) === 'development'
	&& in_array('marketing', $featureView['groups'], true), 'feature data uses shared template shape and preserves stored audiences');
$previousSaves = $store->saves;
try { $admin->replaceGroupRules(['authenticated' => []], ''); throw new RuntimeException('Protected group unexpectedly editable.'); }
catch (InvalidArgumentException) { check($store->saves === $previousSaves, 'locked code groups cannot be modified through shared form'); }
try { $admin->replaceFeatureRules(['made_up_feature' => []]); throw new RuntimeException('Unknown feature unexpectedly editable.'); }
catch (InvalidArgumentException) { check($store->saves === $previousSaves, 'unknown features cannot be modified through shared form'); }
try { $admin->replaceGroupRules([], 'development'); throw new RuntimeException('Privileged fallback unexpectedly accepted.'); }
catch (InvalidArgumentException) { check($store->saves === $previousSaves, 'privileged groups cannot become the fallback'); }
$admin->replaceGroupRules([
	'development' => [['kind' => 'role', 'value' => '7']],
	'administration' => [], 'marketing' => []
], 'marketing');
check($store->saves === $previousSaves + 1 && $groups->getDefaultGroup() === 'marketing'
	&& $groups->getEntries()[0]['source'] === 'code', 'group form persists all editable criteria and nonprivileged fallback in one settings write');
check($groupRows['development']['codeCriteria'] === (new \Base3MoodleLab\Group\MoodleLabDevelopmentGroup())->getCriteria(),
	'fixed source-owned development criteria are unchanged by edited rules');
try { $admin->removeGroup('marketing'); throw new RuntimeException('Fallback group unexpectedly removed.'); }
catch (InvalidArgumentException) { check($groups->getDefaultGroup() === 'marketing', 'configured fallback group cannot be deleted'); }
$originalUser = $USER;
$USER = (object)['id' => 99, 'username' => 'member', 'firstname' => 'Ordinary', 'lastname' => 'Member',
	'deleted' => 0, 'email' => 'member@example.org', 'lang' => 'en'];
$GLOBALS['siteAdmin'] = false;
$memberGroups = (new \Base3Moodle\Adapter\MoodleUsermanager($groups, $evaluator))->getGroups();
check(in_array('marketing', array_map(static fn($group): string => $group->name, $memberGroups), true)
	&& !in_array('development', array_map(static fn($group): string => $group->name, $memberGroups), true),
	'nonprivileged fallback applies only to authenticated users without specific group matches');
$USER = $originalUser;
$GLOBALS['siteAdmin'] = true;
$previousSaves = $store->saves;
$admin->replaceFeatureRules(['pulsecoreplayfeature' => [['kind' => 'group', 'value' => 'development']]]);
check($store->saves === $previousSaves + 1 && $features->getAudience(new \PulseCore\Feature\PulseCorePlayFeature()) === ['any' => [['group' => 'development']]],
	'feature edits use the existing persisted audience schema and one settings write');
check(\ClientStack\Display\AuthorizationGroupsDisplay::getName() === 'authorizationgroupsdisplay'
	&& \ClientStack\Display\AuthorizationFeaturesDisplay::getName() === 'authorizationfeaturesdisplay',
	'both shared ClientStack display classes are used unchanged');
$renderer = new class {
	public array $_ = [];
	public function render(string $file, array $data): string {
		$this->_ = $data;
		ob_start();
		try { require $file; return (string)ob_get_clean(); }
		catch (Throwable $error) { ob_end_clean(); throw $error; }
	}
};
$sharedTemplates = $modules . '/ClientStack/tpl/Display/';
$groupHtml = $renderer->render($sharedTemplates . 'AuthorizationGroupsDisplay.php', array_merge(
	$admin->getGroupView(), ['endpoint' => '/local/base3/ajax.php', 'csrf' => 'test-token', 'translations' => [], 'message' => '']
));
check(str_contains($groupHtml, 'data-base3-display="authorizationgroupsdisplay"')
	&& str_contains($groupHtml, 'base3_default_group') && str_contains($groupHtml, 'base3_group_csrf'),
	'common groups template renders current Moodle groups with shared controls');
$featureHtml = $renderer->render($sharedTemplates . 'AuthorizationFeaturesDisplay.php', array_merge(
	$admin->getFeatureView(), ['endpoint' => '/local/base3/ajax.php', 'csrf' => 'test-token', 'translations' => [], 'message' => '']
));
check(str_contains($featureHtml, 'data-base3-display="authorizationfeaturesdisplay"')
	&& str_contains($featureHtml, 'base3_feature_csrf') && str_contains($featureHtml, 'pulsecoreplayfeature'),
	'common features template renders persisted Moodle audiences');
echo "Moodle shared administration contract and templates OK.\n";

