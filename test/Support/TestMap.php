<?php declare(strict_types=1);

namespace Base3Moodle\Test\Support;

class TestMap implements \Base3\Api\IClassMap {
	public array $registry = [];
	public array $names = [];
	public function instantiate(string $class) { return null; }
	public function instantiateWith(string $class, array $arguments = []) { return null; }
	public function generate($regenerate = false): void {}
	public function getApps() { return []; }
	public function &getInstances(array $criteria = []) { $a=[]; return $a; }
	public function &getInstancesByInterface($interface) { $a = $this->registry[$interface] ?? []; return $a; }
	public function &getInstancesByAppInterface($app, $interface, $retry = false) { $a=[]; return $a; }
	public function &getInstanceByAppName($app, $name, $retry = false) { $a=null;return $a; }
	public function getClassByInterfaceName(string $interface, string $name): ?string { return null; }
	public function &getInstanceByInterfaceName($interface, $name, $retry = false) {
		if (isset($this->names[$name])) return $this->names[$name];
		$none = null;
		return $none;
	}
	public function &getInstanceByAppInterfaceName($app, $interface, $name, $retry = false) { $a=null;return $a; }
	public function getPlugins() { return []; }
}
