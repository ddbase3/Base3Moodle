<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Session\Api\ISession;

/** Stores BASE3 keys under Moodle's managed session; never destroys Moodle login state. */
final class MoodleSession implements ISession {

	public function started(): bool {
		global $SESSION;
		return isset($SESSION) && is_object($SESSION);
	}

	public function getId(): string {
		return $this->started() ? session_id() : '';
	}

	public function start(): bool {
		if (!$this->started()) throw new \RuntimeException('Moodle session is not available.');
		return true;
	}

	public function destroy(): bool {
		global $SESSION;
		$this->start();
		unset($SESSION->base3);
		return true;
	}

	public function get(string $key, mixed $default = null): mixed {
		global $SESSION;
		$this->start();
		$values = (array) ($SESSION->base3 ?? []);
		return $values[$key] ?? $default;
	}

	public function set(string $key, mixed $value): void {
		global $SESSION;
		$this->start();
		$data = (array) ($SESSION->base3 ?? []);
		$data[$key] = $value;
		$SESSION->base3 = $data;
	}

	public function has(string $key): bool {
		global $SESSION;
		$this->start();
		return array_key_exists($key, (array) ($SESSION->base3 ?? []));
	}

	public function remove(string $key): void {
		global $SESSION;
		$this->start();
		$data = (array) ($SESSION->base3 ?? []);
		unset($data[$key]);
		$SESSION->base3 = $data;
	}
}
