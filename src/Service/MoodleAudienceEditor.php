<?php declare(strict_types=1);

namespace Base3Moodle\Service;

use Base3\Api\IClassMap;
use Base3\Usermanager\Api\IUserCriterion;
use Base3\Usermanager\UserCriterionEvaluator;
use InvalidArgumentException;

/** One form-to-expression translator for both Moodle-hosted administration screens. */
final class MoodleAudienceEditor {

	public function __construct(
		private readonly IClassMap $classMap,
		private readonly UserCriterionEvaluator $evaluator
	) {}

	/** @return list<array{name:string,schema:array}> */
	public function getCriteria(): array {
		$found = [];
		foreach ($this->classMap->getInstancesByInterface(IUserCriterion::class) as $criterion) {
			if (!$criterion instanceof IUserCriterion) throw new InvalidArgumentException('Invalid criterion.');
			$name = $criterion::getName();
			if (isset($found[$name])) throw new InvalidArgumentException('Duplicate user criterion.');
			$found[$name] = ['name' => $name, 'schema' => $criterion->getSchema()];
		}
		ksort($found);
		return array_values($found);
	}

	/** @return list<array{id:int,name:string}> Native Moodle role options, without a BASE3 role registry */
	public function getRoleOptions(): array {
		global $DB;
		$result = [];
		foreach ($DB->get_records('role', [], 'sortorder ASC', 'id,shortname') as $role) {
			$result[] = ['id' => (int)$role->id, 'name' => (string)$role->shortname];
		}
		return $result;
	}

	/**
	 * @param array $rows Repeatable selection rows
	 * @param list<string>|null $groups Known feature groups, or null for group membership rules
	 * @return array<string,mixed> Validated BASE3 audience expression
	 * @throws InvalidArgumentException On invalid native identifiers or criterion syntax
	 */
	public function parse(array $rows, ?array $groups): array {
		if (count($rows) > 100) throw new InvalidArgumentException('Too many audience assignments.');
		$items = [];
		foreach ($rows as $row) {
			if (!is_array($row) || !is_string($row['kind'] ?? null)) throw new InvalidArgumentException('Invalid audience row.');
			$value = $row['value'] ?? '';
			switch ($row['kind']) {
				case 'user':
					$items[] = ['criterion' => 'base3useridcriterion', 'config' => ['user_ids' => [$this->positiveId($value)]]];
					break;
				case 'role':
					$items[] = ['criterion' => 'moodlerolecriterion', 'config' => ['role_ids' => [$this->positiveId($value)]]];
					break;
				case 'group':
					if ($groups === null || !is_string($value) || !in_array($value, $groups, true)) throw new InvalidArgumentException('Unknown BASE3 group.');
					$items[] = ['group' => $value];
					break;
				case 'criterion':
					if (!is_string($value) || $value === '') throw new InvalidArgumentException('Unknown criterion.');
					$items[] = ['criterion' => $value, 'config' => $this->json($row['config'] ?? '{}')];
					break;
				case 'expression':
					$items[] = $this->json($row['config'] ?? '{}');
					break;
				default: throw new InvalidArgumentException('Unknown audience selector.');
			}
		}
		$expression = $items === [] ? [] : ['any' => $items];
		$this->evaluator->validate($expression, $groups);
		return $expression;
	}

	/** @return list<array{kind:string,value:string,config:string}> */
	public function describe(array $expression): array {
		if ($expression === []) return [];
		$items = array_keys($expression) === ['any'] && is_array($expression['any']) ? $expression['any'] : [$expression];
		$rows = [];
		foreach ($items as $item) {
			if (!is_array($item)) throw new InvalidArgumentException('Invalid existing audience expression.');
			if (array_keys($item) === ['group'] && is_string($item['group'])) {
				$rows[] = ['kind' => 'group', 'value' => $item['group'], 'config' => '{}'];
				continue;
			}
			$name = $item['criterion'] ?? '';
			$config = $item['config'] ?? [];
			if ($name === 'base3useridcriterion' && count((array)($config['user_ids'] ?? [])) === 1) {
				$rows[] = ['kind' => 'user', 'value' => (string)$config['user_ids'][0], 'config' => '{}'];
				continue;
			}
			if ($name === 'moodlerolecriterion' && !isset($config['context_id']) && count((array)($config['role_ids'] ?? [])) === 1) {
				$rows[] = ['kind' => 'role', 'value' => (string)$config['role_ids'][0], 'config' => '{}'];
				continue;
			}
			if (is_string($name) && $name !== '') {
				$rows[] = ['kind' => 'criterion', 'value' => $name, 'config' => json_encode($config === [] ? (object)[] : $config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
				continue;
			}
			$rows[] = ['kind' => 'expression', 'value' => '', 'config' => json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
		}
		return $rows;
	}

	private function positiveId(mixed $value): int {
		if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Invalid native identifier.');
		$id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($id === false) throw new InvalidArgumentException('Expected a positive Moodle identifier.');
		return $id;
	}

	private function json(mixed $value): array {
		if (!is_string($value) || strlen($value) > 20000) throw new InvalidArgumentException('Invalid JSON criterion configuration.');
		try { $data = json_decode($value, true, 20, JSON_THROW_ON_ERROR); }
		catch (\JsonException $error) { throw new InvalidArgumentException('Invalid criterion JSON.', 0, $error); }
		if (!is_array($data) || (array_is_list($data) && $data !== [])) throw new InvalidArgumentException('Criterion JSON must be an object.');
		return $data;
	}
}
