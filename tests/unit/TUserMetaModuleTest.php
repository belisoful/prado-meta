<?php

use Belisoful\Prado\Util\Meta\TMetaBehavior;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Security\IUser;
use Prado\Security\TUser;
use Prado\Exceptions\TInvalidOperationException;
use Prado\TComponent;
use Prado\Util\TClassBehavior;

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

	public function testTableAndEntityColumnMustBeSqlIdentifiers()
	{
		foreach (['TableName', 'EntityIdField'] as $property) {
			$module = new TestUserMetaModule();
			try {
				$module->{'set' . $property}('user meta');
				$this->fail("{$property} accepted a value that is not an SQL identifier");
			} catch (TInvalidDataValueException $e) {
				$this->assertStringContainsString("'user meta'", $e->getMessage());
			}
		}
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
		$user = MetaTestTools::createUser('rayelan');

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
		$user = MetaTestTools::createUser('rayelan');

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
		$user = MetaTestTools::createUser('rayelan');
		$user->setMeta('timezone', 'America/Denver');

		$restored = MetaTestTools::createUser('rayelan');

		// A second instance of the same user reads the stored value; nothing was carried in the
		// session to make that work.
		$this->assertSame('America/Denver', $restored->getMeta('timezone'));
		$this->assertStringNotContainsString('America/Denver', (string) $restored->saveToString());
	}

	public function testTwoUsersDoNotShareValues()
	{
		$this->createModule();
		$first = MetaTestTools::createUser('rayelan');
		$second = MetaTestTools::createUser('hobie');

		$first->setMeta('colour', 'green');
		$second->setMeta('colour', 'red');

		$this->assertSame('green', $first->getMeta('colour'));
		$this->assertSame('red', $second->getMeta('colour'));
	}

	public function testRowsAreKeyedOnTheUserNameWhenThereIsNoId()
	{
		$module = $this->createModule();
		$user = MetaTestTools::createUser('rayelan');

		$user->setMeta('colour', 'green');
		$this->assertSame('green', $module->getMeta('rayelan', 'colour'));
	}

	public function testRowsAreKeyedOnTheUserIdWhenThereIsOne()
	{
		// An id survives a rename, where rows keyed on the name would be orphaned by one.
		$module = $this->createModule();
		/** @var \MetaTestUserWithId $user */
		$user = MetaTestTools::createUser('rayelan', MetaTestUserWithId::class);
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

		$user = MetaTestTools::createUser('rayelan');
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

	/**
	 * Every anonymous visitor is the same guest user, so meta written for one would be read by
	 * all of them.
	 */
	public function testAGuestHasNoMeta()
	{
		$this->createModule();
		$guest = new TUser(MetaTestTools::createUserManager());
		$this->assertTrue($guest->getIsGuest());

		$this->assertSame('fallback', $guest->getMeta('cart', 'fallback'));
		$this->assertFalse($guest->existsMeta('cart'));
		$this->assertSame([], $guest->getAllMeta());
		foreach (['setMeta' => ['cart', 'secret'], 'unsetMeta' => ['cart']] as $method => $args) {
			try {
				$guest->$method(...$args);
				$this->fail("{$method}() wrote for a guest");
			} catch (TInvalidOperationException $e) {
				$this->assertStringContainsString("Meta in 'user_meta' cannot be written for a guest user", $e->getMessage());
			}
		}
	}

	public function testASignedInUserNamedLikeTheGuestIsNotAGuest()
	{
		$module = $this->createModule();
		$user = MetaTestTools::createUser('Guest');
		$user->setMeta('colour', 'green');
		$this->assertSame('green', $module->getMeta('Guest', 'colour'));
	}

	public function testInitializingAgainReplacesTheBehavior()
	{
		// A second module, or the application booted again in a long-running process.
		$first = $this->createModule();
		$second = $this->createModule();

		$user = MetaTestTools::createUser('rayelan');
		$this->assertSame($second, $user->asa('meta')->getModule());
		$user->setMeta('colour', 'green');
		$this->assertSame('green', $second->getMeta('rayelan', 'colour'));
		$this->assertFalse($first->existsMeta('rayelan', 'colour'));
	}

	public function testABehaviorOfTheSameNameFromElsewhereIsNotReplaced()
	{
		$this->_attached[] = 'claimed';
		TComponent::attachClassBehavior('claimed', new TClassBehavior(), IUser::class);
		try {
			MetaTestTools::createUserModule(['BehaviorName' => 'claimed']);
			$this->fail('another behavior of the same name was replaced');
		} catch (TInvalidOperationException $e) {
			$this->assertStringContainsString("already has a class behavior of 'claimed'", $e->getMessage());
		}
	}

	public function testAForeignBehaviorThatTookOverTheNameIsKept()
	{
		$this->createModule(['BehaviorName' => 'shared']);
		TComponent::detachClassBehavior('shared', IUser::class);
		$foreign = new TClassBehavior();
		TComponent::attachClassBehavior('shared', $foreign, IUser::class);

		try {
			$this->createModule(['BehaviorName' => 'shared']);
			$this->fail('a foreign behavior was replaced');
		} catch (TInvalidOperationException $e) {
			$this->assertSame($foreign, MetaTestTools::createUser('rayelan')->asa('shared'));
		}
	}
}
