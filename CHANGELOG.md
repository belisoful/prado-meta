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
- Upserts on MySQL, SQLite, and PostgreSQL, with a delete-then-insert fallback for any other
  driver.
- The table and column properties of `TEntityMetaModule` and `TUserMetaModule` are SQL
  identifiers; their setters reject any other value, as PRADO's `TDbParameterModule` does.

### Changed

- PHP 8.2 is the minimum. PRADO `master`, which the package tracks, requires PHP 8.2, so
  `pradosoft/prado ^4.4@dev` no longer installs on 8.1. CI runs PHP 8.2 to 8.5, and
  `php-cs-fixer` and `phpstan` are pinned to the versions the framework pins (3.95.27, 2.3.3).
- Error messages are ICU MessageFormat patterns, as PRADO now formats them. A quoted argument is
  written `''{0}''`; the single-quoted `'{0}'` rendered the literal `{0}`. A package standard
  check formats each message and fails on an argument it does not render.
