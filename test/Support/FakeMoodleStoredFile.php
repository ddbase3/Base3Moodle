<?php declare(strict_types=1);

namespace Base3Moodle\Test\Support;

final class FakeMoodleStoredFile {

	public function __construct(
		private readonly FakeMoodleFileStorage $storage,
		public readonly string $key,
		private readonly array $entry
	) {}

	public function get_filepath(): string { return $this->entry['filepath']; }
	public function get_filename(): string { return $this->entry['filename']; }
	public function get_filesize(): int { return strlen($this->entry['content']); }
	public function get_timemodified(): int { return $this->entry['modified']; }
	public function is_directory(): bool { return $this->entry['filename'] === '.'; }
	public function get_content(): string { return $this->entry['content']; }
	public function delete(): bool { return $this->storage->delete($this->key); }
}
