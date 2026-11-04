-- Sets up a local MySQL database for the PostgreSQL/MySQL legs of the unit suite
-- (tests/unit/TEntityMetaModuleDriverTest.php). Run it as a MySQL administrator:
--
--     mysql -u root < tests/initdb_mysql.sql
--     META_MYSQL_DSN="mysql:host=127.0.0.1;dbname=prado_meta_test" \
--         META_MYSQL_USER=prado_meta_test META_MYSQL_PASSWORD=prado_meta_test composer unittest
--
-- The tests create and drop tables of their own; nothing here needs to exist beforehand.
DROP DATABASE IF EXISTS `prado_meta_test`;
CREATE DATABASE `prado_meta_test`;
CREATE USER IF NOT EXISTS 'prado_meta_test'@'localhost' IDENTIFIED BY 'prado_meta_test';
CREATE USER IF NOT EXISTS 'prado_meta_test'@'127.0.0.1' IDENTIFIED BY 'prado_meta_test';
GRANT ALL ON `prado_meta_test`.* TO 'prado_meta_test'@'localhost';
GRANT ALL ON `prado_meta_test`.* TO 'prado_meta_test'@'127.0.0.1';
FLUSH PRIVILEGES;
