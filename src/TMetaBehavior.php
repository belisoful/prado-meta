<?php

/**
 * TMetaBehavior class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-meta
 * @license https://github.com/belisoful/prado-meta/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\Meta;

use Prado\Exceptions\TInvalidOperationException;
use Prado\Security\IUser;
use Prado\Util\TClassBehavior;

/**
 * TMetaBehavior class.
 *
 * Gives every user the meta methods of the module that attached it:
 *
 * ```php
 * $user->setMeta('timezone', 'America/Denver');
 * $user->getMeta('timezone', 'UTC');
 * $user->existsMeta('timezone');
 * $user->unsetMeta('timezone');
 * $user->getAllMeta();
 * ```
 *
 * This is a {@see \Prado\Util\TClassBehavior}, so one instance serves every user and the owner
 * arrives as the first argument of each method.
 *
 * A guest has no meta. Every anonymous visitor is the same guest user, so a row written for one
 * would be read by all of them: for a guest, a read returns the default, {@see existsMeta} is
 * false, {@see getAllMeta} is empty, and a write or removal throws.
 *
 * The module is held directly. A class behavior lives in the process-wide registry
 * {@see \Prado\TComponent::attachClassBehavior} keeps, and is never serialized with a user, so
 * holding the module cannot drag it into the session; a weak reference would only introduce the
 * chance of the module being collected out from under a user that still answers getMeta().
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TMetaBehavior extends TClassBehavior
{
	/** @var \Belisoful\Prado\Util\Meta\TUserMetaModule the module that attached this behavior */
	private TUserMetaModule $_module;

	/**
	 * @param \Belisoful\Prado\Util\Meta\TUserMetaModule $module the module serving the meta
	 */
	public function __construct(TUserMetaModule $module)
	{
		$this->_module = $module;
		parent::__construct();
	}

	/**
	 * @return \Belisoful\Prado\Util\Meta\TUserMetaModule the module serving the meta
	 */
	public function getModule(): TUserMetaModule
	{
		return $this->_module;
	}

	/**
	 * Reads one of the user's values.
	 * @param \Prado\Security\IUser $user the user the behavior is attached to
	 * @param string $key the key
	 * @param mixed $default what to return when the key is not stored
	 * @return mixed the stored value, in the type it was stored as, or $default
	 */
	public function getMeta(IUser $user, string $key, $default = null)
	{
		if ($user->getIsGuest()) {
			return $default;
		}
		$module = $this->getModule();

		return $module->getMeta($module->getUserEntityId($user), $key, $default);
	}

	/**
	 * Writes one of the user's values.
	 * @param \Prado\Security\IUser $user the user the behavior is attached to
	 * @param string $key the key
	 * @param mixed $value the value
	 * @param bool $autoLoad whether the row loads with the user's first read
	 * @throws \Prado\Exceptions\TInvalidOperationException when the user is a guest.
	 */
	public function setMeta(IUser $user, string $key, $value, bool $autoLoad = true): void
	{
		$this->assertNotGuest($user);
		$module = $this->getModule();
		$module->setMeta($module->getUserEntityId($user), $key, $value, $autoLoad);
	}

	/**
	 * Removes one of the user's values.
	 * @param \Prado\Security\IUser $user the user the behavior is attached to
	 * @param string $key the key
	 * @throws \Prado\Exceptions\TInvalidOperationException when the user is a guest.
	 */
	public function unsetMeta(IUser $user, string $key): void
	{
		$this->assertNotGuest($user);
		$module = $this->getModule();
		$module->unsetMeta($module->getUserEntityId($user), $key);
	}

	/**
	 * @param \Prado\Security\IUser $user the user the behavior is attached to
	 * @param string $key the key
	 * @return bool whether the key is stored for the user
	 */
	public function existsMeta(IUser $user, string $key): bool
	{
		if ($user->getIsGuest()) {
			return false;
		}
		$module = $this->getModule();

		return $module->existsMeta($module->getUserEntityId($user), $key);
	}

	/**
	 * @param \Prado\Security\IUser $user the user the behavior is attached to
	 * @return array every value stored for the user, as key => value
	 */
	public function getAllMeta(IUser $user): array
	{
		if ($user->getIsGuest()) {
			return [];
		}
		$module = $this->getModule();

		return $module->getAllMeta($module->getUserEntityId($user));
	}

	/**
	 * @param \Prado\Security\IUser $user the user about to be written for
	 * @throws \Prado\Exceptions\TInvalidOperationException when the user is a guest.
	 */
	protected function assertNotGuest(IUser $user): void
	{
		if ($user->getIsGuest()) {
			throw new TInvalidOperationException('meta_user_guest', $this->getModule()->getTableName());
		}
	}
}
