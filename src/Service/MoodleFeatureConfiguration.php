<?php declare(strict_types=1);

namespace Base3Moodle\Service;

use Base3\Api\IClassMap;
use Base3\Authorization\Api\IFeature;
use Base3\Settings\Api\ISettingsStore;
use Base3\Usermanager\UserCriterionEvaluator;
use InvalidArgumentException;

/** Host-owned feature audience overrides in the existing BASE3 settings backend. */
final class MoodleFeatureConfiguration {

	public const SETTINGS_GROUP = 'base3_authorization_features';
	public const SETTINGS_NAME = 'catalog';

	private ?array $rules = null;

	public function __construct(
		private readonly ISettingsStore $settings,
		private readonly UserCriterionEvaluator $evaluator,
		private readonly IClassMap $classMap
	) {}

	/** @return array<string,mixed> Effective explicit override or code-defined audience */
	public function getAudience(IFeature $feature): array {
		$rules = $this->load();
		$name = $feature::getName();
		return array_key_exists($name, $rules) ? $rules[$name] : $feature->getDefaultAudience();
	}

	/**
	 * @param array<string,array<string,mixed>> $rules Full set of feature overrides
	 * @param list<string> $groups Known logical BASE3 groups
	 * @return void
	 * @throws InvalidArgumentException On an unknown feature or malformed audience
	 */
	public function replaceAll(array $rules, array $groups): void {
		$known = [];
		foreach ($this->classMap->getInstancesByInterface(IFeature::class) as $feature) {
			if (!$feature instanceof IFeature || isset($known[$feature::getName()])) throw new InvalidArgumentException('Invalid or duplicate feature definition.');
			$known[$feature::getName()] = true;
		}
		foreach ($rules as $name => $expression) {
			if (!is_string($name) || !isset($known[$name]) || !is_array($expression)) throw new InvalidArgumentException('Unknown feature audience.');
			$this->evaluator->validate($expression, $groups);
		}
		$this->settings->set(self::SETTINGS_GROUP, self::SETTINGS_NAME, ['rules' => $rules]);
		$this->settings->save();
		$this->rules = $rules;
	}

	/** Checks both explicit overrides and code defaults before a group is removed. */
	public function referencesGroup(string $groupName): bool {
		$overrides = $this->load();
		foreach ($this->classMap->getInstancesByInterface(IFeature::class) as $feature) {
			if (!$feature instanceof IFeature) continue;
			$expr = array_key_exists($feature::getName(), $overrides) ? $overrides[$feature::getName()] : $feature->getDefaultAudience();
			if ($this->containsGroup($expr, $groupName)) return true;
		}
		return false;
	}

	/** @return array<string,array<string,mixed>> */
	private function load(): array {
		if ($this->rules !== null) return $this->rules;
		$data = $this->settings->get(self::SETTINGS_GROUP, self::SETTINGS_NAME, []);
		if (!is_array($data) || (array_diff(array_keys($data), ['rules']) !== []) || !is_array($data['rules'] ?? [])) {
			throw new InvalidArgumentException('Invalid stored feature audiences.');
		}
		$rules = $data['rules'] ?? [];
		if (array_is_list($rules) && $rules !== []) throw new InvalidArgumentException('Expected feature-keyed audiences.');
		foreach ($rules as $name => $expression) if (!is_string($name) || !is_array($expression)) throw new InvalidArgumentException('Invalid feature audience record.');
		return $this->rules = $rules;
	}

	private function containsGroup(array $expression, string $name): bool {
		if (isset($expression['group'])) return $expression['group'] === $name;
		foreach (['any', 'all'] as $operator) {
			foreach (($expression[$operator] ?? []) as $part) {
				if (is_array($part) && $this->containsGroup($part, $name)) return true;
			}
		}
		return isset($expression['not']) && is_array($expression['not']) && $this->containsGroup($expression['not'], $name);
	}
}
