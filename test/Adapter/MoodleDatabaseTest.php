<?php declare(strict_types=1);

namespace Base3MoodleTest\Adapter;

use Base3Moodle\Adapter\MoodleDatabase;
use mysqli;
use mysqli_result;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class MoodleDatabaseTest extends TestCase {

	private function databaseWithConnection(mysqli $connection): MoodleDatabase {
		$database = new MoodleDatabase();
		(new ReflectionProperty($database, 'connection'))->setValue($database, $connection);
		return $database;
	}

	public function testReturnsOriginalCaseAndDuplicateRows(): void {
		$result = $this->getMockBuilder(mysqli_result::class)
			->disableOriginalConstructor()
			->onlyMethods(['fetch_assoc', 'free'])
			->getMock();
		$result->expects($this->exactly(3))->method('fetch_assoc')
			->willReturnOnConsecutiveCalls(
				['Name' => 'first', 'Engine' => 'InnoDB'],
				['Name' => 'first', 'Engine' => 'InnoDB'],
				null
			);
		$result->expects($this->once())->method('free');

		$connection = $this->getMockBuilder(mysqli::class)
			->disableOriginalConstructor()
			->onlyMethods(['query'])
			->getMock();
		$connection->expects($this->once())->method('query')
			->with('SHOW TABLE STATUS')
			->willReturn($result);

		$rows = $this->databaseWithConnection($connection)->multiQuery('SHOW TABLE STATUS');
		$this->assertSame([
			['Name' => 'first', 'Engine' => 'InnoDB'],
			['Name' => 'first', 'Engine' => 'InnoDB'],
		], $rows);
	}

	public function testExecutesSqlWithoutRewritingQuotedJson(): void {
		$result = $this->getMockBuilder(mysqli_result::class)
			->disableOriginalConstructor()
			->onlyMethods(['fetch_row', 'free'])
			->getMock();
		$result->method('fetch_row')->willReturn(['ok']);
		$result->expects($this->once())->method('free');

		$sql = "SELECT '{\"enabled\":true,\"value\":\"x:y\"}' AS Result";
		$connection = $this->getMockBuilder(mysqli::class)
			->disableOriginalConstructor()
			->onlyMethods(['query'])
			->getMock();
		$connection->expects($this->once())->method('query')
			->with($sql)
			->willReturn($result);

		$this->assertSame('ok', $this->databaseWithConnection($connection)->scalarQuery($sql));
	}

	public function testReturnsNullForEmptyResult(): void {
		$result = $this->getMockBuilder(mysqli_result::class)
			->disableOriginalConstructor()
			->onlyMethods(['fetch_assoc', 'free'])
			->getMock();
		$result->method('fetch_assoc')->willReturn(null);
		$result->expects($this->once())->method('free');

		$connection = $this->getMockBuilder(mysqli::class)
			->disableOriginalConstructor()
			->onlyMethods(['query'])
			->getMock();
		$connection->method('query')->willReturn($result);

		$this->assertNull($this->databaseWithConnection($connection)->singleQuery('SELECT 1 WHERE FALSE'));
	}

	public function testStoresDatabaseErrorsAndClearsThemAfterSuccess(): void {
		$connection = $this->getMockBuilder(mysqli::class)
			->disableOriginalConstructor()
			->onlyMethods(['query'])
			->getMock();
		$connection->expects($this->exactly(2))->method('query')
			->willReturnCallback(static function(string $sql): bool {
				if ($sql === 'invalid statement') throw new \mysqli_sql_exception('syntax error', 1064);
				return true;
			});

		$database = $this->databaseWithConnection($connection);
		try {
			$database->nonQuery('invalid statement');
			$this->fail('Expected a query exception.');
		} catch (RuntimeException $error) {
			$this->assertStringContainsString('syntax error', $error->getMessage());
		}
		$this->assertTrue($database->isError());
		$this->assertSame(1064, $database->errorNumber());
		$database->nonQuery('CREATE TABLE test (id INT)');
		$this->assertFalse($database->isError());
	}

	public function testNestedTransactionsAndPrematureDisconnectAreRejected(): void {
		$connection = $this->getMockBuilder(mysqli::class)
			->disableOriginalConstructor()
			->onlyMethods(['begin_transaction', 'rollback', 'close'])
			->getMock();
		$connection->expects($this->once())->method('begin_transaction')->willReturn(true);
		$connection->expects($this->once())->method('rollback')->willReturn(true);
		$connection->expects($this->never())->method('close');

		$database = $this->databaseWithConnection($connection);
		$database->beginTransaction();
		try {
			$database->beginTransaction();
			$this->fail('Expected a nested transaction error.');
		} catch (RuntimeException $error) {
			$this->assertStringContainsString('Nested', $error->getMessage());
		}
		try {
			$database->disconnect();
			$this->fail('Expected an active transaction error.');
		} catch (RuntimeException $error) {
			$this->assertStringContainsString('active transaction', $error->getMessage());
		}
		$database->rollback();
	}
}
