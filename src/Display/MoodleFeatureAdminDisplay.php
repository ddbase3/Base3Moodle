<?php declare(strict_types=1);

namespace Base3Moodle\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IClassMap;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Api\IFeature;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3Moodle\Service\MoodleAudienceEditor;
use Base3Moodle\Service\MoodleFeatureConfiguration;
use Base3Moodle\Service\MoodleGroupConfiguration;
use InvalidArgumentException;

/** Allows admin-edited feature audiences while leaving Moodle capability checks native. */
final class MoodleFeatureAdminDisplay implements IDisplay {

	public function __construct(
		private readonly IAuthorizationService $authorization,
		private readonly IUsermanager $usermanager,
		private readonly IClassMap $classMap,
		private readonly MoodleFeatureConfiguration $features,
		private readonly MoodleGroupConfiguration $groups,
		private readonly MoodleAudienceEditor $audiences,
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly ILinkTargetService $links,
		private readonly IAssetResolver $assets
	) {}

	public static function getName(): string { return 'moodlefeatureadmindisplay'; }
	public function setData($data) {}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'html') throw new InvalidArgumentException('Feature administration renders HTML only.');
		if (!$this->isAdministrator()) {
			http_response_code(403);
			return '<p role="alert">BASE3 administration access denied.</p>';
		}
		$catalog = $this->catalog();
		$groupNames = array_map(static fn($group): string => (string)$group->name, $this->groups->getGroups());
		$message = '';
		$action = $this->request->post('base3_feature_action', '');
		if ($action !== '') {
			if ($action !== 'save' || !confirm_sesskey()) throw new InvalidArgumentException('Invalid feature administration request.');
			$rows = $this->request->post('base3_feature_rules', []);
			if (!is_array($rows)) throw new InvalidArgumentException('Invalid feature form.');
			foreach ($rows as $name => $selected) if (!isset($catalog[$name]) || !is_array($selected)) throw new InvalidArgumentException('Unknown feature audience.');
			$rules = [];
			foreach ($catalog as $name => $feature) $rules[$name] = $this->audiences->parse($rows[$name] ?? [], $groupNames);
			$this->features->replaceAll($rules, $groupNames);
			$message = 'Feature audiences saved.';
		}
		$entries = [];
		foreach ($catalog as $feature) {
			$permissions = [];
			foreach ($feature->getPermissions() as $permission) $permissions[] = $permission->scope . '/' . $permission->permission;
			$entries[] = [
				'name' => $feature::getName(), 'label' => $feature->getLabel(), 'description' => $feature->getDescription(),
				'permissions' => $permissions, 'rules' => $this->audiences->describe($this->features->getAudience($feature))
			];
		}
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->setTemplate('Display/MoodleFeatureAdminDisplay.php');
		$this->view->assign('rows', $entries);
		$this->view->assign('groups', $groupNames);
		$this->view->assign('roles', $this->audiences->getRoleOptions());
		$this->view->assign('criteria', $this->audiences->getCriteria());
		$this->view->assign('endpoint', $this->links->getLink(['name' => self::getName(), 'out' => 'html']));
		$this->view->assign('styleUrl', $this->assets->resolve('plugin/Base3Moodle/assets/authorization/audience.css'));
		$this->view->assign('scriptUrl', $this->assets->resolve('plugin/Base3Moodle/assets/authorization/audience.js'));
		$this->view->assign('message', $message);
		return $this->view->loadTemplate();
	}

	/** @return array<string,IFeature> */
	private function catalog(): array {
		$result = [];
		foreach ($this->classMap->getInstancesByInterface(IFeature::class) as $feature) {
			if (!$feature instanceof IFeature || isset($result[$feature::getName()])) throw new InvalidArgumentException('Duplicate or invalid feature.');
			$result[$feature::getName()] = $feature;
		}
		ksort($result);
		return $result;
	}

	private function isAdministrator(): bool {
		if (!$this->authorization->decide(new AuthorizationRequest(
			$this->authorization->getDefaultAuthority(), Permission::for('system', 'admin')
		))->isAllowed()) return false;
		foreach ($this->usermanager->getGroups() as $group) {
			if ((string)$group->name === 'development') return true;
		}
		return false;
	}
}
