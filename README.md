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

PRADO depends on `bower-asset/*` packages, so the application's own `composer.json` lists the
asset-packagist repository, as every PRADO application does:

```json
"repositories": [
	{ "type": "composer", "url": "https://asset-packagist.org" }
]
```

MySQL (and MariaDB), PostgreSQL, and SQLite are supported, including creating the table.

Load the extension by package name; the module class comes from `extra.prado.bootstrap`:

```xml
<modules>
	<module id="belisoful/prado-meta" ConnectionID="db" />
</modules>
```

That is `TUserMetaModule`, which stores in `user_meta` keyed on `user_id` and gives every
`IUser` the meta methods. The table is created on first use unless `AutoCreateTable` says
otherwise. MySQL commits any open transaction before it creates a table, so on MySQL let the
table be created outside one, or create it ahead of time.

A guest has no meta. Every anonymous visitor is the same guest user, so anything written for one
would be read by all of them: for a guest, `getMeta()` returns the default, `existsMeta()` is
false, `getAllMeta()` is empty, and `setMeta()` and `unsetMeta()` throw.

Rows are keyed on the user's `ID` when the user class has one, so they survive a rename, and on
the user name otherwise. PRADO's own `TUser` and `TDbUser` have no `ID`, so with the stock user
managers a rename leaves the old name's rows behind.

The behavior is attached to `IUser` for the whole process under `BehaviorName` (`meta`). Booting
the module again, as a long-running server does, replaces the behavior it attached before.


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
(entity, autoload) for the autoload read. On MySQL the key and entity columns use a binary
collation that does not pad, so `Colour`, `colour`, and `colour ` are three keys, as they are on
PostgreSQL and SQLite.

`TableName` and the column properties are SQL identifiers, written into the queries as given;
their setters reject anything else. A quoted name (`"post meta"`) or a schema-qualified one
(`app.post_meta`) works, and so does creating it.

Entity ids are strings or numbers (or `Stringable`), at most `MaxEntityIdLength` (128)
characters; keys are at most `MaxKeyLength` (191). Those match the columns the module creates;
set either to `0` to lift the check on a table of your own with wider columns.

Strings, ints, floats (`INF` and `NAN` included), bools, and null round-trip exactly. Arrays and
objects are serialized with PHP's `serialize()`, or as JSON when `Serializer` is set to `json`
(JSON does not restore objects, and a value JSON cannot encode is refused). Each row records
which serializer wrote it, so changing `Serializer` leaves existing rows readable.

A PHP-serialized object is restored only when its class is in `AllowedClasses`, which is empty
by default; any other comes back as `__PHP_Incomplete_Class`. Restoring every class (`*`) lets
whoever can write the table choose what is instantiated, so name the classes you store:

```xml
<module id="post-meta" class="TEntityMetaModule" ConnectionID="db" TableName="post_meta"
        EntityIdField="post_id" AllowedClasses="App\Model\Address, DateTimeImmutable" />
```


Reads and queries
-----------------

The first read of an entity loads every autoloaded row in one query, and is cached for the
request. A key that is not autoloaded costs one query the first time it is asked for and none
afterwards. Set `AutoLoadField` to `''` and the first read loads all of the entity's rows, after
which no key ever costs a second query.

`flushMetaCache($entityId = null)` drops the cache when rows have been written by some other
route. The module drops it itself when a transaction on its connection rolls back, and at the end
of every request, so a module in a long-running server does not serve one request's reads to the
next.


Compatibility with an existing table
------------------------------------

Every column name is configurable. Setting `TypeField` to `''` stores values without a type
code, for a table that has none; values then come back as strings, which is all such a table can
tell you. Setting `AutoLoadField` to `''` does the same for a table without an autoload column.

The table must have a unique index over the entity and key columns: writes are upserts that
conflict against it. Without one, every write adds a row. WordPress's `usermeta` is such a
table -- it allows several values per key and has neither a type nor an autoload column -- so it
needs a unique index on `(user_id, meta_key)` before this module can write to it, and adding one
fails while any key holds more than one value.

On a driver other than MySQL, PostgreSQL, or SQLite, create the table yourself; the module
writes there by deleting the old row and inserting the new one in a transaction.


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

The unit suite runs on SQLite. The driver tests also run on PostgreSQL and MySQL when their DSNs
are in the environment (`tests/initdb_mysql.sql` sets up a local MySQL database for them):

```
META_PGSQL_DSN="pgsql:host=127.0.0.1;dbname=prado_meta_test" META_PGSQL_USER=... META_PGSQL_PASSWORD=... \
META_MYSQL_DSN="mysql:host=127.0.0.1;dbname=prado_meta_test" META_MYSQL_USER=... META_MYSQL_PASSWORD=... \
composer unittest
```

CI runs both and sets `META_REQUIRE_BACKENDS=1`, which fails a test whose backend is missing
instead of skipping it.
