<?php

/**
 * TUserMetaModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-meta
 * @license https://github.com/belisoful/prado-meta/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\Meta;

use Prado\Security\IUser;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * TUserMetaModule class.
 *
 * Binds {@see \Belisoful\Prado\Util\Meta\TEntityMetaModule} to users: it stores in `user_meta`
 * keyed on `user_id`, and attaches {@see \Belisoful\Prado\Util\Meta\TMetaBehavior} to every
 * {@see \Prado\Security\IUser}, so any user gains the meta methods:
 *
 * ```php
 * $user->setMeta('timezone', 'America/Denver');
 * $timezone = $user->getMeta('timezone', 'UTC');
 * ```
 *
 * ```xml
 * <module id="user-meta" class="TUserMetaModule" ConnectionID="db" />
 * ```
 *
 * A user's meta is not a user's session state. {@see \Prado\Security\TUser::getState()} holds
 * what the session carries and loses it at logout; what is set here is stored until it is
 * removed, and is readable for a user who is not signed in.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TUserMetaModule extends TEntityMetaModule
{
	/** @var string the name the behavior is attached under */
	private string $_behaviorName = 'meta';

	/** @var string the table holding user meta */
	private string $_tableName = 'user_meta';

	/** @var string the column holding the user */
	private string $_entityIdField = 'user_id';

	/**
	 * Initializes the module and gives every user the meta methods.
	 * @param null|array|\Prado\Xml\TXmlElement $config the module configuration
	 */
	public function init($config)
	{
		parent::init($config);

		TComponent::attachClassBehavior($this->getBehaviorName(), new TMetaBehavior($this), IUser::class);
	}

	/**
	 * @return string the table holding user meta, 'user_meta' by default
	 */
	public function getTableName(): string
	{
		return $this->_tableName;
	}

	/**
	 * @param string $value the table holding user meta
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setTableName($value): void
	{
		$this->assertUninitialized('TableName');
		$this->_tableName = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the column holding the user, 'user_id' by default
	 */
	public function getEntityIdField(): string
	{
		return $this->_entityIdField;
	}

	/**
	 * @param string $value the column holding the user
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setEntityIdField($value): void
	{
		$this->assertUninitialized('EntityIdField');
		$this->_entityIdField = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the name the behavior is attached under, 'meta' by default
	 */
	public function getBehaviorName(): string
	{
		return $this->_behaviorName;
	}

	/**
	 * @param string $value the name the behavior is attached under
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setBehaviorName($value): void
	{
		$this->assertUninitialized('BehaviorName');
		$this->_behaviorName = TPropertyValue::ensureString($value);
	}

	/**
	 * Works out which column value a user's rows are keyed on: the user's ID where the user
	 * manager gives one, and the user name otherwise.
	 *
	 * An ID is preferred because it survives a rename, where rows keyed on the name would be
	 * orphaned by one. A user class without an ID -- the framework's own
	 * {@see \Prado\Security\TUser} has none -- falls back to the name, which is unique.
	 *
	 * @param \Prado\Security\IUser $user the user to key on
	 * @return string the value of the entity column for the user
	 */
	public function getUserEntityId(IUser $user): string
	{
		if ($user instanceof TComponent && $user->canGetProperty('id')) {
			$id = $user->getSubProperty('ID');
			if ($id !== null && (string) $id !== '') {
				return (string) $id;
			}
		}

		return (string) $user->getName();
	}
}
