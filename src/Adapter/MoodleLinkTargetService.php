<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\LinkTarget\Api\ILinkTargetService;

final class MoodleLinkTargetService implements ILinkTargetService {

	public function getLink(array $target, array $params = []): string {
		$name = (string) ($target['name'] ?? '');
		$out = (string) ($target['out'] ?? $params['out'] ?? 'html');
		$hasAction = array_key_exists('action', $params);
		$action = $hasAction ? (string) $params['action'] : '';

		unset($params['name'], $params['out'], $params['sesskey'], $params['action']);
		$transport = ['name' => $name, 'out' => $out, 'sesskey' => sesskey()];
		$url = (new \moodle_url('/local/base3/ajax.php', array_merge($params, $transport)))->out(false);

		// Some BASE3 displays append the action value directly to this URL.
		// Keep action last, including the '=' for an empty value.
		if ($hasAction) {
			$url .= '&action=' . rawurlencode($action);
		}

		return $url;
	}
}
