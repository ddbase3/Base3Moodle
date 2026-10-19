<?php
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$entries = $this->_['rows'];
$groups = $this->_['groups'];
$criteria = $this->_['criteria'];
$roles = $this->_['roles'];
?>
<link rel="stylesheet" href="<?php echo $e($this->_['styleUrl']); ?>">
<section class="base3-audience-admin" data-base3-audience-root="features">
	<h3>BASE3 features</h3>
	<p>Assign individual Moodle users, native Moodle roles, BASE3 groups or discoverable criteria. Native Moodle capability checks remain independent.</p>
	<?php if ($this->_['message'] !== ''): ?><p tabindex="-1" role="status" data-audience-status><?php echo $e($this->_['message']); ?></p><?php endif; ?>
	<p role="alert" data-audience-error hidden></p>
	<form method="post" action="<?php echo $e($this->_['endpoint']); ?>" data-audience-form>
		<?php foreach ($entries as $entry): ?>
		<fieldset>
			<legend><?php echo $e($entry['label']); ?></legend>
			<p><?php echo $e($entry['description']); ?> <code><?php echo $e($entry['name']); ?></code></p>
			<p>Permissions: <?php echo $e(implode(', ', $entry['permissions'])); ?></p>
			<?php $prefix = 'base3_feature_rules[' . $entry['name'] . ']'; $ruleRows = $entry['rules']; $allowGroups = true; require __DIR__ . '/AuthorizationAudienceEditor.php'; ?>
		</fieldset>
		<?php endforeach; ?>
		<input type="hidden" name="base3_feature_action" value="save">
		<input type="hidden" name="sesskey" value="<?php echo $e(sesskey()); ?>">
		<button type="submit" class="btn btn-primary">Save feature audiences</button>
	</form>
</section>
<script src="<?php echo $e($this->_['scriptUrl']); ?>"></script>
