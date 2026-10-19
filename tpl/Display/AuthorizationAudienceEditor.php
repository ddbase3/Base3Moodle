<?php
/** @var string $prefix */
/** @var array $ruleRows */
/** @var bool $allowGroups */
/** @var array $groups */
/** @var array $criteria */
/** @var array $roles */
$schemas = [];
foreach ($criteria as $criterion) $schemas[$criterion['name']] = $criterion['schema'];
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="base3-audience-editor" data-audience-editor data-index="<?php echo count($ruleRows); ?>"
	data-schemas="<?php echo $e(json_encode($schemas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); ?>">
	<div data-audience-list>
		<?php foreach ($ruleRows as $index => $rule): ?><?php require __DIR__ . '/AuthorizationAudienceRow.php'; ?><?php endforeach; ?>
	</div>
	<template data-audience-template>
		<?php $index = '__INDEX__'; $rule = []; require __DIR__ . '/AuthorizationAudienceRow.php'; ?>
	</template>
	<button type="button" class="btn btn-secondary" data-audience-add>Add assignment</button>
	<p class="base3-audience-hint">Assignments are OR-connected. For course-specific roles select the Moodle role criterion and supply its context ID. Advanced expressions support AND, OR and NOT.</p>
</div>
