-- mysql_high_performance.sql
-- Run these statements as a user with SUPER or SYSTEM_VARIABLES_ADMIN privileges (or run as root via the mysql CLI).
-- Note: some variables (e.g. query_cache_*) were removed in MySQL 8 and are intentionally omitted.
-- This file applies session/global variables immediately; to make them persistent, update your MySQL configuration (my.cnf) and restart the server.

SET GLOBAL max_connections = 500;
SET GLOBAL thread_cache_size = 100;
SET GLOBAL table_open_cache = 4000;

SET GLOBAL innodb_buffer_pool_size = '4G';
SET GLOBAL innodb_log_buffer_size = '64M';

-- query_cache_size and query_cache_limit omitted (MySQL 8+ removed these)

SET GLOBAL innodb_flush_log_at_trx_commit = 2;
SET GLOBAL sync_binlog = 0;
SET GLOBAL innodb_doublewrite = 0;

SET GLOBAL bulk_insert_buffer_size = '64M';
SET GLOBAL max_allowed_packet = '256M';

SET GLOBAL wait_timeout = 300;
SET GLOBAL interactive_timeout = 300;
