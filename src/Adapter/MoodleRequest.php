<?php declare(strict_types=1);

namespace Base3Moodle\Adapter;

use Base3\Core\Request;

/** Moodle transport data is read independently of POST business data. */
final class MoodleRequest extends Request {

	public function initFromGlobals(): void {
		parent::initFromGlobals();
		// The display target is taken from GET by the dispatcher. Remove only
		// transport fields so they cannot shadow business fields in request().
		unset($this->get['sesskey'], $this->get['base3_display_data']);
	}

	public static function fromGlobals(): self {
		$request = new self();
		$request->initFromGlobals();
		return $request;
	}
}
