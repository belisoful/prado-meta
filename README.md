prado-meta
==========

Typed, autoloaded entity meta storage for PRADO: one implementation, one table per entity type.

Store arbitrary keyed values against a user, a post, a tag, or anything else with an id, and get
them back as what they were: an int as an int, an array as an array.

```php
$user->setMeta('timezone', 'America/Denver');
$user->getMeta('timezone', 'UTC');
```


Installation
------------

```
composer require belisoful/prado-meta
```

Load the extension by package name; the module class comes from `extra.prado.bootstrap`:

```xml
<modules>
	<module id="belisoful/prado-meta" ConnectionID="db" />
</modules>
```

That is `TUserMetaModule`, which stores in `user_meta` keyed on `user_id` and gives every
`IUser` the meta methods. The table is created on first use unless `AutoCreateTable` says
otherwise.


Meta on other entities
----------------------

`TEntityMetaModule` is the same storage without the user binding. Configure one per entity type,
each with its own table:

```xml
<module id="post-meta" class="TEntityMetaModule" ConnectionID="db"
        TableName="post_meta" EntityIdField="post_id" />
```

```php
$postMeta = $this->getApplication()->getModule('post-meta');
$postMeta->setMeta($postId, 'source-url', 'https://example.com/article');
```

One table per entity type rather than one shared table with a type column: a shared table puts
that column in every index and makes each lookup pay for every other entity type's rows.


What it stores
--------------

| Column | Purpose |
| --- | --- |
| `meta_id` | primary key |
| `user_id` / `post_id` / … | the entity, named by `EntityIdField` |
| `meta_key` | the key |
| `meta_value` | the value as text |
| `meta_type` | the type code, so the value returns as what it was |
| `autoload` | whether the row loads with the entity's first read |

Unique on (entity, key), which is what the upsert conflicts against, and indexed on
(entity, autoload) for the autoload read.

Strings, ints, floats, bools, and null round-trip exactly. Arrays and objects are serialized with
PHP's `serialize()`, or as JSON when `Serializer` is set to `json` (JSON does not restore
objects).


Reads and queries
-----------------

The first read of an entity loads every autoloaded row in one query, and is cached for the
request. A key that is not autoloaded costs one query the first time it is asked for and none
afterwards. Set `AutoLoadField` to `''` and the first read loads all of the entity's rows, after
which no key ever costs a second query.

`flushMetaCache($entityId = null)` drops the cache when rows have been written by some other
route.


Compatibility with an existing table
------------------------------------

Every column name is configurable, and setting `TypeField` to `''` stores values without a type
code, which maps the module onto a table that has none -- WordPress's `usermeta`, say. Values
then come back as strings, which is all such a table can tell you.


Meta is not session state
-------------------------

`TUser::getState()` holds session data and loses it at logout. What is set here is stored until
it is removed, is readable for a user who is not signed in, and is deliberately not named
`getMetaState()` so the two are not mistaken for each other.


Development
-----------

`composer fulltest` runs the full check: compile, code style, static analysis, unit tests.
`composer integration` installs the package into a throwaway consumer project and checks the
wiring. See AGENTS.md for the conventions this package holds to.
