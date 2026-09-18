<?php

/**
 * TEntityMetaModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-meta
 * @license https://github.com/belisoful/prado-meta/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\Meta;

use PDO;
use Prado\Data\TDbDriver;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TPropertyValue;
use Prado\Util\TDbModule;
use Prado\Util\Traits\TInitializedTrait;

/**
 * TEntityMetaModule class.
 *
 * Stores arbitrary keyed values against an entity -- a user, a post, a tag -- in a table of its
 * own. One instance serves one entity type, and a second instance configured with another table
 * serves the next, so `user_meta` and `post_meta` share this implementation without sharing
 * rows:
 *
 * ```xml
 * <module id="post-meta" class="TEntityMetaModule" ConnectionID="db"
 *         TableName="post_meta" EntityIdField="post_id" />
 * ```
 *
 * A single table with an entity-type column would put that column in every index and make each
 * lookup pay for every other entity type's rows; separate tables keep the indexes narrow and let
 * one entity's meta grow without slowing another's.
 *
 * Values keep their type. A value is stored with a type code in {@see setTypeField TypeField},
 * so an int returns as an int and an array as an array, rather than as the string the database
 * gave back. Setting `TypeField` to '' turns typing off for compatibility with an existing table
 * (WordPress's `usermeta`, say), and every value then returns as a string.
 *
 * Reads are served from a per-entity cache. The first read of an entity loads every row marked
 * {@see setAutoLoadField autoload} in one query; a key outside that set costs one query the first
 * time it is asked for and none afterwards. With `AutoLoadField` set to '', the first read loads
 * all of the entity's rows, and no key ever costs a second query.
 *
 * This is storage, not session state. What is written here persists until it is removed, and has
 * nothing to do with {@see \Prado\Security\TUser::getState()}, which holds session data that is
 * discarded at logout.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TEntityMetaModule extends TDbModule
{
	// Freezes the table and column properties once init() has run: they describe rows that may
	// already have been read or written, so a later change would point the cache at one table
	// and the queries at another. It also arms the ConnectionID guard TDbModule declares.
	use TInitializedTrait;

	/** The type code of a stored string. */
	public const TYPE_STRING = 's';

	/** The type code of a stored integer. */
	public const TYPE_INT = 'i';

	/** The type code of a stored float. */
	public const TYPE_FLOAT = 'f';

	/** The type code of a stored boolean. */
	public const TYPE_BOOL = 'b';

	/** The type code of a stored null. */
	public const TYPE_NULL = 'n';

	/** The type code of a stored array or object. */
	public const TYPE_SERIALIZED = 'a';

	/** Serialize arrays and objects with PHP's serialize(). */
	public const SERIALIZE_PHP = 'php';

	/** Serialize arrays and objects as JSON, which does not restore objects. */
	public const SERIALIZE_JSON = 'json';

	/** @var string the table holding the meta rows */
	private string $_tableName = 'entity_meta';

	/** @var string the column holding the entity the row belongs to */
	private string $_entityIdField = 'entity_id';

	/** @var string the column holding the key */
	private string $_keyField = 'meta_key';

	/** @var string the column holding the value */
	private string $_valueField = 'meta_value';

	/** @var string the column holding the type code, or '' when values are untyped */
	private string $_typeField = 'meta_type';

	/** @var string the column holding the autoload flag, or '' when every row is loaded at once */
	private string $_autoLoadField = 'autoload';

	/** @var string how arrays and objects are serialized */
	private string $_serializer = self::SERIALIZE_PHP;

	/** @var bool whether a missing table is created on first use */
	private bool $_autoCreateTable = true;

	/** @var bool whether the table has been checked for */
	private bool $_tableEnsured = false;

	/** @var array<string, array<string, mixed>> entity id => key => decoded value */
	private array $_cache = [];

	/** @var array<string, bool> entity id => every row of the entity is cached */
	private array $_loaded = [];

	/** @var array<string, array<string, bool>> entity id => key => the key is known to be absent */
	private array $_absent = [];

	/**
	 * Initializes the module. Once this has run, the table and column properties are frozen.
	 * @param null|array|\Prado\Xml\TXmlElement $config the module configuration
	 */
	public function init($config)
	{
		parent::init($config);
		$this->markInitialized();
	}

	/**
	 * @return string the table holding the meta rows, 'entity_meta' by default
	 */
	public function getTableName(): string
	{
		return $this->_tableName;
	}

	/**
	 * @param string $value the table holding the meta rows
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setTableName($value): void
	{
		$this->assertUninitialized('TableName');
		$this->_tableName = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the column holding the entity the row belongs to, 'entity_id' by default
	 */
	public function getEntityIdField(): string
	{
		return $this->_entityIdField;
	}

	/**
	 * @param string $value the column holding the entity the row belongs to
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setEntityIdField($value): void
	{
		$this->assertUninitialized('EntityIdField');
		$this->_entityIdField = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the column holding the key, 'meta_key' by default
	 */
	public function getKeyField(): string
	{
		return $this->_keyField;
	}

	/**
	 * @param string $value the column holding the key
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setKeyField($value): void
	{
		$this->assertUninitialized('KeyField');
		$this->_keyField = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the column holding the value, 'meta_value' by default
	 */
	public function getValueField(): string
	{
		return $this->_valueField;
	}

	/**
	 * @param string $value the column holding the value
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setValueField($value): void
	{
		$this->assertUninitialized('ValueField');
		$this->_valueField = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the column holding the type code, 'meta_type' by default, '' when values are untyped
	 */
	public function getTypeField(): string
	{
		return $this->_typeField;
	}

	/**
	 * Sets the column holding the type code. An empty value stores every value as a string, for
	 * an existing table that has no such column.
	 * @param string $value the column holding the type code
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setTypeField($value): void
	{
		$this->assertUninitialized('TypeField');
		$this->_typeField = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the column holding the autoload flag, 'autoload' by default, '' when every row loads at once
	 */
	public function getAutoLoadField(): string
	{
		return $this->_autoLoadField;
	}

	/**
	 * Sets the column holding the autoload flag. An empty value loads all of an entity's rows on
	 * first read, which suits an entity with few rows.
	 * @param string $value the column holding the autoload flag
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setAutoLoadField($value): void
	{
		$this->assertUninitialized('AutoLoadField');
		$this->_autoLoadField = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string how arrays and objects are serialized, {@see SERIALIZE_PHP} by default
	 */
	public function getSerializer(): string
	{
		return $this->_serializer;
	}

	/**
	 * @param string $value how arrays and objects are serialized, php or json
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value names no known serializer.
	 */
	public function setSerializer($value): void
	{
		$this->assertUninitialized('Serializer');
		$value = TPropertyValue::ensureString($value);
		if ($value !== self::SERIALIZE_PHP && $value !== self::SERIALIZE_JSON) {
			throw new TInvalidDataValueException('meta_serializer_invalid', $value);
		}
		$this->_serializer = $value;
	}

	/**
	 * @return bool whether a missing table is created on first use, true by default
	 */
	public function getAutoCreateTable(): bool
	{
		return $this->_autoCreateTable;
	}

	/**
	 * @param bool $value whether a missing table is created on first use
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setAutoCreateTable($value): void
	{
		$this->assertUninitialized('AutoCreateTable');
		$this->_autoCreateTable = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Reads one value.
	 * @param mixed $entityId the entity the value belongs to
	 * @param string $key the key
	 * @param mixed $default what to return when the key is not stored
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId or $key is empty.
	 * @return mixed the stored value, in the type it was stored as, or $default
	 */
	public function getMeta($entityId, string $key, $default = null)
	{
		$entityId = $this->ensureEntityId($entityId);
		$this->ensureKey($key);
		$this->ensureLoaded($entityId);

		if (array_key_exists($key, $this->_cache[$entityId])) {
			return $this->_cache[$entityId][$key];
		}
		if (($this->_loaded[$entityId] ?? false) || isset($this->_absent[$entityId][$key])) {
			return $default;
		}

		$row = $this->queryRow($entityId, $key);
		if ($row === null) {
			$this->_absent[$entityId][$key] = true;

			return $default;
		}
		$this->_cache[$entityId][$key] = $this->decodeValue($row['value'], $row['type']);

		return $this->_cache[$entityId][$key];
	}

	/**
	 * Writes one value, inserting the row or updating the one already there.
	 * @param mixed $entityId the entity the value belongs to
	 * @param string $key the key
	 * @param mixed $value the value, of any type the serializer can carry
	 * @param bool $autoLoad whether the row loads with the entity's first read
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId or $key is empty.
	 */
	public function setMeta($entityId, string $key, $value, bool $autoLoad = true): void
	{
		$entityId = $this->ensureEntityId($entityId);
		$this->ensureKey($key);
		$this->ensureTable();

		[$stored, $type] = $this->encodeValue($value);
		$db = $this->getDbConnection();
		$table = $this->getTableName();
		$columns = [$this->getEntityIdField(), $this->getKeyField(), $this->getValueField()];
		$values = [':entity', ':key', ':value'];
		$updates = [$this->getValueField()];
		if ($this->getTypeField() !== '') {
			$columns[] = $this->getTypeField();
			$values[] = ':type';
			$updates[] = $this->getTypeField();
		}
		if ($this->getAutoLoadField() !== '') {
			$columns[] = $this->getAutoLoadField();
			$values[] = ':autoload';
			$updates[] = $this->getAutoLoadField();
		}

		$sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
		$driver = $db->getDriverName();
		if ($driver === TDbDriver::DRIVER_MYSQL) {
			$assignments = array_map(fn (string $column) => $column . ' = VALUES(' . $column . ')', $updates);
			$sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
		} elseif ($driver === TDbDriver::DRIVER_SQLITE || $driver === TDbDriver::DRIVER_PGSQL) {
			// SQLite (3.24+) and PostgreSQL take the same upsert.
			$assignments = array_map(fn (string $column) => $column . ' = excluded.' . $column, $updates);
			$sql .= ' ON CONFLICT (' . $this->getEntityIdField() . ', ' . $this->getKeyField() . ')'
				. ' DO UPDATE SET ' . implode(', ', $assignments);
		} else {
			// Any other driver has no upsert, so the old row goes first and the insert stands
			// on its own.
			$this->deleteRow($entityId, $key);
		}

		$command = $db->createCommand($sql);
		$command->bindValue(':entity', $entityId, PDO::PARAM_STR);
		$command->bindValue(':key', $key, PDO::PARAM_STR);
		$command->bindValue(':value', $stored, PDO::PARAM_STR);
		if ($this->getTypeField() !== '') {
			$command->bindValue(':type', $type, PDO::PARAM_STR);
		}
		if ($this->getAutoLoadField() !== '') {
			$command->bindValue(':autoload', $autoLoad ? 1 : 0, PDO::PARAM_INT);
		}
		$command->execute();

		$this->_cache[$entityId][$key] = $this->getTypeField() === '' ? $stored : $value;
		unset($this->_absent[$entityId][$key]);
	}

	/**
	 * Removes one value. Removing a key that is not stored does nothing.
	 * @param mixed $entityId the entity the value belongs to
	 * @param string $key the key
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId or $key is empty.
	 */
	public function unsetMeta($entityId, string $key): void
	{
		$entityId = $this->ensureEntityId($entityId);
		$this->ensureKey($key);
		$this->ensureTable();
		$this->deleteRow($entityId, $key);

		unset($this->_cache[$entityId][$key]);
		$this->_absent[$entityId][$key] = true;
	}

	/**
	 * @param mixed $entityId the entity the value belongs to
	 * @param string $key the key
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId or $key is empty.
	 * @return bool whether the key is stored for the entity
	 */
	public function existsMeta($entityId, string $key): bool
	{
		$entityId = $this->ensureEntityId($entityId);
		$this->ensureKey($key);
		$this->ensureLoaded($entityId);

		if (array_key_exists($key, $this->_cache[$entityId])) {
			return true;
		}
		if (($this->_loaded[$entityId] ?? false) || isset($this->_absent[$entityId][$key])) {
			return false;
		}

		$row = $this->queryRow($entityId, $key);
		if ($row === null) {
			$this->_absent[$entityId][$key] = true;

			return false;
		}
		$this->_cache[$entityId][$key] = $this->decodeValue($row['value'], $row['type']);

		return true;
	}

	/**
	 * Reads every value stored for an entity, in one query.
	 * @param mixed $entityId the entity to read
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId is empty.
	 * @return array<string, mixed> key => value, empty when the entity has no rows
	 */
	public function getAllMeta($entityId): array
	{
		$entityId = $this->ensureEntityId($entityId);
		if (!($this->_loaded[$entityId] ?? false)) {
			$this->loadRows($entityId, false);
		}

		return $this->_cache[$entityId];
	}

	/**
	 * Drops what has been cached, so the next read goes back to the database. Call this after
	 * writing rows by some other route, such as a bulk update.
	 * @param mixed $entityId the entity to forget, or null to forget every entity
	 */
	public function flushMetaCache($entityId = null): void
	{
		if ($entityId === null) {
			$this->_cache = [];
			$this->_loaded = [];
			$this->_absent = [];

			return;
		}
		$entityId = (string) $entityId;
		unset($this->_cache[$entityId], $this->_loaded[$entityId], $this->_absent[$entityId]);
	}

	/**
	 * Loads the entity's autoloaded rows, once per entity.
	 * @param string $entityId the entity to load
	 */
	protected function ensureLoaded(string $entityId): void
	{
		if (isset($this->_cache[$entityId])) {
			return;
		}
		// Without an autoload column there is nothing to select on, so the whole entity comes
		// back at once and no key can need a query of its own afterwards.
		$this->loadRows($entityId, $this->getAutoLoadField() !== '');
	}

	/**
	 * Reads an entity's rows into the cache in one query.
	 * @param string $entityId the entity to load
	 * @param bool $autoLoadOnly whether to limit the read to the rows marked autoload
	 */
	protected function loadRows(string $entityId, bool $autoLoadOnly): void
	{
		$this->ensureTable();
		$this->_cache[$entityId] ??= [];

		$type = $this->getTypeField() !== '' ? ', ' . $this->getTypeField() . ' AS type' : '';
		$sql = 'SELECT ' . $this->getKeyField() . ' AS mkey, ' . $this->getValueField() . ' AS value' . $type
			. ' FROM ' . $this->getTableName() . ' WHERE ' . $this->getEntityIdField() . ' = :entity';
		if ($autoLoadOnly) {
			$sql .= ' AND ' . $this->getAutoLoadField() . ' <> 0';
		}
		$command = $this->getDbConnection()->createCommand($sql);
		$command->bindValue(':entity', $entityId, PDO::PARAM_STR);
		foreach ($command->query()->readAll() as $row) {
			$this->_cache[$entityId][$row['mkey']] = $this->decodeValue($row['value'], $row['type'] ?? null);
		}
		if (!$autoLoadOnly) {
			$this->_loaded[$entityId] = true;
			$this->_absent[$entityId] = [];
		}
	}

	/**
	 * Reads one row.
	 * @param string $entityId the entity the row belongs to
	 * @param string $key the key
	 * @return null|array the row as value and type, or null when there is none
	 */
	protected function queryRow(string $entityId, string $key): ?array
	{
		$this->ensureTable();
		$type = $this->getTypeField() !== '' ? ', ' . $this->getTypeField() . ' AS type' : '';
		$sql = 'SELECT ' . $this->getValueField() . ' AS value' . $type . ' FROM ' . $this->getTableName()
			. ' WHERE ' . $this->getEntityIdField() . ' = :entity AND ' . $this->getKeyField() . ' = :key';
		$command = $this->getDbConnection()->createCommand($sql);
		$command->bindValue(':entity', $entityId, PDO::PARAM_STR);
		$command->bindValue(':key', $key, PDO::PARAM_STR);
		$row = $command->query()->read();

		return $row === false ? null : ['value' => $row['value'], 'type' => $row['type'] ?? null];
	}

	/**
	 * Deletes one row.
	 * @param string $entityId the entity the row belongs to
	 * @param string $key the key
	 */
	protected function deleteRow(string $entityId, string $key): void
	{
		$sql = 'DELETE FROM ' . $this->getTableName() . ' WHERE ' . $this->getEntityIdField()
			. ' = :entity AND ' . $this->getKeyField() . ' = :key';
		$command = $this->getDbConnection()->createCommand($sql);
		$command->bindValue(':entity', $entityId, PDO::PARAM_STR);
		$command->bindValue(':key', $key, PDO::PARAM_STR);
		$command->execute();
	}

	/**
	 * Turns a value into what the row holds.
	 * @param mixed $value the value to store
	 * @return array the stored text and its type code
	 */
	protected function encodeValue($value): array
	{
		if ($value === null) {
			return ['', self::TYPE_NULL];
		}
		if (is_bool($value)) {
			return [$value ? '1' : '0', self::TYPE_BOOL];
		}
		if (is_int($value)) {
			return [(string) $value, self::TYPE_INT];
		}
		if (is_float($value)) {
			// json_encode round-trips a float exactly, where a string cast drops digits.
			return [(string) json_encode($value), self::TYPE_FLOAT];
		}
		if (is_string($value)) {
			return [$value, self::TYPE_STRING];
		}

		$serialized = $this->getSerializer() === self::SERIALIZE_JSON
			? (string) json_encode($value, JSON_UNESCAPED_UNICODE)
			: serialize($value);

		return [$serialized, self::TYPE_SERIALIZED];
	}

	/**
	 * Turns what the row holds back into a value.
	 * @param null|string $stored the stored text
	 * @param null|string $type the type code, or null when the table carries no type column
	 * @return mixed the value, as a string when there is no type code
	 */
	protected function decodeValue(?string $stored, ?string $type)
	{
		if ($type === null || $this->getTypeField() === '') {
			return $stored;
		}

		return match ($type) {
			self::TYPE_NULL => null,
			self::TYPE_BOOL => $stored === '1',
			self::TYPE_INT => (int) $stored,
			self::TYPE_FLOAT => (float) $stored,
			self::TYPE_SERIALIZED => $this->getSerializer() === self::SERIALIZE_JSON
				? json_decode((string) $stored, true)
				: @unserialize((string) $stored),
			default => $stored,
		};
	}

	/**
	 * @param mixed $entityId the entity to check
	 * @throws \Prado\Exceptions\TInvalidDataValueException when the entity is empty.
	 * @return string the entity as the column holds it
	 */
	protected function ensureEntityId($entityId): string
	{
		$entityId = (string) $entityId;
		if ($entityId === '') {
			throw new TInvalidDataValueException('meta_entity_id_required', $this->getTableName());
		}

		return $entityId;
	}

	/**
	 * @param string $key the key to check
	 * @throws \Prado\Exceptions\TInvalidDataValueException when the key is empty.
	 */
	protected function ensureKey(string $key): void
	{
		if ($key === '') {
			throw new TInvalidDataValueException('meta_key_required', $this->getTableName());
		}
	}

	/**
	 * Checks for the table, creating it when it is missing and {@see getAutoCreateTable
	 * AutoCreateTable} allows.
	 * @throws \Prado\Exceptions\TConfigurationException when the table is missing and cannot be created.
	 */
	protected function ensureTable(): void
	{
		if ($this->_tableEnsured) {
			return;
		}
		$this->_tableEnsured = true;
		$db = $this->getDbConnection();
		try {
			$db->createCommand('SELECT * FROM ' . $this->getTableName() . ' WHERE 0=1')->query()->close();
		} catch (\Exception $e) {
			if (!$this->getAutoCreateTable()) {
				throw new TConfigurationException('meta_table_nonexistent', $this->getTableName());
			}
			$this->createDbTable();
		}
	}

	/**
	 * Creates the meta table and its indexes. The unique index over entity and key is what the
	 * upsert in {@see setMeta} conflicts against, so a hand-made table needs it too.
	 */
	protected function createDbTable(): void
	{
		$db = $this->getDbConnection();
		$driver = $db->getDriverName();
		$table = $this->getTableName();

		$autoIdAttributes = '';
		$autoType = 'INTEGER';
		$textType = 'MEDIUMTEXT';
		$boolDefault = '1';
		switch ($driver) {
			case TDbDriver::DRIVER_SQLITE:
				$autoIdAttributes = ' AUTOINCREMENT';
				break;
			case TDbDriver::DRIVER_PGSQL:
				$autoType = 'SERIAL';
				$textType = 'TEXT';
				$boolDefault = 'TRUE';
				break;
			default:	// mysql
				$autoIdAttributes = ' AUTO_INCREMENT';
				break;
		}

		$columns = [
			'meta_id ' . $autoType . ' PRIMARY KEY' . $autoIdAttributes,
			$this->getEntityIdField() . ' VARCHAR(128) NOT NULL',
			$this->getKeyField() . ' VARCHAR(191) NOT NULL',
			$this->getValueField() . ' ' . $textType,
		];
		if ($this->getTypeField() !== '') {
			$columns[] = $this->getTypeField() . " VARCHAR(8) NOT NULL DEFAULT '" . self::TYPE_STRING . "'";
		}
		if ($this->getAutoLoadField() !== '') {
			$columns[] = $this->getAutoLoadField() . ' BOOLEAN NOT NULL DEFAULT ' . $boolDefault;
		}

		$db->createCommand('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')')->execute();
		// An index name is unique across the database in SQLite and PostgreSQL, so it carries the
		// table name: two instances of this module must not collide.
		$db->createCommand('CREATE UNIQUE INDEX ' . $table . '_entity_key ON ' . $table
			. ' (' . $this->getEntityIdField() . ', ' . $this->getKeyField() . ')')->execute();
		if ($this->getAutoLoadField() !== '') {
			$db->createCommand('CREATE INDEX ' . $table . '_entity_autoload ON ' . $table
				. ' (' . $this->getEntityIdField() . ', ' . $this->getAutoLoadField() . ')')->execute();
		}
	}
}
