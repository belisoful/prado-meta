<?php

use Belisoful\Prado\Util\Meta\TEntityMetaModule;
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
		$module->setSerializer('yaml');
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
		$module = MetaTestTools::createEntityModule();
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
		$module->getMeta('', 'colour');
	}

	public function testAnEmptyKeyIsRejected()
	{
		$module = MetaTestTools::createEntityModule();
		$this->expectException(TInvalidDataValueException::class);
		$module->setMeta('7', '', 'green');
	}

	public function testANumericEntityIdAndItsStringAreTheSameEntity()
	{
		$module = MetaTestTools::createEntityModule();
		$module->setMeta(7, 'colour', 'green');
		$this->assertSame('green', $module->getMeta('7', 'colour'));
	}
}
