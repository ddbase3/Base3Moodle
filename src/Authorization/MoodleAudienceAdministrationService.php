<?php declare(strict_types=1);

namespace Base3Moodle\Authorization;

use Base3\Api\IClassMap;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Api\IFeature;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3Moodle\Service\MoodleAudienceEditor;
use Base3Moodle\Service\MoodleFeatureConfiguration;
use Base3Moodle\Service\MoodleGroupConfiguration;
use InvalidArgumentException;
use RuntimeException;
use UiFoundation\Api\IAuthorizationAudienceAdministrationService;

/** Moodle-owned authorization and persistence adapter for the unmodified ClientStack administration displays. */
final class MoodleAudienceAdministrationService implements IAuthorizationAudienceAdministrationService {

	public function __construct(
		private readonly IAuthorizationService $authorization,
		private readonly IUsermanager $usermanager,
		private readonly MoodleGroupConfiguration $groups,
		private readonly MoodleFeatureConfiguration $features,
		private readonly MoodleAudienceEditor $audiences,
		private readonly IClassMap $classMap
	) {}

	public function assertCanAdminister(): void {
		if (!$this->authorization->decide(new AuthorizationRequest(
			$this->authorization->getDefaultAuthority(), Permission::for('system', 'admin')
		))->isAllowed()) throw new RuntimeException('Moodle site administration is required.');
		foreach ($this->usermanager->getGroups() as $group) {
			if ((string)$group->name === 'development') return;
		}
		throw new RuntimeException('BASE3 development membership is required.');
	}

	public function getGroupView(): array {
		$this->assertCanAdminister();
		$entries = [];
		foreach ($this->groups->getEntries() as $entry) {
			$entries[] = [
				'name' => (string)$entry['group']->name,
				'description' => (string)$entry['group']->info,
				'source' => $entry['source'],
				'editable' => $entry['editable'],
				'codeCriteria' => $entry['codeCriteria'],
				'expression' => $entry['criteria'],
				'rows' => $this->audiences->describe($entry['configuredCriteria'])
			];
		}
		return [
			'entries' => $entries,
			'defaultGroup' => $this->groups->getDefaultGroup(),
			'criterionOptions' => $this->audiences->getCriteria(),
			'roleOptions' => $this->roleOptions(),
			'roleHint' => 'Moodle roles use native numeric IDs; select moodlerolecriterion for context-specific role assignments.'
		];
	}

	public function replaceGroupRules(array $rows, string $defaultGroup): void {
		$this->assertCanAdminister();
		$editable = [];
		foreach ($this->groups->getEntries() as $entry) {
			if ($entry['editable']) $editable[(string)$entry['group']->name] = true;
		}
		foreach ($rows as $name => $selected) {
			if (!is_string($name) || !isset($editable[$name]) || !is_array($selected)) {
				throw new InvalidArgumentException('Unknown, protected or invalid BASE3 group assignment.');
			}
		}
		$rules = [];
		foreach ($editable as $name => $_) $rules[$name] = $this->audiences->parse($rows[$name] ?? [], null);
		$this->groups->replaceAll($rules, $defaultGroup);
	}

	public function createGroup(string $name, string $description): void {
		$this->assertCanAdminister();
		$this->groups->create($name, $description);
	}

	public function removeGroup(string $name): void {
		$this->assertCanAdminister();
		$this->groups->remove($name);
	}

	public function getFeatureView(): array {
		$this->assertCanAdminister();
		$rows = [];
		foreach ($this->catalog() as $feature) {
			$permissions = [];
			foreach ($feature->getPermissions() as $permission) {
				$permissions[] = $permission->scope . '/' . $permission->permission;
			}
			$rows[] = [
				'name' => $feature::getName(),
				'label' => $feature->getLabel(),
				'description' => $feature->getDescription(),
				'permissions' => $permissions,
				'rules' => $this->audiences->describe($this->features->getAudience($feature))
			];
		}
		return [
			'rows' => $rows,
			'groups' => array_map(static fn($group): string => (string)$group->name, $this->groups->getGroups()),
			'criterionOptions' => $this->audiences->getCriteria(),
			'roleOptions' => $this->roleOptions(),
			'roleHint' => 'Moodle roles use native numeric IDs; select moodlerolecriterion for context-specific role assignments.'
		];
	}

	public function replaceFeatureRules(array $rows): void {
		$this->assertCanAdminister();
		$catalog = $this->catalog();
		foreach ($rows as $name => $selected) {
			if (!is_string($name) || !isset($catalog[$name]) || !is_array($selected)) {
				throw new InvalidArgumentException('Unknown or invalid feature audience.');
			}
		}
		$groups = array_map(static fn($group): string => (string)$group->name, $this->groups->getGroups());
		$rules = [];
		foreach ($catalog as $name => $_) $rules[$name] = $this->audiences->parse($rows[$name] ?? [], $groups);
		$this->features->replaceAll($rules, $groups);
	}

	/** @return array<string,IFeature> */
	private function catalog(): array {
		$found = [];
		foreach ($this->classMap->getInstancesByInterface(IFeature::class) as $feature) {
			if (!$feature instanceof IFeature || isset($found[$feature::getName()])) {
				throw new InvalidArgumentException('Invalid or duplicate BASE3 feature.');
			}
			$found[$feature::getName()] = $feature;
		}
		ksort($found);
		return $found;
	}

	/** @return list<array{id:int,label:string}> */
	private function roleOptions(): array {
		return array_map(static fn(array $role): array => [
			'id' => $role['id'], 'label' => $role['name']
		], $this->audiences->getRoleOptions());
	}
}
