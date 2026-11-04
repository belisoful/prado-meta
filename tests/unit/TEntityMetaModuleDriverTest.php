<?php

use Belisoful\Prado\Util\Meta\TEntityMetaModule;
use PHPUnit\Framework\Attributes\DataProvider;
use Prado\Data\TDbConnection;
use Prado\Data\TDbDriver;
use Prado\Exceptions\TConfigurationException;

require_once(__DIR__ . '/../test_tools/MetaTestTools.php');

/**
 * The module against each database it writes SQL for. SQLite always runs. PostgreSQL and MySQL
 * run when their DSN is in the environment, and are skipped otherwise, unless
 * META_REQUIRE_BACKENDS is set, which makes a missing backend a failure (CI sets it):
 *
 *     META_PGSQL_DSN="pgsql:host=127.0.0.1;dbname=prado_meta_test" META_PGSQL_USER=... META_PGSQL_PASSWORD=...
 *     META_MYSQL_DSN="mysql:host=127.0.0.1;dbname=prado_meta_test" META_MYSQL_USER=... META_MYSQL_PASSWORD=...
 *
 * Each test works in tables of its own, dropped afterwards.
 */
class TEntityMetaModuleDriverTest extends PHPUnit\Framework\TestCase
{
	/** @var array<int, array{0: TDbConnection, 1: string}> connection, table to drop after the test */
	private array $_tables = [];

	/** @var TEntityMetaModule[] modules kept alive until tearDown: a destroyed module closes its connection */
	private array $_modules = [];

	/**
	 * Rows come back in the database's order, which differs between them.
	 * @param array $expected the expected key => value pairs
	 * @param array $actual the pairs read
	 */
	private function assertSameUnordered(array $expected, array $actual): void
	{
		ksort($expected, SORT_STRING);
		ksort($actual, SORT_STRING);
		$this->assertSame($expected, $actual);
	}

	public static function backends(): array
	{
		return [
			'sqlite' => [TDbDriver::DRIVER_SQLITE],
			'pgsql' => [TDbDriver::DRIVER_PGSQL],
			'mysql' => [TDbDriver::DRIVER_MYSQL],
		];
	}

	protected function tearDown(): void
	{
		foreach ($this->_tables as [$connection, $table]) {
			try {
				$connection->createCommand('DROP TABLE IF EXISTS ' . $table)->execute();
			} catch (\Throwable $e) {
				// The connection is gone; an in-memory database went with it.
			}
		}
		$this->_tables = [];
		$this->_modules = [];
	}

	private function connect(string $driver): TDbConnection
	{
		if ($driver === TDbDriver::DRIVER_SQLITE) {
			return MetaTestTools::createConnection();
		}
		$prefix = 'META_' . strtoupper($driver) . '_';
		$dsn = (string) getenv($prefix . 'DSN');
		if ($dsn === '') {
			if (getenv('META_REQUIRE_BACKENDS')) {
				$this->fail("{$prefix}DSN is not set and META_REQUIRE_BACKENDS requires the {$driver} backend");
			}
			$this->markTestSkipped("{$prefix}DSN is not set");
		}
		$connection = new TDbConnection($dsn, (string) getenv($prefix . 'USER'), (string) getenv($prefix . 'PASSWORD'));
		$connection->setActive(true);

		return $connection;
	}

	/**
	 * @param string $driver the backend
	 * @param array $properties module properties, as name => value
	 * @param null|TDbConnection $connection a connection to share, or null for a new one
	 * @return TestEntityMetaModule an initialized module on a table of this test's own
	 */
	private function module(string $driver, array $properties = [], ?TDbConnection $connection = null): TEntityMetaModule
	{
		$connection ??= $this->connect($driver);
		$properties['TableName'] ??= 'meta_' . bin2hex(random_bytes(6));
		$this->_tables[] = [$connection, $properties['TableName']];
		$module = MetaTestTools::createEntityModule($properties, $connection);
		$this->_modules[] = $module;

		return $module;
	}

	#[DataProvider('backends')]
	public function testValuesRoundTripWithAutoload(string $driver)
	{
		$module = $this->module($driver);
		$values = ['s' => 'text', 'i' => 42, 'f' => 0.1 + 0.2, 'inf' => INF, 't' => true, 'n' => null, 'a' => ['x' => [1, 2]]];
		foreach ($values as $key => $value) {
			$module->setMeta('7', $key, $value);
		}
		$module->setMeta('7', 'later', 'on demand', false);
		$module->flushMetaCache();

		foreach ($values as $key => $value) {
			$this->assertSame($value, $module->getMeta('7', $key), "{$key} on {$driver}");
		}
		$this->assertSame('on demand', $module->getMeta('7', 'later'));
		$this->assertSameUnordered($values + ['later' => 'on demand'], $module->getAllMeta('7'));
	}

	#[DataProvider('backends')]
	public function testTheFirstReadLoadsOnlyTheAutoloadedRows(string $driver)
	{
		$module = $this->module($driver);
		$module->setMeta('7', 'small', 'loaded', true);
		$module->setMeta('7', 'large', 'on demand', false);
		$module->flushMetaCache();

		$this->assertSame('loaded', $module->getMeta('7', 'small'));
		MetaTestTools::deleteRowDirectly($module, '7', 'small');
		MetaTestTools::deleteRowDirectly($module, '7', 'large');
		$this->assertSame('loaded', $module->getMeta('7', 'small'), 'the autoloaded row came with the first read');
		$this->assertNull($module->getMeta('7', 'large'), 'the other row was left for a query of its own');
	}

	#[DataProvider('backends')]
	public function testWritingAKeyTwiceUpdatesTheRow(string $driver)
	{
		$module = $this->module($driver);
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'colour', 'blue', false);
		$count = $module->getTestDbConnection()->createCommand('SELECT COUNT(*) FROM ' . $module->getTableName())->queryScalar();

		$this->assertSame(1, (int) $count);
		$module->flushMetaCache();
		$this->assertSame('blue', $module->getMeta('7', 'colour'));
	}

	#[DataProvider('backends')]
	public function testKeysAreCaseSensitive(string $driver)
	{
		// MySQL's default collation would make these one row, and the cache two.
		$module = $this->module($driver);
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'Colour', 'red');
		$module->setMeta('7', 'colour ', 'spaced');
		$module->flushMetaCache();

		$this->assertSameUnordered(['colour' => 'green', 'Colour' => 'red', 'colour ' => 'spaced'], $module->getAllMeta('7'));
	}

	#[DataProvider('backends')]
	public function testAQuotedTableNameIsCreated(string $driver)
	{
		$quote = $driver === TDbDriver::DRIVER_MYSQL ? '`' : '"';
		$name = 'meta ' . bin2hex(random_bytes(4));
		$module = $this->module($driver, ['TableName' => $quote . $name . $quote]);
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'colour', 'blue');
		$module->flushMetaCache();

		$this->assertSame(['colour' => 'blue'], $module->getAllMeta('7'));
	}

	#[DataProvider('backends')]
	public function testAMissingTableWithoutAutoCreateIsAConfigurationError(string $driver)
	{
		$module = $this->module($driver, ['AutoCreateTable' => false]);
		$this->expectException(TConfigurationException::class);
		$module->getMeta('7', 'colour');
	}

	/**
	 * A PostgreSQL transaction fails as a whole once one statement fails, so finding the table
	 * missing must not be a failing statement.
	 */
	#[DataProvider('backends')]
	public function testTheTableIsCreatedInsideTheCallersTransaction(string $driver)
	{
		if ($driver === TDbDriver::DRIVER_MYSQL) {
			$this->markTestSkipped('MySQL commits an open transaction before it creates a table');
		}
		$module = $this->module($driver);
		$connection = $module->getTestDbConnection();
		$transaction = $connection->beginTransaction();
		$module->setMeta('7', 'colour', 'green');
		$transaction->commit();

		$module->flushMetaCache();
		$this->assertSame('green', $module->getMeta('7', 'colour'));
	}

	#[DataProvider('backends')]
	public function testARolledBackWriteLeavesTheCache(string $driver)
	{
		$module = $this->module($driver);
		$module->setMeta('7', 'colour', 'green');
		$transaction = $module->getTestDbConnection()->beginTransaction();
		$module->setMeta('7', 'colour', 'red');
		$transaction->rollBack();

		$this->assertSame('green', $module->getMeta('7', 'colour'));
	}

	/**
	 * A hand-made table may hold the flag in an integer column rather than the BOOLEAN the
	 * module creates on PostgreSQL; both are written and read.
	 */
	#[DataProvider('backends')]
	public function testAnIntegerAutoloadColumnWorks(string $driver)
	{
		$connection = $this->connect($driver);
		$table = 'meta_' . bin2hex(random_bytes(6));
		$connection->createCommand('CREATE TABLE ' . $table . ' (entity_id VARCHAR(128) NOT NULL, meta_key VARCHAR(191) NOT NULL,'
			. ' meta_value TEXT, meta_type VARCHAR(8), autoload INTEGER NOT NULL DEFAULT 1)')->execute();
		$connection->createCommand('CREATE UNIQUE INDEX ' . $table . '_ek ON ' . $table . ' (entity_id, meta_key)')->execute();
		$module = $this->module($driver, ['TableName' => $table], $connection);

		$module->setMeta('7', 'small', 'loaded');
		$module->setMeta('7', 'large', 'on demand', false);
		$module->flushMetaCache();
		$this->assertSameUnordered(['small' => 'loaded', 'large' => 'on demand'], $module->getAllMeta('7'));
		$module->flushMetaCache();
		$this->assertSame('loaded', $module->getMeta('7', 'small'));
	}

	public function testMysqlWritesWithoutDeprecationWarnings()
	{
		$module = $this->module(TDbDriver::DRIVER_MYSQL);
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'colour', 'blue');
		$warnings = $module->getTestDbConnection()->createCommand('SHOW WARNINGS')->queryAll();

		$this->assertSame([], $warnings);
	}
}
