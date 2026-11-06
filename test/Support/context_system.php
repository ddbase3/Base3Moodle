<?php declare(strict_types=1);

class context_system extends context { public static function instance(): self { return new self(1); } }
