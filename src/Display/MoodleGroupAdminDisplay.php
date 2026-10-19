<?php declare(strict_types=1);

namespace Base3Moodle\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3Moodle\Service\MoodleAudienceEditor;
use Base3Moodle\Service\MoodleGroupConfiguration;
use InvalidArgumentException;

/** Manages BASE3 group membership only, without modifying native Moodle role assignments. */
final class MoodleGroupAdminDisplay implements IDisplay {

	public function __construct(
		private readonly IAuthorizationService $authorization,
		private readonly IUsermanager $usermanager,
		private readonly MoodleGroupConfiguration $groups,
		private readonly MoodleAudienceEditor $audiences,
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly ILinkTargetService $links,
		private readonly IAssetResolver $assets
	) {}

	public static function getName(): string { return 'moodlegroupadmindisplay'; }
	public function setData($data) {}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'html') throw new InvalidArgumentException('Group administration renders HTML only.');
		if (!$this->isAdministrator()) {
			http_response_code(403);
			return '<p role="alert">BASE3 administration access denied.</p>';
		}
		$message = '';
		$action = $this->request->post('base3_group_action', '');
		if ($action !== '') {
			if (!is_string($action) || !confirm_sesskey()) throw new InvalidArgumentException('Invalid Moodle session key.');
			switch ($action) {
				case 'save':
					$values = $this->request->post('base3_group_rules', []);
					if (!is_array($values)) throw new InvalidArgumentException('Invalid group rules.');
					$editable = [];
					foreach ($this->groups->getEntries() as $entry) if ($entry['editable']) $editable[$entry['group']->name] = true;
					foreach ($values as $name => $rows) if (!isset($editable[$name]) || !is_array($rows)) throw new InvalidArgumentException('Unknown or locked group.');
					$rules = [];
					foreach ($editable as $name => $_) $rules[$name] = $this->audiences->parse($values[$name] ?? [], null);
					$this->groups->replaceAll($rules);
					$message = 'Group memberships saved.';
					break;
				case 'create':
					$this->groups->create((string)$this->request->post('base3_group_name', ''), (string)$this->request->post('base3_group_description', ''));
					$message = 'Group created.';
					break;
				case 'remove':
					$this->groups->remove((string)$this->request->post('base3_group_name', ''));
					$message = 'Group removed.';
					break;
				default: throw new InvalidArgumentException('Unknown group action.');
			}
		}
		$rows = [];
		foreach ($this->groups->getEntries() as $entry) {
			$rows[] = [
				'name' => (string)$entry['group']->name, 'description' => (string)$entry['group']->info,
				'editable' => $entry['editable'], 'source' => $entry['source'],
				'codeCriteria' => $entry['codeCriteria'], 'rules' => $this->audiences->describe($entry['configuredCriteria'])
			];
		}
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->setTemplate('Display/MoodleGroupAdminDisplay.php');
		$this->view->assign('rows', $rows);
		$this->view->assign('criteria', $this->audiences->getCriteria());
		$this->view->assign('roles', $this->audiences->getRoleOptions());
		$this->view->assign('endpoint', $this->links->getLink(['name' => self::getName(), 'out' => 'html']));
		$this->view->assign('styleUrl', $this->assets->resolve('plugin/Base3Moodle/assets/authorization/audience.css'));
		$this->view->assign('scriptUrl', $this->assets->resolve('plugin/Base3Moodle/assets/authorization/audience.js'));
		$this->view->assign('message', $message);
		return $this->view->loadTemplate();
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
