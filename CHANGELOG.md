# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `TEntityMetaModule`, keyed meta storage for any entity: one instance per entity type, each
  with its own table, every column name configurable.
- Typed values. A type code stored beside each value returns a string, int, float, bool, null,
  array, or object as what it was, rather than as the string the database holds. `TypeField`
  set to '' stores values untyped, for an existing table without such a column.
- A per-entity read cache. The first read of an entity loads its autoloaded rows in one query;
  a key outside that set costs one query the first time and none afterwards. With
  `AutoLoadField` set to '', the first read loads the entity whole and no key needs a second
  query. `flushMetaCache()` drops what is cached.
- `TUserMetaModule`, which binds the storage to `user_meta` on `user_id` and attaches
  `TMetaBehavior` to every `IUser`, so a user answers `getMeta`, `setMeta`, `unsetMeta`,
  `existsMeta`, and `getAllMeta`. Rows are keyed on the user's ID where the user manager gives
  one, so they survive a rename, and on the user name otherwise.
- Upserts on MySQL, SQLite, and PostgreSQL, with a delete-then-insert fallback, in a
  transaction, for any other driver. The table is created on MySQL, SQLite, and PostgreSQL;
  another driver gets a configuration error naming it, rather than MySQL DDL.
- `AllowedClasses`: the classes a PHP-serialized value may restore, none by default, so a value
  written into the table by another route cannot instantiate an arbitrary class.
- `MaxEntityIdLength` (128) and `MaxKeyLength` (191), matching the created columns, so a longer
  id or key is refused instead of truncated into a collision; 0 lifts the check.
- A guest has no meta: reads through the behavior return the default, and writes throw
  `meta_user_guest`, because every anonymous visitor is the same guest user.
- The table and column properties of `TEntityMetaModule` and `TUserMetaModule` are SQL
  identifiers; their setters reject any other value, as PRADO's `TDbParameterModule` does.

### Changed

- PHP 8.2 is the minimum. PRADO `master`, which the package tracks, requires PHP 8.2, so
  `pradosoft/prado ^4.4@dev` no longer installs on 8.1. CI runs PHP 8.2 to 8.5, and
  `php-cs-fixer` and `phpstan` are pinned to the versions the framework pins (3.95.27, 2.3.3).
- Error messages are ICU MessageFormat patterns, as PRADO now formats them. A quoted argument is
  written `''{0}''`; the single-quoted `'{0}'` rendered the literal `{0}`. A package standard
  check formats each message and fails on an argument it does not render.

### Fixed

Found by the audit in `agents/AUDIT-2026-11-03.md`, and tested against SQLite, PostgreSQL 17,
and MySQL 26.7.

- PostgreSQL: every write failed, binding the autoload flag as an integer into the BOOLEAN
  column, and every autoload read failed on `boolean <> integer`. The flag is bound as text and
  the column cast for the read, which also serves a hand-made integer column.
- Guests shared one meta entity, the guest name, so one visitor read what another wrote.
- The cache kept values written inside a transaction that rolled back. It is dropped on
  `TDbTransaction::OnRollback`, and at the end of each request.
- `INF`, `-INF`, and `NAN` were stored as '' and read back as 0.0.
- The JSON serializer stored a value it could not encode as '', read back as null; it now throws
  `meta_value_unencodable`. A cached read and a read from the table now return the same thing:
  the cache holds what the table would give back, not the object that was passed in.
- Changing `Serializer` made existing arrays and objects read as null. Rows record their
  serializer: `p` for PHP, `j` for JSON. The old code `a` is still read, with the configured
  serializer.
- Creating a quoted or schema-qualified table failed after the table was made, leaving it
  without its unique index. The table and its indexes are now created in one statement (MySQL)
  or one transaction (SQLite, PostgreSQL), with index names built from the unquoted table name.
- A missing table without `AutoCreateTable` was reported once, then surfaced as a raw database
  error; any other probe error was taken for a missing table; and on PostgreSQL the failing
  probe aborted the caller's transaction. PostgreSQL is asked through `to_regclass()`, only a
  "no such table" error counts as missing, and the check repeats until the table is found.
- A write before an entity's first read stopped its autoloaded rows from loading together.
- A second `TUserMetaModule`, or the application booted again in one process, threw
  `component_class_behavior_defined`; the module now replaces the behavior it attached before.
- MySQL: the upsert used `VALUES()`, deprecated since 8.0.20, and is written with a row alias on
  8.0.19 and later; MariaDB keeps `VALUES()`. A created table compares keys with a binary
  collation that does not pad, so `Colour`, `colour`, and `colour ` are three keys in the table
  as in the cache.
- An entity id that is not a string, number, or `Stringable` raised a PHP warning; it now throws
  `meta_entity_id_invalid`. A corrupt serialized value read as `false`; it reads as null and is
  logged.
- README no longer says WordPress's `usermeta` works as is: it has no unique (entity, key)
  index, so every write added a row.
- CI runs the driver tests against MySQL 8 and PostgreSQL 16, adds macOS and Windows jobs and
  Dependabot, and checks `tests/` with php-cs-fixer and PHPStan.
