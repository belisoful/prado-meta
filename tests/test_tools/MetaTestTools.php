<?php

/**
 * Test doubles for the meta modules.
 *
 * The modules reach their database through TDbPropertiesTrait, which asks
 * getCustomDbConnection() when no ConnectionID names a TDataSourceConfig module. Overriding that
 * is what lets a test hand a module a connection without standing up an application, so the
 * tests exercise the real query paths against a real database.
 *
 * Each connection is its own in-memory SQLite database, so one test cannot see another's rows.
 */

use Belisoful\Prado\Util\Meta\TEntityMetaModule;
use Belisoful\Prado\Util\Meta\TUserMetaModule;
use Prado\Data\TDbConnection;
use Prado\Security\TUser;
use Prado\Security\TUserManager;

trait MetaTestConnectionTrait
{
	/** @var null|\Prado\Data\TDbConnection the connection this module was handed */
	private ?TDbConnection $_testConnection = null;

	public function setTestDbConnection(TDbConnection $connection): void
	{
		$this->_testConnection = $connection;
	}

	public function getTestDbConnection(): ?TDbConnection
	{
		return $this->_testConnection;
	}

	protected function getCustomDbConnection(): ?TDbConnection
	{
		return $this->_testConnection;
	}
}

class TestEntityMetaModule extends TEntityMetaModule
{
	use MetaTestConnectionTrait;
}

class TestUserMetaModule extends TUserMetaModule
{
	use MetaTestConnectionTrait;
}

/** A user manager is all TUser needs to exist; nothing here authenticates. */
class MetaTestUserManager extends TUserManager
{
}

/** A user whose ID is what its meta rows should be keyed on, as a database user manager's would be. */
class MetaTestUserWithId extends TUser
{
	private string $_userId = '';

	public function getID($hideAutoID = true)
	{
		return $this->_userId;
	}

	public function setID($value)
	{
		$this->_userId = (string) $value;
	}
}

class MetaTestTools
{
	/**
	 * @return \Prado\Data\TDbConnection an open connection to a database of this test's own
	 */
	public static function createConnection(): TDbConnection
	{
		$connection = new TDbConnection('sqlite::memory:');
		$connection->setActive(true);

		return $connection;
	}

	/**
	 * @param array $properties properties to set before init, as name => value
	 * @return \TestEntityMetaModule an initialized module on a fresh database
	 */
	public static function createEntityModule(array $properties = []): TestEntityMetaModule
	{
		$module = new TestEntityMetaModule();
		$module->setTestDbConnection(self::createConnection());
		self::applyProperties($module, $properties);
		$module->init(null);

		return $module;
	}

	/**
	 * @param array $properties properties to set before init, as name => value
	 * @return \TestUserMetaModule an initialized user meta module on a fresh database
	 */
	public static function createUserModule(array $properties = []): TestUserMetaModule
	{
		$module = new TestUserMetaModule();
		$module->setTestDbConnection(self::createConnection());
		$module->setID('user-meta');
		self::applyProperties($module, $properties);
		$module->init(null);

		return $module;
	}

	/**
	 * @return \Prado\Security\TUserManager a user manager that does nothing but exist
	 */
	public static function createUserManager(): TUserManager
	{
		return new MetaTestUserManager();
	}

	/**
	 * Deletes a row without telling the module, so a test can prove that a later read was
	 * served from the cache rather than from the database.
	 * @param \TestEntityMetaModule|\TestUserMetaModule $module the module whose table to write to
	 * @param string $entityId the entity the row belongs to
	 * @param string $key the key to delete
	 */
	public static function deleteRowDirectly($module, string $entityId, string $key): void
	{
		$sql = 'DELETE FROM ' . $module->getTableName() . ' WHERE ' . $module->getEntityIdField()
			. ' = :entity AND ' . $module->getKeyField() . ' = :key';
		$command = $module->getTestDbConnection()->createCommand($sql);
		$command->bindValue(':entity', $entityId, PDO::PARAM_STR);
		$command->bindValue(':key', $key, PDO::PARAM_STR);
		$command->execute();
	}

	/**
	 * @param \Prado\TComponent $module the module to configure
	 * @param array $properties properties as name => value
	 */
	private static function applyProperties($module, array $properties): void
	{
		foreach ($properties as $name => $value) {
			$module->{'set' . $name}($value);
		}
	}
}
