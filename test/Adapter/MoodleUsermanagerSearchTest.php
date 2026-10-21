<?php declare(strict_types=1);

namespace Base3MoodleTest\Adapter;

use Base3\Usermanager\Api\IUsermanager;
use Base3Moodle\Adapter\MoodleUsermanager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Contract and input-boundary regression tests; no Moodle bootstrap or test user classes. */
final class MoodleUsermanagerSearchTest extends TestCase {

	private function manager(): MoodleUsermanager {
		return (new ReflectionClass(MoodleUsermanager::class))->newInstanceWithoutConstructor();
	}

	public function testImplementsFrameworkSearchContract(): void {
		$this->assertTrue(is_subclass_of(MoodleUsermanager::class, IUsermanager::class));
		$method = new ReflectionMethod(MoodleUsermanager::class, 'searchUsers');
		$this->assertTrue($method->isPublic());
		$this->assertSame('array', (string) $method->getReturnType());
		$this->assertSame(20, $method->getParameters()[1]->getDefaultValue());
		$this->assertSame(0, $method->getParameters()[2]->getDefaultValue());
	}

	public function testBlankSearchReturnsNoUsersWithoutLoadingMoodle(): void {
		$this->assertSame([], $this->manager()->searchUsers('  '));
		$this->assertSame([], $this->manager()->searchUsers("\n\t"));
	}

	public function testRejectsInvalidLimit(): void {
		foreach ([0, -1, 101] as $limit) {
			try {
				$this->manager()->searchUsers('smith', $limit);
				$this->fail('Expected an invalid pagination exception.');
			} catch (InvalidArgumentException $exception) {
				$this->assertNotSame('', $exception->getMessage());
			}
		}
	}

	public function testRejectsNegativeOffset(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->manager()->searchUsers('smith', 20, -1);
	}
}
