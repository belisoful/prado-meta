<?php

use Belisoful\Prado\Util\Meta\TEntityMetaModule;
use Prado\Exceptions\TDbException;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TInvalidOperationException;

require_once(__DIR__ . '/../test_tools/MetaTestTools.php');

class TEntityMetaModuleTest extends PHPUnit\Framework\TestCase
{
	public function testDefaultsDescribeAGenericEntityTable()
	{
		$module = new TestEntityMetaModule();
		$this->assertSame('entity_meta', $module->getTableName());
		$this->assertSame('entity_id', $module->getEntityIdField());
		$this->assertSame('meta_key', $module->getKeyField());
		$this->assertSame('meta_value', $module->getValueField());
		$this->assertSame('meta_type', $module->getTypeField());
		$this->assertSame('autoload', $module->getAutoLoadField());
		$this->assertSame(TEntityMetaModule::SERIALIZE_PHP, $module->getSerializer());
		$this->assertTrue($module->getAutoCreateTable());
	}

	public function testSerializerRejectsAnUnknownName()
	{
		$module = new TestEntityMetaModule();
		$this->expectException(TInvalidDataValueException::class);
		$this->expectExceptionMessage("'yaml' is not a meta serializer");
		$module->setSerializer('yaml');
	}

	/**
	 * The table and columns are written into every query as given, so a value that is not an SQL
	 * identifier would be SQL of the caller's choosing.
	 */
	public function testTableAndColumnsMustBeSqlIdentifiers()
	{
		foreach (['TableName', 'EntityIdField', 'KeyField', 'ValueField', 'TypeField', 'AutoLoadField'] as $property) {
			$module = new TestEntityMetaModule();
			try {
				$module->{'set' . $property}('meta; DROP TABLE users');
				$this->fail("{$property} accepted a value that is not an SQL identifier");
			} catch (TInvalidDataValueException $e) {
				$this->assertStringContainsString("'meta; DROP TABLE users'", $e->getMessage());
			}
		}
	}

	public function testTableAndColumnsAcceptQualifiedAndQuotedIdentifiers()
	{
		$module = new TestEntityMetaModule();
		$module->setTableName('app.post_meta');
		$module->setKeyField('"meta key"');
		$module->setValueField('`meta_value`');
		$this->assertSame('app.post_meta', $module->getTableName());
		$this->assertSame('"meta key"', $module->getKeyField());
		$this->assertSame('`meta_value`', $module->getValueField());
	}

	public function testTypeAndAutoLoadFieldsMayBeEmpty()
	{
		$module = new TestEntityMetaModule();
		$module->setTypeField('');
		$module->setAutoLoadField('');
		$this->assertSame('', $module->getTypeField());
		$this->assertSame('', $module->getAutoLoadField());
	}

	public function testTableAndColumnsFreezeOnceInitialized()
	{
		// The cache is keyed on rows already read; repointing the table afterwards would leave
		// the cache describing one table and the queries hitting another.
		$module = MetaTestTools::createEntityModule();
		$this->expectException(TInvalidOperationException::class);
		$module->setTableName('somewhere_else');
	}

	public function testTableIsCreatedOnFirstUse()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'colour', 'green');
		$this->assertSame('green', $module->getMeta('7', 'colour'));
	}

	public function testMissingTableWithoutAutoCreateIsAConfigurationError()
	{
		$module = MetaTestTools::createEntityModule(['AutoCreateTable' => false]);
		$this->expectException(TConfigurationException::class);
		$this->expectExceptionMessage("The meta table 'entity_meta' does not exist");
		$module->getMeta('7', 'colour');
	}

	/**
	 * The point of the type column: a value comes back as what it went in as. Without it, the
	 * database returns everything as a string and callers compare an int against "42".
	 */
	public function testValuesKeepTheirType()
	{
		$module = MetaTestTools::createEntityModule();
		$values = [
			'string' => 'a string',
			'empty-string' => '',
			'int' => 42,
			'negative-int' => -7,
			'float' => 1.25,
			'precise-float' => 0.1 + 0.2,
			'true' => true,
			'false' => false,
			'null' => null,
			'array' => ['a' => 1, 'b' => [2, 3]],
			'empty-array' => [],
		];
		foreach ($values as $key => $value) {
			$module->setMeta('7', $key, $value);
		}
		// Read through a cold cache, so the values come back from the database rather than from
		// what setMeta() kept.
		$module->flushMetaCache();
		foreach ($values as $key => $value) {
			$this->assertSame($value, $module->getMeta('7', $key), "the value of {$key} survives a round trip");
		}
	}

	public function testObjectsRoundTripThroughThePhpSerializer()
	{
		$module = MetaTestTools::createEntityModule(['AllowedClasses' => 'stdClass']);
		$module->setMeta('7', 'point', (object) ['x' => 1, 'y' => 2]);
		$module->flushMetaCache();

		$point = $module->getMeta('7', 'point');
		$this->assertIsObject($point);
		$this->assertSame(1, $point->x);
	}

	public function testTheJsonSerializerStoresArraysAsJson()
	{
		$module = MetaTestTools::createEntityModule(['Serializer' => TEntityMetaModule::SERIALIZE_JSON]);
		$module->setMeta('7', 'tags', ['a', 'b']);
		$module->flushMetaCache();
		$this->assertSame(['a', 'b'], $module->getMeta('7', 'tags'));
	}

	public function testWritingAKeyTwiceUpdatesTheRow()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'colour', 'blue');
		$module->flushMetaCache();

		$this->assertSame('blue', $module->getMeta('7', 'colour'));
		$this->assertCount(1, $module->getAllMeta('7'), 'the second write updated the row rather than adding one');
	}

	public function testAnAbsentKeyReturnsTheDefault()
	{
		$module = MetaTestTools::createEntityModule();
		$this->assertNull($module->getMeta('7', 'colour'));
		$this->assertSame('fallback', $module->getMeta('7', 'colour', 'fallback'));
	}

	public function testExistsMetaSeparatesAStoredNullFromAnAbsentKey()
	{
		// A stored null is a value; getMeta() alone cannot tell it from a missing row.
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'nothing', null);
		$module->flushMetaCache();

		$this->assertTrue($module->existsMeta('7', 'nothing'));
		$this->assertFalse($module->existsMeta('7', 'never-set'));
		$this->assertNull($module->getMeta('7', 'nothing'));
	}

	public function testUnsetMetaRemovesTheValue()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'colour', 'green');
		$module->unsetMeta('7', 'colour');

		$this->assertFalse($module->existsMeta('7', 'colour'));
		$module->flushMetaCache();
		$this->assertFalse($module->existsMeta('7', 'colour'));
	}

	public function testUnsettingAKeyThatIsNotStoredDoesNothing()
	{
		$module = MetaTestTools::createEntityModule();
		$module->unsetMeta('7', 'never-set');
		$this->assertSame([], $module->getAllMeta('7'));
	}

	public function testGetAllMetaReadsEveryKeyOfOneEntity()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'size', 3);
		$module->setMeta('8', 'colour', 'red');
		$module->flushMetaCache();

		$this->assertSame(['colour' => 'green', 'size' => 3], $module->getAllMeta('7'));
		$this->assertSame(['colour' => 'red'], $module->getAllMeta('8'));
		$this->assertSame([], $module->getAllMeta('9'));
	}

	public function testANonAutoloadValueIsStillReadable()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'small', 'loaded', true);
		$module->setMeta('7', 'large', 'on demand', false);
		$module->flushMetaCache();

		// The first read loads only the autoload rows; the other key costs its own query.
		$this->assertSame('loaded', $module->getMeta('7', 'small'));
		$this->assertSame('on demand', $module->getMeta('7', 'large'));
		$this->assertTrue($module->existsMeta('7', 'large'));
	}

	public function testReadsAreServedFromTheCache()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'colour', 'green');
		$this->assertSame('green', $module->getMeta('7', 'colour'));

		// Delete the row behind the module's back: a second read that still answers is a read
		// that never reached the database.
		MetaTestTools::deleteRowDirectly($module, '7', 'colour');
		$this->assertSame('green', $module->getMeta('7', 'colour'));

		$module->flushMetaCache();
		$this->assertNull($module->getMeta('7', 'colour'));
	}

	public function testFlushingOneEntityLeavesTheOthersCached()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('8', 'colour', 'red');
		$module->getMeta('8', 'colour');

		MetaTestTools::deleteRowDirectly($module, '7', 'colour');
		MetaTestTools::deleteRowDirectly($module, '8', 'colour');
		$module->flushMetaCache('7');

		$this->assertNull($module->getMeta('7', 'colour'), 'the flushed entity went back to the database');
		$this->assertSame('red', $module->getMeta('8', 'colour'), 'the other entity stayed cached');
	}

	public function testWithoutAnAutoloadColumnEveryRowLoadsAtOnce()
	{
		$module = MetaTestTools::createEntityModule(['AutoLoadField' => '']);
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'size', 3);
		$module->flushMetaCache();

		// The first read loads the entity whole, so a key it did not find cannot be in the table
		// and no second query is worth making.
		$this->assertSame('green', $module->getMeta('7', 'colour'));
		MetaTestTools::deleteRowDirectly($module, '7', 'size');
		$this->assertSame(3, $module->getMeta('7', 'size'));
		$this->assertFalse($module->existsMeta('7', 'never-set'));
	}

	public function testWithoutATypeColumnValuesComeBackAsStrings()
	{
		// The compatibility mode for an existing table, such as WordPress's usermeta.
		$module = MetaTestTools::createEntityModule(['TypeField' => '']);
		$module->setMeta('7', 'size', 3);
		$module->flushMetaCache();

		$this->assertSame('3', $module->getMeta('7', 'size'));
	}

	public function testTwoModulesKeepTheirTablesApart()
	{
		// The case the design is built on: one implementation, one table per entity type.
		$connection = MetaTestTools::createConnection();
		$users = new TestEntityMetaModule();
		$users->setTestDbConnection($connection);
		$users->setTableName('user_meta');
		$users->setEntityIdField('user_id');
		$users->init(null);

		$posts = new TestEntityMetaModule();
		$posts->setTestDbConnection($connection);
		$posts->setTableName('post_meta');
		$posts->setEntityIdField('post_id');
		$posts->init(null);

		$users->setMeta('1', 'colour', 'green');
		$posts->setMeta('1', 'colour', 'red');

		$this->assertSame('green', $users->getMeta('1', 'colour'));
		$this->assertSame('red', $posts->getMeta('1', 'colour'));
		$this->assertSame([], $posts->getAllMeta('2'));
	}

	public function testAnEmptyEntityIdIsRejected()
	{
		$module = MetaTestTools::createEntityModule();
		$this->expectException(TInvalidDataValueException::class);
		$this->expectExceptionMessage("Meta storage in 'entity_meta' requires a non-empty entity id.");
		$module->getMeta('', 'colour');
	}

	public function testAnEmptyKeyIsRejected()
	{
		$module = MetaTestTools::createEntityModule();
		$this->expectException(TInvalidDataValueException::class);
		$this->expectExceptionMessage("Meta storage in 'entity_meta' requires a non-empty key.");
		$module->setMeta('7', '', 'green');
	}

	public function testANumericEntityIdAndItsStringAreTheSameEntity()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta(7, 'colour', 'green');
		$this->assertSame('green', $module->getMeta('7', 'colour'));
	}

	/**
	 * A value written into the table by some other route must not choose what is instantiated
	 * when it is read.
	 */
	public function testObjectsAreNotRestoredByDefault()
	{
		$connection = MetaTestTools::createConnection();
		$module = MetaTestTools::createEntityModule([], $connection);
		$module->setMeta('7', 'list', [1]);
		$connection->createCommand("UPDATE entity_meta SET meta_value = 'O:8:\"stdClass\":1:{s:1:\"p\";i:1;}'")->execute();
		$module->flushMetaCache();

		$this->assertInstanceOf(__PHP_Incomplete_Class::class, $module->getMeta('7', 'list'));
		$this->assertFalse($module->getAllowedClasses());
	}

	public function testAllowedClassesAcceptsTheUsualForms()
	{
		$cases = [
			['*', true],
			['true', true],
			[true, true],
			['', false],
			['false', false],
			[false, false],
			[[], false],
			['stdClass, \\ArrayObject', ['stdClass', 'ArrayObject']],
			[['stdClass', ' '], ['stdClass']],
		];
		foreach ($cases as [$value, $expected]) {
			$module = new TestEntityMetaModule();
			$module->setAllowedClasses($value);
			$this->assertSame($expected, $module->getAllowedClasses(), var_export($value, true));
		}
	}

	public function testNonFiniteFloatsRoundTrip()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta('7', 'inf', INF);
		$module->setMeta('7', 'neg-inf', -INF);
		$module->setMeta('7', 'nan', NAN);
		$this->assertNan($module->getMeta('7', 'nan'), 'the cached read');
		$module->flushMetaCache();

		$this->assertSame(INF, $module->getMeta('7', 'inf'));
		$this->assertSame(-INF, $module->getMeta('7', 'neg-inf'));
		$this->assertNan($module->getMeta('7', 'nan'));
	}

	public function testAValueJsonCannotEncodeIsRefused()
	{
		$module = MetaTestTools::createEntityModule(['Serializer' => TEntityMetaModule::SERIALIZE_JSON]);
		try {
			$module->setMeta('7', 'bytes', ["a\xB1"]);
			$this->fail('an array JSON cannot encode was stored');
		} catch (TInvalidDataValueException $e) {
			$this->assertStringContainsString("Meta value 'bytes' in 'entity_meta' cannot be encoded as JSON", $e->getMessage());
		}
		$this->assertFalse($module->existsMeta('7', 'bytes'), 'nothing was written');
	}

	public function testCachedAndColdReadsAgree()
	{
		// JSON does not restore objects, so the cached read must not either.
		$module = MetaTestTools::createEntityModule(['Serializer' => TEntityMetaModule::SERIALIZE_JSON]);
		$module->setMeta('7', 'point', (object) ['x' => 1]);
		$warm = $module->getMeta('7', 'point');
		$module->flushMetaCache();

		$this->assertSame(['x' => 1], $warm);
		$this->assertSame($warm, $module->getMeta('7', 'point'));
	}

	public function testTheCacheHoldsACopyOfAStoredObject()
	{
		$module = MetaTestTools::createEntityModule(['AllowedClasses' => '*']);
		$point = (object) ['x' => 1];
		$module->setMeta('7', 'point', $point);
		$point->x = 99;

		$this->assertSame(1, $module->getMeta('7', 'point')->x, 'changing the object after the write does not change the cached value');
	}

	public function testChangingTheSerializerKeepsExistingRowsReadable()
	{
		$connection = MetaTestTools::createConnection();
		$php = MetaTestTools::createEntityModule([], $connection);
		$php->setMeta('7', 'from-php', ['a' => 1]);
		$json = MetaTestTools::createEntityModule(['Serializer' => TEntityMetaModule::SERIALIZE_JSON], $connection);
		$json->setMeta('7', 'from-json', ['b' => 2]);

		$this->assertSame(['a' => 1], $json->getMeta('7', 'from-php'));
		$php->flushMetaCache();
		$this->assertSame(['b' => 2], $php->getMeta('7', 'from-json'));
	}

	public function testTheLegacySerializedCodeIsReadWithTheConfiguredSerializer()
	{
		$connection = MetaTestTools::createConnection();
		$php = MetaTestTools::createEntityModule([], $connection);
		$php->setMeta('7', 'old', 'placeholder');
		$connection->createCommand("UPDATE entity_meta SET meta_type = 'a', meta_value = '" . serialize(['a' => 1]) . "'")->execute();
		$php->flushMetaCache();
		$this->assertSame(['a' => 1], $php->getMeta('7', 'old'));

		$connection->createCommand("UPDATE entity_meta SET meta_value = '{\"a\":1}'")->execute();
		$json = MetaTestTools::createEntityModule(['Serializer' => TEntityMetaModule::SERIALIZE_JSON], $connection);
		$this->assertSame(['a' => 1], $json->getMeta('7', 'old'));
	}

	public function testACorruptSerializedValueReadsAsNull()
	{
		// false would look like a stored false; a corrupt row has no value.
		$connection = MetaTestTools::createConnection();
		$module = MetaTestTools::createEntityModule([], $connection);
		$module->setMeta('7', 'list', [1]);
		$module->setMeta('7', 'tags', ['x']);
		$connection->createCommand("UPDATE entity_meta SET meta_value = 'garbage'")->execute();
		$module->flushMetaCache();
		$this->assertNull($module->getMeta('7', 'list'));

		$json = MetaTestTools::createEntityModule(['Serializer' => TEntityMetaModule::SERIALIZE_JSON], $connection);
		$connection->createCommand("UPDATE entity_meta SET meta_type = 'j'")->execute();
		$this->assertNull($json->getMeta('7', 'tags'));
	}

	public function testARolledBackWriteLeavesTheCache()
	{
		$connection = MetaTestTools::createConnection();
		$module = MetaTestTools::createEntityModule([], $connection);
		$module->setMeta('7', 'colour', 'green');

		$transaction = $connection->beginTransaction();
		$module->setMeta('7', 'colour', 'red');
		$module->setMeta('7', 'size', 3);
		$this->assertSame('red', $module->getMeta('7', 'colour'));
		$transaction->rollBack();

		$this->assertSame('green', $module->getMeta('7', 'colour'));
		$this->assertFalse($module->existsMeta('7', 'size'));
	}

	public function testACommittedWriteStaysCached()
	{
		$connection = MetaTestTools::createConnection();
		$module = MetaTestTools::createEntityModule([], $connection);
		$transaction = $connection->beginTransaction();
		$module->setMeta('7', 'colour', 'red');
		$transaction->commit();

		MetaTestTools::deleteRowDirectly($module, '7', 'colour');
		$this->assertSame('red', $module->getMeta('7', 'colour'));
	}

	public function testATableCreatedInARolledBackTransactionIsCreatedAgain()
	{
		$connection = MetaTestTools::createConnection();
		$module = MetaTestTools::createEntityModule([], $connection);
		$transaction = $connection->beginTransaction();
		$module->setMeta('7', 'colour', 'red');
		$transaction->rollBack();

		$module->setMeta('7', 'colour', 'green');
		$this->assertSame('green', $module->getMeta('7', 'colour'));
	}

	public function testAWriteDoesNotSkipTheAutoloadPreload()
	{
		$connection = MetaTestTools::createConnection();
		$seed = MetaTestTools::createEntityModule([], $connection);
		$seed->setMeta('7', 'a', 'a');
		$seed->setMeta('7', 'b', 'b');

		$module = MetaTestTools::createEntityModule([], $connection);
		$module->setMeta('7', 'z', 'z');
		$module->getMeta('7', 'a');
		// Had the first read loaded only 'a', 'b' would now come from the emptied table.
		MetaTestTools::deleteRowDirectly($module, '7', 'b');
		$this->assertSame('b', $module->getMeta('7', 'b'));
	}

	public function testAMissingTableIsReportedEveryTime()
	{
		$module = MetaTestTools::createEntityModule(['AutoCreateTable' => false]);
		foreach ([1, 2] as $attempt) {
			try {
				$module->getMeta('7', 'colour');
				$this->fail('a missing table was not reported');
			} catch (TConfigurationException $e) {
				$this->assertStringContainsString("The meta table 'entity_meta' does not exist", $e->getMessage(), "attempt {$attempt}");
			}
		}
	}

	public function testAProbeErrorThatIsNotAMissingTableIsThrown()
	{
		// The schema does not exist, so this is not "no such table", and creating would not help.
		$module = MetaTestTools::createEntityModule(['TableName' => 'nosuchschema.entity_meta']);
		$this->expectException(TDbException::class);
		$module->getMeta('7', 'colour');
	}

	public function testAQuotedTableNameIsCreated()
	{
		$module = MetaTestTools::createEntityModule(['TableName' => '"post meta"']);
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'colour', 'blue');
		$module->flushMetaCache();
		$this->assertSame(['colour' => 'blue'], $module->getAllMeta('7'));
	}

	public function testASchemaQualifiedTableNameIsCreated()
	{
		$connection = MetaTestTools::createConnection();
		$connection->createCommand("ATTACH DATABASE ':memory:' AS app")->execute();
		$module = MetaTestTools::createEntityModule(['TableName' => 'app.post_meta'], $connection);
		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'colour', 'blue');
		$module->flushMetaCache();
		$this->assertSame(['colour' => 'blue'], $module->getAllMeta('7'));
		$this->assertSame(1, (int) $connection->createCommand("SELECT COUNT(*) FROM app.sqlite_master WHERE type = 'index' AND name = 'post_meta_entity_key'")->queryScalar());
	}

	public function testAutoCreateIsRefusedOnAnUnsupportedDriver()
	{
		$module = MetaTestTools::createEntityModule([], null, TestOtherDriverEntityMetaModule::class);
		try {
			$module->setMeta('7', 'colour', 'green');
			$this->fail('a table was created with DDL the driver does not take');
		} catch (TConfigurationException $e) {
			$this->assertStringContainsString("cannot be created on the 'odbc' driver", $e->getMessage());
		}
	}

	public function testOtherDriversWriteByDeleteThenInsert()
	{
		$connection = MetaTestTools::createConnection();
		// A module closes its connection when it is destroyed, so the creator is kept alive.
		$creator = MetaTestTools::createEntityModule([], $connection);
		$creator->getMeta('7', 'colour'); // creates the table
		$module = MetaTestTools::createEntityModule([], $connection, TestOtherDriverEntityMetaModule::class);

		$module->setMeta('7', 'colour', 'green');
		$module->setMeta('7', 'colour', 'blue');
		$this->assertNull($connection->getCurrentTransaction(), 'the write closed its own transaction');

		$transaction = $connection->beginTransaction();
		$module->setMeta('7', 'size', 3);
		$this->assertSame($transaction, $connection->getCurrentTransaction(), 'the write joined the open transaction');
		$transaction->commit();

		$module->flushMetaCache();
		$this->assertSame(['colour' => 'blue', 'size' => 3], $module->getAllMeta('7'));
	}

	public function testEntityIdsMustBeStringsOrNumbers()
	{
		$module = MetaTestTools::createEntityModule();
		try {
			$module->getMeta(['7'], 'colour');
			$this->fail('an array entity id was accepted');
		} catch (TInvalidDataValueException $e) {
			$this->assertStringContainsString('requires an entity id that is a string or a number; array given', $e->getMessage());
		}

		$id = new class () implements Stringable {
			public function __toString(): string
			{
				return '7';
			}
		};
		$module->setMeta($id, 'colour', 'green');
		$this->assertSame('green', $module->getMeta(7, 'colour'));
	}

	public function testANullEntityIdIsEmpty()
	{
		$module = MetaTestTools::createEntityModule();
		$this->expectException(TInvalidDataValueException::class);
		$this->expectExceptionMessage('requires a non-empty entity id');
		$module->getMeta(null, 'colour');
	}

	public function testEntityIdsAndKeysLongerThanTheColumnsAreRefused()
	{
		$module = MetaTestTools::createEntityModule();
		$this->assertSame(128, $module->getMaxEntityIdLength());
		$this->assertSame(191, $module->getMaxKeyLength());
		$module->setMeta(str_repeat('é', 128), str_repeat('ü', 191), 'fits');

		try {
			$module->getMeta(str_repeat('e', 129), 'colour');
			$this->fail('an entity id longer than the column was accepted');
		} catch (TInvalidDataValueException $e) {
			$this->assertStringContainsString('at most 128 characters', $e->getMessage());
		}
		try {
			$module->setMeta('7', str_repeat('k', 192), 'x');
			$this->fail('a key longer than the column was accepted');
		} catch (TInvalidDataValueException $e) {
			$this->assertStringContainsString('a key of at most 191 characters', $e->getMessage());
		}
	}

	public function testALengthLimitOfZeroIsNoLimit()
	{
		$module = MetaTestTools::createEntityModule(['MaxEntityIdLength' => 0, 'MaxKeyLength' => '0']);
		$module->setMeta(str_repeat('e', 300), str_repeat('k', 300), 'stored');
		$this->assertSame('stored', $module->getMeta(str_repeat('e', 300), str_repeat('k', 300)));
	}

	public function testTheCacheIsDroppedAtTheEndOfEachRequest()
	{
		// A module in a long-running server outlives the request; the next one reads afresh.
		$application = new MetaTestApplication();
		$module = new TestEntityMetaModuleWithApplication();
		$module->setTestApplication($application);
		$module->setTestDbConnection(MetaTestTools::createConnection());
		$module->init(null);

		$module->setMeta('7', 'colour', 'green');
		MetaTestTools::deleteRowDirectly($module, '7', 'colour');
		$this->assertSame('green', $module->getMeta('7', 'colour'));
		$application->onEndRequest();
		$this->assertNull($module->getMeta('7', 'colour'));
	}
}
