<?php declare(strict_types=1);

namespace Base3Moodle\Service;

use Base3\Api\IClassMap;
use Base3\Settings\Api\ISettingsStore;
use Base3\Usermanager\Api\IGroup;
use Base3\Usermanager\Group;
use Base3\Usermanager\UserCriterionEvaluator;
use InvalidArgumentException;

/** One canonical host-owned dataset for code-group additions and managed BASE3 groups. */
final class MoodleGroupConfiguration {

	public const SETTINGS_GROUP = 'base3moodle';
	public const SETTINGS_NAME = 'groups';

	private ?array $settingsCache = null;

	public function __construct(
		private readonly ISettingsStore $settings,
		private readonly IClassMap $classMap,
		private readonly UserCriterionEvaluator $evaluator,
		private readonly MoodleFeatureConfiguration $features
	) {}

	/** @return list<array{group:Group,criteria:array,codeCriteria:array,configuredCriteria:array,editable:bool,source:string}> */
	public function getEntries(): array {
		$data = $this->load();
		$result = [];
		foreach ($this->classMap->getInstancesByInterface(IGroup::class) as $definition) {
			if (!$definition instanceof IGroup) throw new InvalidArgumentException('Invalid BASE3 group definition.');
			$group = $definition->getGroup();
			$name = (string)$group->name;
			if ($name === '' || isset($result[$name]) || isset($data['groups'][$name])) throw new InvalidArgumentException('Duplicate BASE3 group name.');
			$base = $definition->getCriteria();
			$additional = $definition->isEditable() ? ($data['criteria'][$name] ?? []) : [];
			$this->evaluator->validate($base);
			$this->evaluator->validate($additional);
			$effective = $base === [] ? $additional : ($additional === [] ? $base : ['any' => [$base, $additional]]);
			$result[$name] = ['group' => $group, 'criteria' => $effective, 'codeCriteria' => $base,
				'configuredCriteria' => $additional, 'editable' => $definition->isEditable(), 'source' => 'code'];
		}
		foreach ($data['criteria'] as $name => $_) {
			if (!isset($result[$name]) || !$result[$name]['editable']) throw new InvalidArgumentException('Unknown or locked code-group override.');
		}
		foreach ($data['groups'] as $name => $item) {
			if (!is_string($name) || !$this->validName($name) || isset($result[$name]) || !is_array($item)
				|| !is_string($item['description'] ?? null) || !is_array($item['criteria'] ?? null)) {
				throw new InvalidArgumentException('Invalid managed BASE3 group.');
			}
			$this->evaluator->validate($item['criteria']);
			$group = Group::fromArray(['id' => $name, 'name' => $name, 'info' => $item['description'], 'roles' => []]);
			$result[$name] = ['group' => $group, 'criteria' => $item['criteria'], 'codeCriteria' => [],
				'configuredCriteria' => $item['criteria'], 'editable' => true, 'source' => 'managed'];
		}
		ksort($result);
		return array_values($result);
	}

	/** @return list<Group> */
	public function getGroups(): array { return array_map(static fn(array $e): Group => $e['group'], $this->getEntries()); }

	/** Replaces all editable criteria in one settings write without altering code-owned membership. */
	public function replaceAll(array $rules): void {
		$data = $this->load();
		$entries = $this->getEntries();
		$known = [];
		foreach ($entries as $entry) if ($entry['editable']) $known[(string)$entry['group']->name] = $entry['source'];
		if (array_diff(array_keys($rules), array_keys($known)) !== []) throw new InvalidArgumentException('Unknown group override.');
		foreach ($rules as $name => $expression) {
			if (!is_array($expression)) throw new InvalidArgumentException('Invalid group audience.');
			$this->evaluator->validate($expression);
		}
		foreach ($known as $name => $source) {
			$expr = $rules[$name] ?? [];
			if ($source === 'code') $data['criteria'][$name] = $expr;
			else $data['groups'][$name]['criteria'] = $expr;
		}
		$this->save($data);
	}

	public function create(string $name, string $description): void {
		$name = trim($name);
		$description = trim($description);
		if (!$this->validName($name) || $description === '' || strlen($description) > 250) throw new InvalidArgumentException('Invalid group identity or description.');
		foreach ($this->getEntries() as $entry) if ($entry['group']->name === $name) throw new InvalidArgumentException('BASE3 group already exists.');
		$data = $this->load();
		$data['groups'][$name] = ['description' => $description, 'criteria' => []];
		$this->save($data);
	}

	public function remove(string $name): void {
		$data = $this->load();
		if (!isset($data['groups'][$name])) throw new InvalidArgumentException('Only managed groups can be removed.');
		if ($this->features->referencesGroup($name)) throw new InvalidArgumentException('BASE3 group is still referenced by a feature.');
		unset($data['groups'][$name]);
		$this->save($data);
	}

	/** @return array{criteria:array,groups:array} */
	private function load(): array {
		if ($this->settingsCache !== null) return $this->settingsCache;
		$data = $this->settings->get(self::SETTINGS_GROUP, self::SETTINGS_NAME, []);
		if (array_diff(array_keys($data), ['criteria', 'groups']) !== []
			|| !is_array($data['criteria'] ?? []) || !is_array($data['groups'] ?? [])) throw new InvalidArgumentException('Invalid group settings schema.');
		return $this->settingsCache = ['criteria' => $data['criteria'] ?? [], 'groups' => $data['groups'] ?? []];
	}

	private function save(array $data): void {
		$this->settings->set(self::SETTINGS_GROUP, self::SETTINGS_NAME, $data);
		$this->settings->save();
		$this->settingsCache = $data;
	}

	private function validName(string $name): bool { return (bool)preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $name); }
}
