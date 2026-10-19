<?php
/** @var string $prefix */
/** @var string|int $index */
/** @var array $rule */
/** @var bool $allowGroups */
/** @var array $groups */
/** @var array $criteria */
/** @var array $roles */
$kind = (string)($rule['kind'] ?? ($allowGroups ? 'group' : 'user'));
$value = (string)($rule['value'] ?? '');
$config = (string)($rule['config'] ?? '{}');
$name = $prefix . '[' . $index . ']';
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="base3-audience-row" data-audience-row>
	<label>Assignment type
		<select name="<?php echo $e($name); ?>[kind]" data-audience-kind>
			<option value="user"<?php echo $kind === 'user' ? ' selected' : ''; ?>>Moodle user</option>
			<option value="role"<?php echo $kind === 'role' ? ' selected' : ''; ?>>Moodle role</option>
			<?php if ($allowGroups): ?><option value="group"<?php echo $kind === 'group' ? ' selected' : ''; ?>>BASE3 group</option><?php endif; ?>
			<option value="criterion"<?php echo $kind === 'criterion' ? ' selected' : ''; ?>>Criterion</option>
			<option value="expression"<?php echo $kind === 'expression' ? ' selected' : ''; ?>>Advanced expression</option>
		</select>
	</label>
	<label data-audience-wrap="user">Moodle user ID
		<input type="number" min="1" step="1" name="<?php echo $e($name); ?>[value]" value="<?php echo $kind === 'user' ? $e($value) : ''; ?>" data-audience-value="user">
	</label>
	<label data-audience-wrap="role">Native Moodle role
		<select name="<?php echo $e($name); ?>[value]" data-audience-value="role">
			<option value="">Select a role</option>
			<?php foreach ($roles as $role): ?><option value="<?php echo (int)$role['id']; ?>"<?php echo $kind === 'role' && $value === (string)$role['id'] ? ' selected' : ''; ?>><?php echo $e($role['name']); ?> (<?php echo (int)$role['id']; ?>)</option><?php endforeach; ?>
		</select>
	</label>
	<?php if ($allowGroups): ?>
	<label data-audience-wrap="group">BASE3 group
		<select name="<?php echo $e($name); ?>[value]" data-audience-value="group">
			<option value="">Select a group</option>
			<?php foreach ($groups as $group): ?><option value="<?php echo $e($group); ?>"<?php echo $kind === 'group' && $value === $group ? ' selected' : ''; ?>><?php echo $e($group); ?></option><?php endforeach; ?>
		</select>
	</label>
	<?php endif; ?>
	<label data-audience-wrap="criterion">Criterion
		<select name="<?php echo $e($name); ?>[value]" data-audience-value="criterion">
			<option value="">Select a criterion</option>
			<?php foreach ($criteria as $criterion): ?><option value="<?php echo $e($criterion['name']); ?>"<?php echo $kind === 'criterion' && $value === $criterion['name'] ? ' selected' : ''; ?>><?php echo $e($criterion['name']); ?></option><?php endforeach; ?>
		</select>
	</label>
	<div data-audience-wrap="config" class="base3-audience-config">
		<div data-audience-properties></div>
		<label data-audience-json-wrap>Configuration (JSON)
			<textarea rows="2" name="<?php echo $e($name); ?>[config]" data-audience-config><?php echo $e($config); ?></textarea>
		</label>
		<p class="base3-audience-hint" data-audience-schema role="status"></p>
	</div>
	<button type="button" data-audience-remove class="btn btn-secondary" aria-label="Remove assignment">Remove</button>
</div>
