# Security Policy

## Reporting Security Issues

If you discover a security vulnerability in this extension, please report it by creating a private security advisory on GitHub or contacting the maintainers directly. Do not open a public issue for security vulnerabilities.

## Security Features

### SQL Injection Protection

1. **Prepared Statements (Recommended)**
   - Use the `prepared` configuration option to define parameterized queries
   - Parameters are passed separately and never interpolated into SQL
   - Example:
     ```php
     'prepared' => [
         'get_user' => 'SELECT * FROM users WHERE id = ?'
     ]
     ```

2. **Composed Query Sanitization**
   - When `$wgODBCAllowArbitraryQueries` is enabled, all query components are sanitized
   - Dangerous SQL keywords are blocked: DROP, DELETE, INSERT, UPDATE, EXEC, etc.
   - SQL comment sequences (`--`, `/*`) are blocked
   - Control characters including null bytes are stripped
   - Column and table identifiers are validated (alphanumeric + underscore + dot only)

3. **LIMIT Enforcement**
   - All queries are limited by `$wgODBCMaxRows` (default: 1000)
   - LIMIT is enforced in SQL (using TOP and LIMIT syntax) and in result fetching
   - Prevents resource exhaustion from unbounded queries

### Access Control

1. **Permission System**
   - `odbc-query`: Required to execute queries from wiki pages
   - `odbc-admin`: Required to access Special:ODBCAdmin
   - By default, only sysops have these permissions

2. **Per-Source Authorization**
   - Individual sources can require prepared statements even when global config allows ad-hoc queries
   - Use `allow_queries: false` (or omit it) in source config

### CSRF Protection

- State-changing POST actions (e.g., running test queries from the admin interface) require a valid `wpEditToken`
- Read-only GET actions (connection tests, table / column browsing) do not mutate server state and do not require a token — this follows standard MediaWiki practice for read-only admin views
- Invalid tokens result in an error message and no action is taken

### Connection Security

1. **Credential Protection**
   - Passwords are never exposed in error messages (regex-stripped from ODBC errors)
   - Connection strings are not logged to client-visible errors
   - Use file system permissions to protect `LocalSettings.php`

2. **Connection Pooling**
   - Maximum `$wgODBCMaxConnections` (default: 10) cached connections across all sources combined to prevent resource leaks
   - Connections are health-checked before reuse
   - Stale connections are automatically closed

3. **Query Timeout**
   - Configurable per-source or global timeout prevents long-running queries
   - Timeout is best-effort (depends on ODBC driver support)

### Admin Interface

- `Special:ODBCAdmin` enforces SELECT-only queries
- Even administrators cannot execute INSERT, UPDATE, DELETE, or DDL statements
- All queries are sanitized using the same rules as parser functions
- Results are capped at 100 rows maximum

### Audit Logging

- All query executions are logged to MediaWiki's debug log (`wfDebugLog('odbc', ...)`)
- Logs include source ID, query (truncated to 100 chars), and row count
- Failed queries log full error details server-side
- Warnings are logged for connection/query failures

## Best Practices

### For Production Deployments

1. **Use Prepared Statements**
   - Define all queries in the `prepared` array of source config
   - Never enable `$wgODBCAllowArbitraryQueries` in production
   - Only enable per-source `allow_queries` for specific trusted sources if absolutely necessary

2. **Use Read-Only Database Accounts**
   - Create a dedicated database user with SELECT-only privileges
   - Never use `sa`, `root`, or admin accounts
   - Example (SQL Server):
     ```sql
     CREATE LOGIN wiki_reader WITH PASSWORD = 'SecurePassword123';
     CREATE USER wiki_reader FOR LOGIN wiki_reader;
     GRANT SELECT ON SCHEMA::dbo TO wiki_reader;
     ```

3. **Restrict User Permissions**
   - Don't grant `odbc-query` to anonymous users
   - Consider limiting to autoconfirmed or specific trusted groups
   - Keep `odbc-admin` restricted to sysops or a dedicated admin group

4. **Secure Configuration Files**
   - Set proper permissions on `LocalSettings.php` (read-only for web server user)
   - Consider using environment variables for credentials
   - Never commit `LocalSettings.php` to version control

5. **Use SSL/TLS for Database Connections**
   - Enable encryption in DSN parameters
   - Example: `'dsn_params' => ['Encrypt' => 'yes']`
   - Verify certificates or use `trust_certificate` only for internal networks

6. **Enable Query Caching Carefully**
   - Set `$wgODBCCacheExpiry` based on data staleness tolerance
   - Remember: cache keys include query, parameters, and row limit
   - Consider cache invalidation needs for your use case

7. **Monitor and Audit**
   - Enable debug logging in production (log to file, not screen)
   - Regularly review query logs for suspicious patterns
   - Monitor for failed authentication attempts
   - Set up alerts for repeated query failures

### For Development

1. **Test with Separate Databases**
   - Never point development extensions at production databases
   - Use sample data or database copies

2. **Review Query Logs**
   - Check debug logs to see actual SQL being executed
   - Verify parameters are properly bound in prepared statements

3. **Test Error Conditions**
   - Verify error messages don't leak sensitive information
   - Test with invalid tokens to confirm CSRF protection
   - Try SQL injection patterns to verify sanitization

## Known Limitations

1. **Driver-Dependent Features**
   - Query timeout support varies by ODBC driver
   - LIMIT syntax is chosen based on the configured driver: SQL Server uses `TOP n`, Progress OpenEdge uses `SELECT FIRST n`, and all other drivers use `LIMIT n` appended after the ORDER BY clause
   - Some metadata operations may not work with all drivers

2. **Encoding Detection**
   - Automatic encoding conversion uses `mb_detect_encoding` which may occasionally misdetect
   - Best practice: ensure database returns UTF-8 when possible

3. **Prepared Statement Parameters**
   - Parameter types are not explicitly set (relies on ODBC driver inference)
   - May have issues with binary data or certain date/time formats
   - Test thoroughly with your specific database and driver

## Security Release History

### Version 1.2.0 (2026-03-03)
- Error message HTML now correctly flagged as `isHTML` — prevents edge-case re-parsing of error spans as wikitext (§5.2 fix)
- Query execution timing added to `odbc` debug log; slow-query logging added via `odbc-slow` channel (controlled by new `$wgODBCSlowQueryThreshold` config key)
- `withOdbcWarnings()` DRY refactor completed — five raw `set_error_handler` closures removed from `ODBCQueryRunner` and `EDConnectorOdbcGeneric`
- SELECT * observability: `wfDebugLog` warning emitted when `data=` omitted in `{{#odbc_query:}}`

### Version 1.1.0 (2026-03-03)
- UNION word-boundary matching fix (KI-024) — identifiers like `TRADE_UNION_ID` no longer falsely trigger the sanitizer
- Connection string value escaping implemented (KI-025) — `;`, `{`, `}` in passwords and server names are now correctly escaped per the ODBC specification
- SQL sanitizer word-boundary fix for all DDL/DML keywords (KI-032) — keywords now use `\b` boundaries; false positives on identifiers that contain but don't start with blocked keywords are eliminated
- `validateConfig()` now called before every connection attempt (KI-026)
- External Data connector inherits driver from referenced `$wgODBCSources` entry (KI-027)
- Per-page query limit added via `$wgODBCMaxQueriesPerPage` (KI-018) — prevents runaway template-driven query floods
- Query timeout failures now logged instead of silently discarded (KI-033)

### Version 1.0.3 (2026-03-02)
- Cache key collision fix — prevented potential cross-user data leakage when caching was enabled
- `#` MySQL line comment character added to sanitizer blocklist
- `$wgODBCMaxRows` enforced in External Data connector (previously bypassed)
- Connection liveness detection added (ping before reuse) to prevent stale handle errors
- Connection pool size limit enforced via configurable `$wgODBCMaxConnections`

### Version 1.0.2 (March 2026)
- XSS fix in `#display_odbc_table` column output — values now HTML-escaped before wikitext emission
- Wikitext injection fix in `escapeTemplateParam` — template parameter values properly escaped
- `UNION` keyword added to SQL sanitizer blocklist
- Password stripped from ODBC error messages shown to end users
- CSRF token validation added to admin POST actions

### Version 1.0.1 (March 2026)
- Added SQL identifier validation to prevent injection via column names
- Implemented connection health checks to prevent stale connection reuse
- Added credential sanitization in error messages
- Improved CSRF token validation consistency
- Enhanced encoding detection with more character sets
- Fixed resource leaks in error paths
- Added query logging for audit trails
- Enforced connection pool size limits (max 10)
- Improved control character stripping in sanitizer
- Fixed LIMIT enforcement (now applies in SQL, not just fetching)

### Version 1.0.0 (Initial Release)
- Basic SQL injection protection via keyword blocklist
- CSRF protection for admin interface
- Permission system (odbc-query, odbc-admin)
- Prepared statement support
- Query result caching
