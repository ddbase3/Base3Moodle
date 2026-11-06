<?php declare(strict_types=1);

class context { public function __construct(public int $id) {} public static function instance_by_id(int $id, $strict = false) { return in_array($id, [1, 77], true) ? new self($id) : false; } }
