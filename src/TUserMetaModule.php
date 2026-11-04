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

	/** @var array<string, \Belisoful\Prado\Util\Meta\TMetaBehavior> lowercase behavior name => the behavior a module of this class attached */
	private static array $_attachedBehaviors = [];

	/**
	 * Initializes the module and gives every user the meta methods.
	 *
	 * The behavior is attached to {@see \Prado\Security\IUser} for the whole process. A module
	 * initialized again under the same {@see setBehaviorName BehaviorName} -- a second instance,
	 * or the application booted again in a long-running process -- replaces the behavior the
	 * earlier one attached, so the last module initialized serves users under that name. A
	 * behavior of that name that some other code attached is left alone, and attaching fails.
	 * @param null|array|\Prado\Xml\TXmlElement $config the module configuration
	 * @throws \Prado\Exceptions\TInvalidOperationException when another behavior has the name.
	 */
	public function init($config)
	{
		parent::init($config);

		$name = strtolower($this->getBehaviorName());
		if (isset(self::$_attachedBehaviors[$name])) {
			$previous = TComponent::detachClassBehavior($name, IUser::class);
			if ($previous !== null && $previous !== self::$_attachedBehaviors[$name]) {
				// Not ours: the name was reused after ours was detached. Put it back, and let
				// the attach below report the conflict.
				TComponent::attachClassBehavior($name, $previous, IUser::class);
			}
			unset(self::$_attachedBehaviors[$name]);
		}
		$behavior = new TMetaBehavior($this);
		TComponent::attachClassBehavior($name, $behavior, IUser::class);
		self::$_attachedBehaviors[$name] = $behavior;
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value is not an SQL identifier.
	 */
	public function setTableName($value): void
	{
		$this->assertUninitialized('TableName');
		$this->_tableName = $this->ensureSqlIdentifier(TPropertyValue::ensureString($value), 'TableName');
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
	 * @throws \Prado\Exceptions\TInvalidDataValueException when $value is not an SQL identifier.
	 */
	public function setEntityIdField($value): void
	{
		$this->assertUninitialized('EntityIdField');
		$this->_entityIdField = $this->ensureSqlIdentifier(TPropertyValue::ensureString($value), 'EntityIdField');
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
