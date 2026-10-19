<?php declare(strict_types=1);

namespace Base3Moodle\Authorization;

use Base3\Api\IClassMap;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Api\IFeature;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\UserCriterionEvaluator;
use Base3Moodle\Service\MoodleFeatureConfiguration;
use InvalidArgumentException;
use Throwable;

/** One Moodle authority: native capabilities for host operations, shared criteria for BASE3 features. */
final class MoodleAuthorizationService implements IAuthorizationService {

	public const AUTHORITY = 'moodle';

	private ?array $featurePermissions = null;

	public function __construct(
		private readonly IUsermanager $usermanager,
		private readonly IClassMap $classMap,
		private readonly UserCriterionEvaluator $evaluator,
		private readonly MoodleFeatureConfiguration $features
	) {}

	public function getDefaultAuthority(): string { return self::AUTHORITY; }

	public function decide(AuthorizationRequest $request): AuthorizationDecision {
		if ($request->authority !== self::AUTHORITY) return AuthorizationDecision::indeterminate('unknown_authority');
		if ($request->subject !== null || $request->onBehalfOf !== null) return AuthorizationDecision::deny('unsupported_principal');
		if ($request->parameters !== [] || $request->context !== []) {
			return AuthorizationDecision::deny('unsupported_authorization_parameters');
		}
		$scope = (string)$request->permission->scope;
		$operation = (string)$request->permission->permission;
		if ($scope === 'feature') {
			if ($request->resource !== null) return AuthorizationDecision::deny('feature_resource_not_supported');
			try {
				$feature = $this->featurePermissions()[$operation] ?? null;
				if (!$feature instanceof IFeature) return AuthorizationDecision::deny('unknown_feature');
				$user = $this->usermanager->getUser();
				$groups = array_map(static fn($group): string => (string)$group->name, $this->usermanager->getGroups());
				$expr = $this->features->getAudience($feature);
				return $this->evaluator->matches($user, $expr, $groups)
					? AuthorizationDecision::allow('base3_feature_audience') : AuthorizationDecision::deny('feature_audience_not_allowed');
			} catch (Throwable) {
				return AuthorizationDecision::indeterminate('invalid_feature_authorization');
			}
		}
		if ($scope === 'system' && $operation === 'admin') {
			if ($request->resource !== null) return AuthorizationDecision::deny('unexpected_resource');
			return is_siteadmin() ? AuthorizationDecision::allow('moodle_site_administrator') : AuthorizationDecision::deny('native_administrator_required');
		}
		if ($scope !== 'moodle' || !str_contains($operation, '/') || $this->usermanager->getUser() === null) {
			return AuthorizationDecision::deny('unknown_native_permission');
		}
		try {
			$context = \context_system::instance();
			if ($request->resource !== null) {
				if ($request->resource->type !== 'context_id' || !ctype_digit((string)$request->resource->id) || (int)$request->resource->id <= 0) {
					return AuthorizationDecision::deny('unsupported_resource_type');
				}
				$context = \context::instance_by_id((int)$request->resource->id, IGNORE_MISSING);
				if ($context === false) return AuthorizationDecision::deny('unknown_native_context');
			}
			return has_capability($operation, $context)
				? AuthorizationDecision::allow('moodle_capability') : AuthorizationDecision::deny('moodle_capability_not_granted');
		} catch (Throwable) {
			return AuthorizationDecision::indeterminate('moodle_authority_error');
		}
	}

	public function decideMany(array $requests): array {
		$results = [];
		foreach ($requests as $key => $request) {
			if (!$request instanceof AuthorizationRequest) throw new InvalidArgumentException('Expected AuthorizationRequest.');
			$results[$key] = $this->decide($request);
		}
		return $results;
	}

	/** @return array<string,IFeature> */
	private function featurePermissions(): array {
		if ($this->featurePermissions !== null) return $this->featurePermissions;
		$found = [];
		foreach ($this->classMap->getInstancesByInterface(IFeature::class) as $feature) {
			if (!$feature instanceof IFeature) throw new InvalidArgumentException('Invalid discoverable BASE3 feature.');
			foreach ($feature->getPermissions() as $permission) {
				if ((string)$permission->scope !== 'feature' || (string)$permission->permission === '' || isset($found[(string)$permission->permission])) {
					throw new InvalidArgumentException('Duplicate or invalid feature permission owner.');
				}
				$found[(string)$permission->permission] = $feature;
			}
		}
		return $this->featurePermissions = $found;
	}
}
