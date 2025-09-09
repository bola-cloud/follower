MySQL High Performance Optimizations (ops)

Purpose

These statements tune MySQL server variables recommended for high-throughput write workloads. They are intentionally not executed automatically by application migrations because:

- Changing GLOBAL variables requires SUPER or SYSTEM_VARIABLES_ADMIN privileges.
- Some variables are removed in newer MySQL versions (e.g., query_cache_* in MySQL 8).
- Permanent changes should be made in MySQL configuration (my.cnf) and applied by DBAs.

Contents

- `mysql_high_performance.sql` — the suggested SQL statements to run as a privileged user. Run via the `mysql` CLI or your preferred DB admin tooling.

Instructions (DBA)

1. Review and test in non-production first.
2. Run as a privileged user (root or user with appropriate privileges):

   mysql -u root -p < database/ops/mysql_high_performance.sql

3. To make settings persistent across restarts, update `/etc/mysql/my.cnf` or appropriate configuration used by your MySQL packaging. Example entries:

   [mysqld]
   max_connections = 500
   thread_cache_size = 100
   table_open_cache = 4000
   innodb_buffer_pool_size = 4G
   innodb_log_buffer_size = 64M
   innodb_flush_log_at_trx_commit = 2
   sync_binlog = 0
   innodb_doublewrite = 0
   bulk_insert_buffer_size = 64M
   max_allowed_packet = 256M
   wait_timeout = 300
   interactive_timeout = 300

4. Restart MySQL after editing my.cnf.

Notes

- Do not apply these without validating available RAM and overall system capacity. `innodb_buffer_pool_size` should be set to ~60-80% of available RAM on dedicated DB servers.
- If you use managed MySQL (RDS, Cloud SQL), apply changes through their console/parameter groups.
- Keep a rollback plan and monitor the server after changes (CPU, I/O, disk usage, connections).
