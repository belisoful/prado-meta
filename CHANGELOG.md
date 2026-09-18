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
