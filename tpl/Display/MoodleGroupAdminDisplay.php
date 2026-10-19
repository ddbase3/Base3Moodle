<?php
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$entries = $this->_['rows'];
$groups = array_column($entries, 'name');
$criteria = $this->_['criteria'];
$roles = $this->_['roles'];
?>
<link rel="stylesheet" href="<?php echo $e($this->_['styleUrl']); ?>">
<section class="base3-audience-admin" data-base3-audience-root="groups">
	<h3>BASE3 groups</h3>
	<p>Manage BASE3 group membership through Moodle users, native roles and discoverable criteria. Moodle role assignments and capabilities are not changed.</p>
	<?php if ($this->_['message'] !== ''): ?><p tabindex="-1" role="status" data-audience-status><?php echo $e($this->_['message']); ?></p><?php endif; ?>
	<p role="alert" data-audience-error hidden></p>
	<form method="post" action="<?php echo $e($this->_['endpoint']); ?>" data-audience-form>
		<?php foreach ($entries as $entry): ?>
		<fieldset>
			<legend><?php echo $e($entry['name']); ?><?php echo $entry['source'] === 'managed' ? ' (custom)' : ''; ?></legend>
			<p><?php echo $e($entry['description']); ?></p>
			<?php if ($entry['codeCriteria'] !== []): ?>
				<p>Code-owned baseline (not editable): <code><?php echo $e(json_encode($entry['codeCriteria'], JSON_THROW_ON_ERROR)); ?></code></p>
			<?php endif; ?>
			<?php if ($entry['editable']): ?>
				<?php $prefix = 'base3_group_rules[' . $entry['name'] . ']'; $ruleRows = $entry['rules']; $allowGroups = false; require __DIR__ . '/AuthorizationAudienceEditor.php'; ?>
			<?php else: ?><p>This group is defined entirely in PHP.</p><?php endif; ?>
		</fieldset>
		<?php endforeach; ?>
		<input type="hidden" name="base3_group_action" value="save">
		<input type="hidden" name="sesskey" value="<?php echo $e(sesskey()); ?>">
		<button type="submit" class="btn btn-primary">Save memberships</button>
	</form>
	<h4>Create custom group</h4>
	<form method="post" action="<?php echo $e($this->_['endpoint']); ?>" data-audience-form>
		<label>Technical name <input name="base3_group_name" type="text" required pattern="[a-z][a-z0-9_-]{1,63}" maxlength="64"></label>
		<label>Description <input name="base3_group_description" type="text" required maxlength="250"></label>
		<input type="hidden" name="sesskey" value="<?php echo $e(sesskey()); ?>">
		<input type="hidden" name="base3_group_action" value="create">
		<button type="submit" class="btn btn-secondary">Create group</button>
	</form>
	<?php if (array_filter($entries, static fn(array $entry): bool => $entry['source'] === 'managed')): ?>
	<h4>Remove custom group</h4>
	<?php foreach ($entries as $entry): ?><?php if ($entry['source'] !== 'managed') continue; ?>
	<form method="post" action="<?php echo $e($this->_['endpoint']); ?>" data-audience-form>
		<span><?php echo $e($entry['name']); ?></span>
		<input type="hidden" name="base3_group_name" value="<?php echo $e($entry['name']); ?>">
		<input type="hidden" name="base3_group_action" value="remove">
		<input type="hidden" name="sesskey" value="<?php echo $e(sesskey()); ?>">
		<button type="submit" class="btn btn-secondary" data-audience-confirm="Remove group <?php echo $e($entry['name']); ?>?">Remove</button>
	</form>
	<?php endforeach; ?><?php endif; ?>
</section>
<script src="<?php echo $e($this->_['scriptUrl']); ?>"></script>
