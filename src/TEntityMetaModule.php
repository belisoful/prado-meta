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
use Prado\Data\TDbConnection;
use Prado\Data\TDbDriver;
use Prado\Data\TDbTransaction;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TDbException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
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
 * gave back. Setting `TypeField` to '' turns typing off for an existing table that has no such
 * column, and every value then returns as a string.
 *
 * The table needs a unique index over the entity and key columns: writes are upserts that
 * conflict against it. A table without one, such as WordPress's `usermeta`, which allows several
 * values per key, gains a row on every write.
 *
 * Arrays and objects are serialized with PHP's serialize(), and are restored with only the
 * classes {@see setAllowedClasses AllowedClasses} names, none by default, so a value written into
 * the table by some other route cannot instantiate an arbitrary class.
 *
 * Reads are served from a per-entity cache. The first read of an entity loads every row marked
 * {@see setAutoLoadField autoload} in one query; a key outside that set costs one query the first
 * time it is asked for and none afterwards. With `AutoLoadField` set to '', the first read loads
 * all of the entity's rows, and no key ever costs a second query. The cache is dropped when a
 * transaction on the connection rolls back and at the end of each request.
 *
 * The table and column properties are SQL identifiers, written into the queries as given, so
 * their setters reject any other value, as {@see \Prado\Util\TDbParameterModule}'s do.
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

	/**
	 * The type code of an array or object written before the serializer had a code of its own.
	 * It is read with the configured {@see setSerializer Serializer}; nothing writes it now.
	 */
	public const TYPE_SERIALIZED = 'a';

	/** The type code of an array or object serialized with PHP's serialize(). */
	public const TYPE_PHP = 'p';

	/** The type code of an array or object serialized as JSON. */
	public const TYPE_JSON = 'j';

	/** Serialize arrays and objects with PHP's serialize(). */
	public const SERIALIZE_PHP = 'php';

	/** Serialize arrays and objects as JSON, which does not restore objects. */
	public const SERIALIZE_JSON = 'json';

	/** The stored text of the float values JSON cannot carry. */
	private const NON_FINITE = ['INF' => INF, '-INF' => -INF, 'NAN' => NAN];

	/** One part of an SQL identifier, as {@see \Prado\Data\TDbPropertiesTrait::ensureSqlIdentifier()} accepts it. */
	private const IDENTIFIER_PART = '(?:[A-Za-z_][A-Za-z0-9_$]*|`[^`\x00]+`|"[^"\x00]+"|\[[^\]\x00]+\])';

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

	/** @var bool|string[] the classes unserialize() may restore: true for any, false for none */
	private bool|array $_allowedClasses = false;

	/** @var int the longest entity id accepted, in characters; 0 for no limit */
	private int $_maxEntityIdLength = 128;

	/** @var int the longest key accepted, in characters; 0 for no limit */
	private int $_maxKeyLength = 191;

	/** @var bool whether a missing table is created on first use */
	private bool $_autoCreateTable = true;

	/** @var bool whether the table is known to exist */
	private bool $_tableEnsured = false;

	/** @var array<string, array<string, mixed>> entity id => key => decoded value */
	private array $_cache = [];

	/** @var array<string, bool> entity id => the autoloaded rows of the entity are cached */
	private array $_autoLoaded = [];

	/** @var array<string, bool> entity id => every row of the entity is cached */
	private array $_loaded = [];

	/** @var array<string, array<string, bool>> entity id => key => the key is known to be absent */
	private array $_absent = [];

	/** @var null|\WeakMap<\Prado\Data\TDbTransaction, bool> the transactions whose rollback drops the cache */
	private ?\WeakMap $_watchedTransactions = null;

	/**
	 * Initializes the module. Once this has run, the table and column properties are frozen.
	 * The cache is dropped at the end of every request, so a module that outlives one, in a
	 * long-running server, does not serve one request's reads to the next.
	 * @param null|array|\Prado\Xml\TXmlElement $config the module configuration
	 */
	public function init($config)
	{
		parent::init($config);
		$this->markInitialized();

		$application = $this->getApplication();
		if ($application !== null) {
			$application->attachEventHandler('OnEndRequest', fn () => $this->flushMetaCache());
		}
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value is not an SQL identifier.
	 */
	public function setTableName($value): void
	{
		$this->assertUninitialized('TableName');
		$this->_tableName = $this->ensureSqlIdentifier(TPropertyValue::ensureString($value), 'TableName');
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value is not an SQL identifier.
	 */
	public function setEntityIdField($value): void
	{
		$this->assertUninitialized('EntityIdField');
		$this->_entityIdField = $this->ensureSqlIdentifier(TPropertyValue::ensureString($value), 'EntityIdField');
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value is not an SQL identifier.
	 */
	public function setKeyField($value): void
	{
		$this->assertUninitialized('KeyField');
		$this->_keyField = $this->ensureSqlIdentifier(TPropertyValue::ensureString($value), 'KeyField');
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value is not an SQL identifier.
	 */
	public function setValueField($value): void
	{
		$this->assertUninitialized('ValueField');
		$this->_valueField = $this->ensureSqlIdentifier(TPropertyValue::ensureString($value), 'ValueField');
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value is not an SQL identifier.
	 */
	public function setTypeField($value): void
	{
		$this->assertUninitialized('TypeField');
		$value = TPropertyValue::ensureString($value);
		$this->_typeField = $value === '' ? '' : $this->ensureSqlIdentifier($value, 'TypeField');
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
	 * first read, which suits an entity with few rows, or a table that has no such column.
	 * @param string $value the column holding the autoload flag
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value is not an SQL identifier.
	 */
	public function setAutoLoadField($value): void
	{
		$this->assertUninitialized('AutoLoadField');
		$value = TPropertyValue::ensureString($value);
		$this->_autoLoadField = $value === '' ? '' : $this->ensureSqlIdentifier($value, 'AutoLoadField');
	}

	/**
	 * @return string how arrays and objects are serialized, {@see SERIALIZE_PHP} by default
	 */
	public function getSerializer(): string
	{
		return $this->_serializer;
	}

	/**
	 * Sets how arrays and objects are written. Each row records the serializer that wrote it, so
	 * a change applies to new writes and every existing row is still read.
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
	 * @return bool|string[] the classes a PHP-serialized value may restore: true for any class,
	 *   false (the default) for none, or a list of class names
	 * @since 1.0.0
	 */
	public function getAllowedClasses(): bool|array
	{
		return $this->_allowedClasses;
	}

	/**
	 * Sets the classes a PHP-serialized value may restore, as unserialize()'s `allowed_classes`.
	 * An object of any other class comes back as `__PHP_Incomplete_Class`. Restoring any class
	 * lets whoever can write the table choose what is instantiated, so name the classes you store.
	 * @param bool|string|string[] $value true or '*' for any class; false or '' for none; or class
	 *   names, as an array or a comma-separated string
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 * @since 1.0.0
	 */
	public function setAllowedClasses($value): void
	{
		$this->assertUninitialized('AllowedClasses');
		if (is_string($value)) {
			$trimmed = strtolower(trim($value));
			if ($trimmed === '*' || $trimmed === 'true') {
				$value = true;
			} elseif ($trimmed === '' || $trimmed === 'false') {
				$value = false;
			} else {
				$value = explode(',', $value);
			}
		}
		if (is_array($value)) {
			$value = array_values(array_filter(array_map(fn ($class) => ltrim(trim((string) $class), '\\'), $value), fn ($class) => $class !== ''));
			$this->_allowedClasses = $value === [] ? false : $value;
		} else {
			$this->_allowedClasses = TPropertyValue::ensureBoolean($value);
		}
	}

	/**
	 * @return int the longest entity id accepted, in characters, 128 by default; 0 for no limit
	 * @since 1.0.0
	 */
	public function getMaxEntityIdLength(): int
	{
		return $this->_maxEntityIdLength;
	}

	/**
	 * Sets the longest entity id accepted. The default matches the column the module creates;
	 * a longer id would be truncated, or refused, by the database.
	 * @param int $value the longest entity id accepted, in characters; 0 for no limit
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 * @since 1.0.0
	 */
	public function setMaxEntityIdLength($value): void
	{
		$this->assertUninitialized('MaxEntityIdLength');
		$this->_maxEntityIdLength = max(0, TPropertyValue::ensureInteger($value));
	}

	/**
	 * @return int the longest key accepted, in characters, 191 by default; 0 for no limit
	 * @since 1.0.0
	 */
	public function getMaxKeyLength(): int
	{
		return $this->_maxKeyLength;
	}

	/**
	 * Sets the longest key accepted. The default matches the column the module creates; a longer
	 * key would be truncated, or refused, by the database, and two truncated keys would collide.
	 * @param int $value the longest key accepted, in characters; 0 for no limit
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 * @since 1.0.0
	 */
	public function setMaxKeyLength($value): void
	{
		$this->assertUninitialized('MaxKeyLength');
		$this->_maxKeyLength = max(0, TPropertyValue::ensureInteger($value));
	}

	/**
	 * @return bool whether a missing table is created on first use, true by default
	 */
	public function getAutoCreateTable(): bool
	{
		return $this->_autoCreateTable;
	}

	/**
	 * Sets whether a missing table is created on first use. MySQL commits an open transaction
	 * before it creates a table, so create the table ahead of a first write made inside one.
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId or $key is empty, or invalid.
	 * @return mixed the stored value, in the type it was stored as, or $default
	 */
	public function getMeta($entityId, string $key, $default = null)
	{
		$entityId = $this->ensureEntityId($entityId);
		$this->findValue($entityId, $key, $found);

		return $found ? $this->_cache[$entityId][$key] : $default;
	}

	/**
	 * Writes one value, inserting the row or updating the one already there.
	 * @param mixed $entityId the entity the value belongs to
	 * @param string $key the key
	 * @param mixed $value the value, of any type the serializer can carry
	 * @param bool $autoLoad whether the row loads with the entity's first read
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId or $key is empty or
	 *   invalid, or the JSON serializer cannot encode $value.
	 */
	public function setMeta($entityId, string $key, $value, bool $autoLoad = true): void
	{
		$entityId = $this->ensureEntityId($entityId);
		$this->ensureKey($key);
		$this->ensureTable();

		[$stored, $type] = $this->encodeValue($value, $key);
		$db = $this->getDbConnection();
		$driver = $this->getDriverName();
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

		$sql = 'INSERT INTO ' . $this->getTableName() . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
		$upsert = true;
		if ($driver === TDbDriver::DRIVER_MYSQL) {
			$sql .= $this->getMysqlUpsertClause($updates);
		} elseif ($driver === TDbDriver::DRIVER_SQLITE || $driver === TDbDriver::DRIVER_PGSQL) {
			// SQLite (3.24+) and PostgreSQL take the same upsert.
			$assignments = array_map(fn (string $column) => $column . ' = excluded.' . $column, $updates);
			$sql .= ' ON CONFLICT (' . $this->getEntityIdField() . ', ' . $this->getKeyField() . ')'
				. ' DO UPDATE SET ' . implode(', ', $assignments);
		} else {
			$upsert = false;
		}

		$command = $db->createCommand($sql);
		$command->bindValue(':entity', $entityId, PDO::PARAM_STR);
		$command->bindValue(':key', $key, PDO::PARAM_STR);
		$command->bindValue(':value', $stored, PDO::PARAM_STR);
		if ($this->getTypeField() !== '') {
			$command->bindValue(':type', $type, PDO::PARAM_STR);
		}
		if ($this->getAutoLoadField() !== '') {
			$this->bindAutoLoad($command, ':autoload', $autoLoad);
		}

		if ($upsert) {
			$command->execute();
		} else {
			// Any other driver has no upsert, so the old row goes first. The two statements run
			// in a transaction of their own, unless the caller's is open, so no reader sees the
			// key missing and no second writer inserts between them.
			$this->inTransaction(function () use ($entityId, $key, $command) {
				$this->deleteRow($entityId, $key);
				$command->execute();
			});
		}

		// Cache what a read from the table would return, so a cached read and a cold one agree.
		$this->_cache[$entityId][$key] = $this->decodeValue($stored, $type);
		unset($this->_absent[$entityId][$key]);
	}

	/**
	 * Removes one value. Removing a key that is not stored does nothing.
	 * @param mixed $entityId the entity the value belongs to
	 * @param string $key the key
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId or $key is empty, or invalid.
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId or $key is empty, or invalid.
	 * @return bool whether the key is stored for the entity
	 */
	public function existsMeta($entityId, string $key): bool
	{
		$this->findValue($this->ensureEntityId($entityId), $key, $found);

		return $found;
	}

	/**
	 * Reads every value stored for an entity, in one query.
	 * @param mixed $entityId the entity to read
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $entityId is empty, or invalid.
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
			$this->_autoLoaded = [];
			$this->_loaded = [];
			$this->_absent = [];

			return;
		}
		$entityId = (string) $entityId;
		unset($this->_cache[$entityId], $this->_autoLoaded[$entityId], $this->_loaded[$entityId], $this->_absent[$entityId]);
	}

	/**
	 * Finds one value, through the cache, and leaves it cached.
	 * @param string $entityId the entity the value belongs to
	 * @param string $key the key
	 * @param null|bool $found set to whether the key is stored
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $key is empty, or too long.
	 * @since 1.0.0
	 */
	protected function findValue(string $entityId, string $key, ?bool &$found): void
	{
		$this->ensureKey($key);
		$this->ensureLoaded($entityId);

		if (array_key_exists($key, $this->_cache[$entityId] ?? [])) {
			$found = true;
		} elseif (($this->_loaded[$entityId] ?? false) || isset($this->_absent[$entityId][$key])) {
			$found = false;
		} else {
			$row = $this->queryRow($entityId, $key);
			$found = $row !== null;
			if ($found) {
				$this->_cache[$entityId][$key] = $this->decodeValue($row['value'], $row['type']);
			} else {
				$this->_absent[$entityId][$key] = true;
			}
		}
	}

	/**
	 * Loads the entity's autoloaded rows, once per entity. A write does not count as a load: the
	 * entity's other autoloaded rows still arrive together on its first read.
	 * @param string $entityId the entity to load
	 */
	protected function ensureLoaded(string $entityId): void
	{
		if (isset($this->_autoLoaded[$entityId])) {
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
			$sql .= ' AND ' . $this->getAutoLoadCondition();
		}
		$command = $this->getDbConnection()->createCommand($sql);
		$command->bindValue(':entity', $entityId, PDO::PARAM_STR);
		foreach ($command->query()->readAll() as $row) {
			$this->_cache[$entityId][(string) $row['mkey']] = $this->decodeValue($row['value'], $row['type'] ?? null);
		}
		$this->_autoLoaded[$entityId] = true;
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
	 * @return string the PDO driver name of the connection
	 * @since 1.0.0
	 */
	protected function getDriverName(): string
	{
		return (string) $this->getDbConnection()->getDriverName();
	}

	/**
	 * Returns the ON DUPLICATE KEY clause of a MySQL upsert. MySQL 8.0.19 and later name the new
	 * row with an alias, and deprecate the VALUES() function MariaDB and older MySQL still need.
	 * @param string[] $updates the columns the upsert updates
	 * @return string the clause, with a leading space
	 * @since 1.0.0
	 */
	protected function getMysqlUpsertClause(array $updates): string
	{
		$version = $this->getServerVersion();
		if (stripos($version, 'mariadb') === false && version_compare($version, '8.0.19', '>=')) {
			$assignments = array_map(fn (string $column) => $column . ' = incoming.' . $column, $updates);

			return ' AS incoming ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
		}
		$assignments = array_map(fn (string $column) => $column . ' = VALUES(' . $column . ')', $updates);

		return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
	}

	/**
	 * @return string the collation of the key and entity columns of a table created on MySQL: a
	 *   binary one that does not pad, so 'Colour', 'colour', and 'colour ' are three keys, as
	 *   they are to the cache. MySQL before 8.0 has only utf8mb4_bin, which pads.
	 * @since 1.0.0
	 */
	protected function getMysqlCollation(): string
	{
		$version = $this->getServerVersion();
		if (stripos($version, 'mariadb') !== false) {
			return 'utf8mb4_nopad_bin';
		}

		return version_compare($version, '8.0.0', '>=') ? 'utf8mb4_0900_bin' : 'utf8mb4_bin';
	}

	/**
	 * @return string the server version the connection reports, '' when it reports none
	 */
	private function getServerVersion(): string
	{
		$db = $this->getDbConnection();

		return $db instanceof TDbConnection ? (string) $db->getServerVersion() : '';
	}

	/**
	 * Binds the autoload flag. PostgreSQL types a bound integer, which a BOOLEAN column refuses,
	 * so the flag goes as the text '1' or '0', which both a BOOLEAN and an integer column take.
	 * @param \Prado\Data\TDbCommand $command the command to bind on
	 * @param string $name the parameter name
	 * @param bool $autoLoad the flag
	 * @since 1.0.0
	 */
	protected function bindAutoLoad($command, string $name, bool $autoLoad): void
	{
		if ($this->getDriverName() === TDbDriver::DRIVER_PGSQL) {
			$command->bindValue($name, $autoLoad ? '1' : '0', PDO::PARAM_STR);
		} else {
			$command->bindValue($name, $autoLoad ? 1 : 0, PDO::PARAM_INT);
		}
	}

	/**
	 * @return string the condition selecting autoloaded rows. PostgreSQL compares a BOOLEAN with
	 *   no integer, so its column is cast first; the cast also takes an integer column.
	 * @since 1.0.0
	 */
	protected function getAutoLoadCondition(): string
	{
		if ($this->getDriverName() === TDbDriver::DRIVER_PGSQL) {
			return 'CAST(' . $this->getAutoLoadField() . ' AS INTEGER) <> 0';
		}

		return $this->getAutoLoadField() . ' <> 0';
	}

	/**
	 * Turns a value into what the row holds.
	 * @param mixed $value the value to store
	 * @param string $key the key the value is stored under, for the error message
	 * @throws \Prado\Exceptions\TInvalidDataValueException when the JSON serializer cannot encode $value.
	 * @return array the stored text and its type code
	 */
	protected function encodeValue($value, string $key = ''): array
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
			if (!is_finite($value)) {
				return [is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF'), self::TYPE_FLOAT];
			}
			// json_encode round-trips a float exactly, where a string cast drops digits.
			return [json_encode($value), self::TYPE_FLOAT];
		}
		if (is_string($value)) {
			return [$value, self::TYPE_STRING];
		}

		if ($this->getSerializer() === self::SERIALIZE_JSON) {
			try {
				return [json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), self::TYPE_JSON];
			} catch (\JsonException $e) {
				throw new TInvalidDataValueException('meta_value_unencodable', $this->getTableName(), $key, $e->getMessage());
			}
		}

		return [serialize($value), self::TYPE_PHP];
	}

	/**
	 * Turns what the row holds back into a value. A serialized value that cannot be read is
	 * logged as a warning and returns null.
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
			self::TYPE_FLOAT => self::NON_FINITE[(string) $stored] ?? (float) $stored,
			self::TYPE_PHP => $this->unserializeValue((string) $stored),
			self::TYPE_JSON => $this->jsonDecodeValue((string) $stored),
			self::TYPE_SERIALIZED => $this->getSerializer() === self::SERIALIZE_JSON
				? $this->jsonDecodeValue((string) $stored)
				: $this->unserializeValue((string) $stored),
			default => $stored,
		};
	}

	/**
	 * @param string $stored the PHP-serialized text
	 * @return mixed the value, restoring only the {@see getAllowedClasses AllowedClasses}, or null
	 *   when the text cannot be read
	 */
	private function unserializeValue(string $stored)
	{
		$value = @unserialize($stored, ['allowed_classes' => $this->getAllowedClasses()]);
		if ($value === false && $stored !== serialize(false)) {
			Prado::warning('A PHP-serialized meta value in ' . $this->getTableName() . ' could not be read.', static::class);

			return null;
		}

		return $value;
	}

	/**
	 * @param string $stored the JSON text
	 * @return mixed the value, objects as arrays, or null when the text cannot be read
	 */
	private function jsonDecodeValue(string $stored)
	{
		try {
			return json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			Prado::warning('A JSON meta value in ' . $this->getTableName() . ' could not be read: ' . $e->getMessage(), static::class);

			return null;
		}
	}

	/**
	 * @param mixed $entityId the entity to check: a string, a number, or a Stringable
	 * @throws \Prado\Exceptions\TInvalidDataValueException when the entity is empty, of another
	 *   type, or longer than {@see getMaxEntityIdLength MaxEntityIdLength}.
	 * @return string the entity as the column holds it
	 */
	protected function ensureEntityId($entityId): string
	{
		if ($entityId !== null && !is_scalar($entityId) && !$entityId instanceof \Stringable) {
			throw new TInvalidDataValueException('meta_entity_id_invalid', $this->getTableName(), get_debug_type($entityId));
		}
		$entityId = (string) $entityId;
		if ($entityId === '') {
			throw new TInvalidDataValueException('meta_entity_id_required', $this->getTableName());
		}
		$max = $this->getMaxEntityIdLength();
		if ($max > 0 && self::length($entityId) > $max) {
			throw new TInvalidDataValueException('meta_entity_id_too_long', $this->getTableName(), $max, $entityId);
		}

		return $entityId;
	}

	/**
	 * @param string $key the key to check
	 * @throws \Prado\Exceptions\TInvalidDataValueException when the key is empty, or longer than
	 *   {@see getMaxKeyLength MaxKeyLength}.
	 */
	protected function ensureKey(string $key): void
	{
		if ($key === '') {
			throw new TInvalidDataValueException('meta_key_required', $this->getTableName());
		}
		$max = $this->getMaxKeyLength();
		if ($max > 0 && self::length($key) > $max) {
			throw new TInvalidDataValueException('meta_key_too_long', $this->getTableName(), $max, $key);
		}
	}

	/**
	 * Checks for the table, creating it when it is missing and {@see getAutoCreateTable
	 * AutoCreateTable} allows. The table counts as present only once it has been found or made,
	 * so a failure here is reported again on the next call.
	 * @throws \Prado\Exceptions\TConfigurationException when the table is missing and cannot be created.
	 */
	protected function ensureTable(): void
	{
		$this->watchTransaction();
		if ($this->_tableEnsured) {
			return;
		}
		if (!$this->tableExists()) {
			if (!$this->getAutoCreateTable()) {
				throw new TConfigurationException('meta_table_nonexistent', $this->getTableName());
			}
			$this->createDbTable();
		}
		$this->_tableEnsured = true;
	}

	/**
	 * Reports whether the table exists. PostgreSQL is asked through to_regclass(), because a
	 * failing probe would abort a transaction the caller has open. Elsewhere a probe query
	 * runs, and only a "no such table" error counts as missing; any other error is thrown.
	 * @throws \Prado\Exceptions\TDbException when the probe fails for another reason.
	 * @return bool whether the table exists
	 * @since 1.0.0
	 */
	protected function tableExists(): bool
	{
		$db = $this->getDbConnection();
		if ($this->getDriverName() === TDbDriver::DRIVER_PGSQL) {
			$command = $db->createCommand('SELECT to_regclass(:table)');
			$command->bindValue(':table', $this->getTableName(), PDO::PARAM_STR);
			$found = $command->queryScalar();

			return $found !== null && $found !== false;
		}
		try {
			$db->createCommand('SELECT * FROM ' . $this->getTableName() . ' WHERE 0=1')->query()->close();
		} catch (TDbException $e) {
			// MySQL and SQL Server 42S02, DB2 42704, SQLite, Oracle, and Firebird by their text.
			if (preg_match('/SQLSTATE\[(42S02|42704|42P01)\]|no such table|ORA-00942|Table unknown/i', $e->getMessage())) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	/**
	 * Creates the meta table and its indexes. The unique index over entity and key is what the
	 * upsert in {@see setMeta} conflicts against, so a hand-made table needs it too.
	 *
	 * MySQL takes the indexes in the CREATE TABLE statement itself. SQLite and PostgreSQL create
	 * the table and its indexes in one transaction, so a failure leaves no table without its
	 * unique index. The key and entity columns compare as written on MySQL
	 * ({@see getMysqlCollation()}), as they do on the other two: a case-insensitive or padding
	 * collation would make 'Colour' and 'colour' one row in the table and two in the cache.
	 * @throws \Prado\Exceptions\TConfigurationException when the driver is not MySQL, PostgreSQL, or SQLite.
	 */
	protected function createDbTable(): void
	{
		$db = $this->getDbConnection();
		$driver = $this->getDriverName();
		$table = $this->getTableName();
		[$qualifier, $tablePart, $base] = $this->splitTableName();
		$entityKeyIndex = $base . '_entity_key';
		$autoLoadIndex = $base . '_entity_autoload';
		$entityKey = $this->getEntityIdField() . ', ' . $this->getKeyField();
		$entityAutoLoad = $this->getEntityIdField() . ', ' . $this->getAutoLoadField();

		$textType = 'TEXT';
		$boolDefault = '1';
		switch ($driver) {
			case TDbDriver::DRIVER_SQLITE:
				$idColumn = 'meta_id INTEGER PRIMARY KEY AUTOINCREMENT';
				break;
			case TDbDriver::DRIVER_PGSQL:
				$idColumn = 'meta_id SERIAL PRIMARY KEY';
				$boolDefault = 'TRUE';
				break;
			case TDbDriver::DRIVER_MYSQL:
				$idColumn = 'meta_id INTEGER PRIMARY KEY AUTO_INCREMENT';
				$textType = 'MEDIUMTEXT';
				break;
			default:
				throw new TConfigurationException('meta_table_create_unsupported', $table, $driver);
		}

		$columns = [
			$idColumn,
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

		if ($driver === TDbDriver::DRIVER_MYSQL) {
			$columns[] = 'UNIQUE KEY ' . $entityKeyIndex . ' (' . $entityKey . ')';
			if ($this->getAutoLoadField() !== '') {
				$columns[] = 'KEY ' . $autoLoadIndex . ' (' . $entityAutoLoad . ')';
			}
			$db->createCommand('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')'
				. ' DEFAULT CHARSET=utf8mb4 COLLATE=' . $this->getMysqlCollation())->execute();

			return;
		}

		// An index name is unique across a schema in SQLite and PostgreSQL, so it carries the
		// table name: two instances of this module must not collide. SQLite puts a schema on the
		// index name and not on the table; PostgreSQL creates the index in the table's schema.
		$indexPrefix = $driver === TDbDriver::DRIVER_SQLITE && $qualifier !== '' ? $qualifier . '.' : '';
		$indexTable = $driver === TDbDriver::DRIVER_SQLITE ? $tablePart : $table;
		$statements = ['CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')'];
		$statements[] = 'CREATE UNIQUE INDEX ' . $indexPrefix . $entityKeyIndex . ' ON ' . $indexTable . ' (' . $entityKey . ')';
		if ($this->getAutoLoadField() !== '') {
			$statements[] = 'CREATE INDEX ' . $indexPrefix . $autoLoadIndex . ' ON ' . $indexTable . ' (' . $entityAutoLoad . ')';
		}
		$this->inTransaction(function () use ($db, $statements) {
			foreach ($statements as $sql) {
				$db->createCommand($sql)->execute();
			}
		});
	}

	/**
	 * Splits the table name into its qualifier and its last part.
	 * @return array{0: string, 1: string, 2: string} the qualifier ('' when there is none), the
	 *   last part as written, and the last part unquoted and reduced to [A-Za-z0-9_] for naming
	 *   the indexes
	 */
	private function splitTableName(): array
	{
		preg_match_all('/' . self::IDENTIFIER_PART . '/', $this->getTableName(), $matches);
		$parts = $matches[0];
		$last = (string) array_pop($parts);
		$base = preg_replace('/[^A-Za-z0-9_]/', '_', trim($last, '`"[]'));

		return [implode('.', $parts), $last, (string) $base];
	}

	/**
	 * Runs work in a transaction of its own, unless the connection already has one open, in
	 * which case the work joins it.
	 * @param callable $work the work
	 * @throws \Throwable whatever the work throws, after rolling back
	 */
	private function inTransaction(callable $work): void
	{
		$db = $this->getDbConnection();
		if ($db->getCurrentTransaction() !== null) {
			$work();

			return;
		}
		$transaction = $db->beginTransaction();
		try {
			$work();
			$transaction->commit();
		} catch (\Throwable $e) {
			if ($transaction->getActive()) {
				$transaction->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Drops the cache when the connection's open transaction rolls back: what was read or
	 * written inside it may no longer be in the table, nor may a table created inside it.
	 */
	private function watchTransaction(): void
	{
		$transaction = $this->getDbConnection()->getCurrentTransaction();
		if (!$transaction instanceof TDbTransaction) {
			return;
		}
		$this->_watchedTransactions ??= new \WeakMap();
		if (isset($this->_watchedTransactions[$transaction])) {
			return;
		}
		$this->_watchedTransactions[$transaction] = true;
		$transaction->attachEventHandler('OnRollback', function () {
			$this->flushMetaCache();
			$this->_tableEnsured = false;
		});
	}

	/**
	 * @param string $value the text to measure
	 * @return int its length in characters, or in bytes without mbstring
	 */
	private static function length(string $value): int
	{
		return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
	}
}
