<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\LinkTarget\Api\ILinkTargetService;

final class MoodleLinkTargetService implements ILinkTargetService {
	public function getLink(array $target, array $params = []): string {
		$name = (string) ($target['name'] ?? '');
		$out = (string) ($target['out'] ?? $params['out'] ?? 'html');
		unset($params['name'], $params['out'], $params['sesskey']);
		// Moodle's sesskey is a transport concern. The application's own action,
		// often POST action=save, is not renamed or filtered.
		$transport = ['name' => $name, 'out' => $out, 'sesskey' => sesskey()];
		return (new \moodle_url('/local/base3/ajax.php', array_merge($params, $transport)))->out(false);
	}
}
