<?php

use Belisoful\Prado\Util\Meta\TMetaBehavior;
use Prado\Security\IUser;
use Prado\Security\TUser;
use Prado\TComponent;

require_once(__DIR__ . '/../test_tools/MetaTestTools.php');

class TUserMetaModuleTest extends PHPUnit\Framework\TestCase
{
	/** @var string[] behavior names attached to IUser during a test */
	private array $_attached = [];

	protected function tearDown(): void
	{
		// The behavior is attached to the IUser interface, which is process-wide state: leaving
		// it attached would carry into the next test and make attaching again a duplicate.
		foreach ($this->_attached as $name) {
			try {
				TComponent::detachClassBehavior($name, IUser::class);
			} catch (\Throwable $e) {
				// Already gone; nothing to undo.
			}
		}
		$this->_attached = [];
	}

	/**
	 * @param array $properties properties to set before init
	 * @return \TestUserMetaModule an initialized module whose behavior is detached afterwards
	 */
	private function createModule(array $properties = []): TestUserMetaModule
	{
		$module = MetaTestTools::createUserModule($properties);
		$this->_attached[] = $module->getBehaviorName();

		return $module;
	}

	public function testDefaultsDescribeTheUserTable()
	{
		$module = new TestUserMetaModule();
		$this->assertSame('user_meta', $module->getTableName());
		$this->assertSame('user_id', $module->getEntityIdField());
		$this->assertSame('meta', $module->getBehaviorName());
	}

	public function testUsersGainTheMetaMethods()
	{
		$this->createModule();
		$user = new TUser(MetaTestTools::createUserManager());
		$user->setName('rayelan');

		$user->setMeta('timezone', 'America/Denver');
		$this->assertSame('America/Denver', $user->getMeta('timezone'));
		$this->assertTrue($user->existsMeta('timezone'));
		$this->assertSame(['timezone' => 'America/Denver'], $user->getAllMeta());

		$user->unsetMeta('timezone');
		$this->assertFalse($user->existsMeta('timezone'));
		$this->assertSame('UTC', $user->getMeta('timezone', 'UTC'));
	}

	public function testUserMetaKeepsItsTypeThroughTheBehavior()
	{
		$module = $this->createModule();
		$user = new TUser(MetaTestTools::createUserManager());
		$user->setName('rayelan');

		$user->setMeta('posts', 12);
		$user->setMeta('trusted', false);
		$module->flushMetaCache();

		$this->assertSame(12, $user->getMeta('posts'));
		$this->assertFalse($user->getMeta('trusted'));
	}

	/**
	 * Meta is storage, not session state: the two APIs sit side by side on a user and must not
	 * be confused, which is why the persistent one is not called getMetaState().
	 */
	public function testMetaIsSeparateFromSessionState()
	{
		$this->createModule();
		$user = new TUser(MetaTestTools::createUserManager());
		$user->setName('rayelan');
		$user->setMeta('timezone', 'America/Denver');

		$restored = new TUser(MetaTestTools::createUserManager());
		$restored->setName('rayelan');

		// A second instance of the same user reads the stored value; nothing was carried in the
		// session to make that work.
		$this->assertSame('America/Denver', $restored->getMeta('timezone'));
		$this->assertStringNotContainsString('America/Denver', (string) $restored->saveToString());
	}

	public function testTwoUsersDoNotShareValues()
	{
		$this->createModule();
		$manager = MetaTestTools::createUserManager();
		$first = new TUser($manager);
		$first->setName('rayelan');
		$second = new TUser($manager);
		$second->setName('hobie');

		$first->setMeta('colour', 'green');
		$second->setMeta('colour', 'red');

		$this->assertSame('green', $first->getMeta('colour'));
		$this->assertSame('red', $second->getMeta('colour'));
	}

	public function testRowsAreKeyedOnTheUserNameWhenThereIsNoId()
	{
		$module = $this->createModule();
		$user = new TUser(MetaTestTools::createUserManager());
		$user->setName('rayelan');

		$user->setMeta('colour', 'green');
		$this->assertSame('green', $module->getMeta('rayelan', 'colour'));
	}

	public function testRowsAreKeyedOnTheUserIdWhenThereIsOne()
	{
		// An id survives a rename, where rows keyed on the name would be orphaned by one.
		$module = $this->createModule();
		$user = new MetaTestUserWithId(MetaTestTools::createUserManager());
		$user->setName('rayelan');
		$user->setID('41');

		$user->setMeta('colour', 'green');
		$this->assertSame('green', $module->getMeta('41', 'colour'));
		$this->assertSame('41', $module->getUserEntityId($user));

		$user->setName('renamed');
		$this->assertSame('green', $user->getMeta('colour'), 'the value follows the user through a rename');
	}

	public function testTheBehaviorNameIsConfigurable()
	{
		$module = $this->createModule(['BehaviorName' => 'usermeta']);
		$this->assertSame('usermeta', $module->getBehaviorName());

		$user = new TUser(MetaTestTools::createUserManager());
		$user->setName('rayelan');
		$this->assertInstanceOf(TMetaBehavior::class, $user->asa('usermeta'));
	}

	public function testTheBehaviorFindsItsModule()
	{
		$module = $this->createModule();
		$user = new TUser(MetaTestTools::createUserManager());
		$behavior = $user->asa('meta');

		$this->assertInstanceOf(TMetaBehavior::class, $behavior);
		$this->assertSame($module, $behavior->getModule());
	}
}
