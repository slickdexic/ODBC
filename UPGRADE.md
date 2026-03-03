# Upgrade Guide

## Upgrading to 1.3.0 from 1.2.0

Version 1.3.0 is a quality and security-consistency release. All users are recommended to upgrade.

### Breaking Changes

**None.** Version 1.3.0 is fully backward compatible with 1.2.0.

### Security: `Special:ODBCAdmin` Now Respects `$wgODBCAllowArbitraryQueries`

In previous versions, administrators could run test queries from Special:ODBCAdmin even when `$wgODBCAllowArbitraryQueries = false`. The admin interface now consistently enforces the same arbitrary-query policy as the parser functions. If you have operators who need to run test queries via the admin page, ensure either `$wgODBCAllowArbitraryQueries = true` or that the relevant ODBC source has `'allow_queries' => true` in `$wgODBCSources`.

### Internal Improvements

- **`extension.json` `callback` replaced with `ExtensionRegistration` hook** — Removes a deprecated manifest key. No visible change for operators.
- **`$mainConfig` cached in `ODBCQueryRunner`** — Minor performance improvement for pages with multiple `{{#odbc_query:}}` calls.
- **`data=` dropped-pair logging** — Oversized `data=` mapping pairs now emit a `wfDebugLog('odbc', ...)` entry instead of silently disappearing.
- **Admin SQL textarea width changed to CSS** — `cols="80"` replaced with `style="width: 100%"` for proper responsive layout.

### Upgrade Steps

1. Replace all files in `extensions/ODBC/` with the new version (or `git pull`).
2. Clear the PHP opcode cache (restart PHP-FPM or Apache) and any object/parser caches.
3. If using `$wgODBCAllowArbitraryQueries = false`, verify the new admin-page policy matches your expectations (see Security section above).
4. Test connections via Special:ODBCAdmin.

---

## Upgrading to 1.2.0 from 1.1.0

Version 1.2.0 is a quality and feature release. All users are recommended to upgrade.

### Breaking Changes

**None.** Version 1.2.0 is fully backward compatible with 1.1.0.

### New: `row=` Parameter for `{{#odbc_value:}}`

`{{#odbc_value:}}` now accepts an optional third parameter to select which row to retrieve instead of always returning the first row:

```wiki
{{#odbc_value: varName | default | 2 }}      — row 2 (1-indexed)
{{#odbc_value: varName | default | last }}   — final row
{{#odbc_value: varName | default | row=3 }}  — named form
```

Omitting the parameter still returns the first row — **no changes needed to existing templates**.

### New: Slow-Query Logging (`$wgODBCSlowQueryThreshold`)

A new optional configuration key enables slow-query logging. When a query's combined execute + fetch time exceeds the threshold, it is written to the `odbc-slow` log channel:

```php
// Log queries that take longer than 2 seconds (default: 0 = disabled)
$wgODBCSlowQueryThreshold = 2.0;

// Route the slow-query channel to a dedicated log file:
$wgDebugLogGroups['odbc-slow'] = '/var/log/mediawiki/odbc-slow.log';
```

Leave at `0` (the default) if you do not need slow-query visibility.

### What's Fixed

1. **Error messages now correctly marked as HTML** — parser function error outputs (permission denied, too many queries, etc.) were previously returned with `'noparse' => false`, which could cause the HTML error span to be re-processed as wikitext. All error returns now use `'noparse' => true, 'isHTML' => true`. No visible change for end users; this prevents potential edge-case output corruption.
2. **Query execution time added to debug log** — every query in the `odbc` debug log now includes its execution time in seconds, e.g. `— Returned 42 rows in 0.083s`.
3. **Documentation corrections** — `$wgODBCMaxConnections` "per source" description corrected in six locations; `wiki/Architecture.md` LRU/WANObjectCache errors corrected; `wiki/Known-Issues.md` KI-020 partial-fix status updated; `odbc-error-too-many-queries` i18n message corrected.
4. **`withOdbcWarnings()` DRY refactor completed** — all five raw `set_error_handler` closures in `ODBCQueryRunner` and `EDConnectorOdbcGeneric` replaced with the shared helper.
5. **SELECT * visibility** — `wfDebugLog` warning emitted whenever `data=` is omitted in `{{#odbc_query:}}` and a `SELECT *` is issued.

### Upgrade Steps

1. Replace all files in `extensions/ODBC/` with the new version (or `git pull`).
2. Clear the PHP opcode cache (restart PHP-FPM or Apache) and any object/parser caches.
3. Test connections via Special:ODBCAdmin.
4. Optionally configure `$wgODBCSlowQueryThreshold` if you want slow-query logging.

---

## Upgrading to 1.1.0 from 1.0.3

Version 1.1.0 is a bug-fix and feature release. All users are recommended to upgrade — especially those using MS Access, External Data integration, or identifiers containing "union".

### Breaking Changes

**None.** Version 1.1.0 is fully backward compatible with 1.0.3.

### New: Progress OpenEdge Support

Progress OpenEdge databases are now fully supported via ODBC. The extension detects Progress drivers (names containing "progress" or "openedge") and automatically uses `SELECT FIRST n` row-limit syntax.

Progress uses different connection-string keys than other databases. When using driver/host/port/db mode (not DSN), use `host` and `db` instead of `server` and `database`:

```php
$wgODBCSources['progress-erp'] = [
    'driver'   => 'Progress OpenEdge 11.7 Driver',
    'host'     => 'db.example.com',   // Progress uses Host=, not Server=
    'port'     => '32770',
    'db'       => 'sports2000',       // Progress uses DB=, not Database=
    'user'     => 'admin',
    'password' => 'pw',
];
```

### New: Per-Page Query Limit

A new optional configuration key `$wgODBCMaxQueriesPerPage` (default: `0` — no limit) caps how many `{{#odbc_query:}}` calls can be made per page render. This prevents runaway templates from exhausting database resources:

```php
// Allow at most 20 database queries per page (optional — disabled by default)
$wgODBCMaxQueriesPerPage = 20;
```

When the limit is reached, subsequent calls on the same page return an error; earlier calls are unaffected. The limit applies per page render. If your pages legitimately require more than the default-unlimited behaviour, leave `$wgODBCMaxQueriesPerPage` at `0`.

### What's Fixed

1. **MS Access connection pooling fixed (KI-023)** — the liveness probe now uses `SELECT 1 FROM MSysObjects WHERE 1=0` so Access connections are correctly cached.
2. **UNION no longer blocks valid identifiers (KI-024)** — table/column names containing "union" (e.g., `TRADE_UNION_ID`) no longer trigger the SQL sanitizer.
3. **Connection string escaping fixed (KI-025)** — passwords and server names containing `;`, `{`, or `}` are now correctly escaped per the ODBC specification.
4. **`validateConfig()` is now active (KI-026)** — missing required configuration keys now produce a clear localised error instead of an obscure ODBC driver error.
5. **ED connector `odbc_source` mode inherits driver (KI-027)** — SQL Server/Progress sources accessed via External Data now get correct `TOP`/`FIRST` syntax automatically.
6. **`$wgODBCExternalDataIntegration = 0` now works (KI-028)** — any falsy value (not just literal `false`) now correctly disables ED integration.
7. **Sanitizer word-boundary matching fixed (KI-032)** — all blocked keywords now use strict `\b` word boundaries on both sides.
8. **`validateConfig()` now accepts `host` for Progress OpenEdge (KI-040)** — configurations using `host` + `db` are no longer rejected before reaching connection-string building.
9. **Query timeout failures now logged (KI-033)** — if a driver does not support per-statement timeouts, the failure is recorded in the ODBC debug log instead of being silently discarded.

### Upgrade Steps

1. Replace all files in `extensions/ODBC/` with the new version (or `git pull`).
2. Clear the PHP opcode cache (restart PHP-FPM or Apache) and any object/parser caches.
3. Test connections via Special:ODBCAdmin.

---

## Upgrading to 1.0.3 from 1.0.2

Version 1.0.3 is a hotfix release. All users are strongly recommended to upgrade — especially those using SQL Server or External Data integration.

### Breaking Changes

**None.** Version 1.0.3 is fully backward compatible with 1.0.2.

### New Configuration Key

A new optional configuration key `$wgODBCMaxConnections` has been added (default: `10`). It controls the maximum number of simultaneous ODBC connections across all sources combined. If you previously relied on the hard-coded limit of `10`, no action is needed — the default is unchanged:

```php
// Optional: override the default
$wgODBCMaxConnections = 10;
```

### What's Fixed

1. **Magic words are now case-insensitive** — `{{#ODBC_QUERY:}}` and `{{#odbc_query:}}` both work correctly in all MediaWiki versions.
2. **SQL Server / Access queries via External Data now use `TOP N` syntax** — previously a bare `LIMIT N` was emitted, which is not valid T-SQL.
3. **`$wgODBCMaxRows` is now enforced in the External Data connector** — previously uncapped result sets could be returned.
4. **Connection liveness check fixed** — the previous check (`odbc_error() === ''`) could report a stale connection as healthy. Connections are now validated with a real `SELECT 1` probe.
5. **Query timeout now applied at statement level** — the previous connection-level timeout had no effect on most ODBC drivers.
6. **Cache key collision fixed** — parameters `['a,b','c']` and `['a','b,c']` now produce different cache keys.
7. **SQL injection blocklist expanded** — `#`, `WAITFOR`, `SLEEP()`, `PG_SLEEP()`, `BENCHMARK()`, `DECLARE`, `UTL_FILE`, and `UTL_HTTP` are now blocked.
8. **Column browser enriched** — Special:ODBCAdmin now shows column type, size, and nullability in addition to name.
9. **`mergeResults()` performance improved** — O(n×m×p) loop replaced with O(n×m) using a per-row lowercase map.

### Upgrade Steps

1. Replace all files in `extensions/ODBC/` with the new version (or `git pull`).
2. Clear the PHP opcode cache (restart PHP-FPM or Apache) and any object/parser caches.
3. Test connections via Special:ODBCAdmin.

---

## Upgrading to 1.0.1 from 1.0.0

Version 1.0.1 includes critical security fixes and improvements. All users are strongly encouraged to upgrade.

### Breaking Changes

**None.** Version 1.0.1 is fully backward compatible with 1.0.0.

### What's Changed

#### Security Fixes (Action Recommended)

1. **SQL Injection Protection Enhanced**
   - Column and table identifiers are now strictly validated
   - Special characters in identifiers are blocked
   - **Action**: Review your queries if you use unusual column/table names (e.g., with spaces or special chars)

2. **Password Exposure Fixed**
   - Passwords are now stripped from error messages
   - **Action**: None required, but review your logs to ensure no credentials were previously exposed

3. **CSRF Protection Improved**  
   - Token validation is now consistent across all admin actions
   - **Action**: None required, users may need to retry failed admin actions after upgrade

#### Functional Improvements

1. **LIMIT Enforcement Fixed**
   - Queries now properly enforce row limits in SQL, not just post-fetch
   - **Action**: Some queries may return fewer results than before if they exceeded limits
   - **Impact**: Improved performance (database processes fewer rows)

2. **Magic Word Case Sensitivity — note** — v1.0.1 changed magic word flags from `0` (case-insensitive) to `1` (case-sensitive), inadvertently making the situation **worse**: uppercase variants stopped working entirely. This was reversed in **v1.0.3**, which restored case-insensitive matching. `{{#ODBC_QUERY:}}` and all mixed-case forms only work correctly from **v1.0.3** onwards.
   - **Action**: If you are on v1.0.1 or v1.0.2, upgrade to v1.0.3 or later. On v1.0.3+, both cases work.

3. **Connection Health Checks Added**
   - Stale connections are automatically detected and replaced
   - **Action**: None required, may see slightly more reconnections in logs

4. **Resource Leak Fixes**
   - ODBC result resources are now properly freed in all error cases
   - **Action**: Monitor for improved memory usage under error conditions

### Configuration Changes

No configuration changes are required. All existing configuration remains valid.

#### Optional: Enhanced Configuration

You may want to take advantage of new features:

```php
// Example: Enhanced source configuration
$wgODBCSources['my-db'] = [
    'driver' => 'ODBC Driver 17 for SQL Server',
    'server' => 'localhost,1433',
    'database' => 'MyDB',
    'user' => 'readonly',
    'password' => 'secret',
    
    // NEW: Named prepared statements for better security
    'prepared' => [
        'get_user' => 'SELECT * FROM users WHERE id = ?',
        'search' => 'SELECT * FROM items WHERE category = ? AND name LIKE ?',
    ],
    
    // Existing options continue to work as before
    'timeout' => 30,
    'allow_queries' => false,  // Recommended: keep false for security
];
```

### Upgrade Steps

1. **Backup Your Data**
   - Backup your `LocalSettings.php`
   - Backup your database (MediaWiki wiki database, not ODBC sources)

2. **Review Current Queries**
   - Check if any queries use unusual identifiers (spaces, special characters)
   - Test queries in Special:ODBCAdmin before deploying to pages

3. **Update Extension Files**
   - Replace all files in `extensions/ODBC/` with new version
   - Or use `git pull` if using git

4. **Clear Caches**
   - MediaWiki parser cache: `php maintenance/rebuildall.php`
   - PHP opcode cache: Restart PHP-FPM or Apache

5. **Test Functionality**
   - Visit Special:ODBCAdmin and test connections
   - Run test queries through the admin interface
   - View pages that use ODBC parser functions
   - Check error logs for any issues

6. **Review Security Settings**
   - Verify `$wgODBCAllowArbitraryQueries` is still `false`
   - Review `allow_queries` settings on individual sources
   - Consider migrating to prepared statements if using ad-hoc queries

### Post-Upgrade Verification

Run these checks after upgrading:

1. **Connection Test**
   ```
   Navigate to Special:ODBCAdmin
   Click "Test Connection" for each configured source
   Verify all show success messages
   ```

2. **Query Test**
   ```
   In Special:ODBCAdmin, run a simple query:
   SELECT * FROM your_table LIMIT 1
   Verify results are displayed correctly
   ```

3. **Parser Function Test**
   ```
   Create a test page with:
   {{#odbc_query: source=your-source | from=your_table | data=col1,col2 | limit=1 }}
   {{#odbc_value:col1}}
   
   Verify data is displayed
   Verify no errors appear
   ```

4. **Permission Test**
   ```
   Log in as a non-admin user (if odbc-query is granted to them)
   Verify they can run queries
   Verify they cannot access Special:ODBCAdmin (unless odbc-admin granted)
   ```

### Troubleshooting Upgrade Issues

#### "Invalid identifier" errors after upgrade

**Cause**: Version 1.0.1 enforces stricter identifier validation.

**Solution**: 
- Check column/table names in your queries
- Ensure they contain only: letters, numbers, underscores, dots
- Maximum 128 characters per identifier
- If you need special characters, use prepared statements and let the database handle it

#### Queries return fewer results than before

**Cause**: LIMIT enforcement is now correct (applied in SQL, not post-fetch).

**Solution**:
- Increase the `limit=` parameter in your query
- Check `$wgODBCMaxRows` setting (default 1000)
- Use `ORDER BY` to control which rows are returned

#### Connection failures after upgrade

**Cause**: Connection health checks may detect previously-ignored stale connections.

**Solution**:
- Restart your database server
- Check connection parameters in `$wgODBCSources`
- Verify network connectivity
- Review ODBC driver logs

#### Admin interface shows "Invalid token" errors

**Cause**: Improved CSRF protection may require token refresh.

**Solution**:
- Refresh the page and try again
- Clear your browser cookies for the wiki
- Log out and log back in

### Migrating to Prepared Statements (Recommended)

If you're currently using `allow_queries: true`, consider migrating to prepared statements for better security:

**Before (1.0.0 - less secure):**
```php
$wgODBCSources['my-db'] = [
    'driver' => 'ODBC Driver 17 for SQL Server',
    'server' => 'localhost,1433',
    'database' => 'MyDB',
    'user' => 'user',
    'password' => 'pass',
    'allow_queries' => true,  // Allows arbitrary SQL
];
```

Wiki page:
```wiki
{{#odbc_query: source=my-db | from=users | where=id=123 | data=name,email }}
```

**After (1.0.1 - more secure):**
```php
$wgODBCSources['my-db'] = [
    'driver' => 'ODBC Driver 17 for SQL Server',
    'server' => 'localhost,1433',
    'database' => 'MyDB',
    'user' => 'user',
    'password' => 'pass',
    'allow_queries' => false,  // Better: disallow ad-hoc queries
    'prepared' => [
        'get_user' => 'SELECT name, email FROM users WHERE id = ?',
    ],
];
```

Wiki page:
```wiki
{{#odbc_query: source=my-db | query=get_user | parameters=123 | data=name,email }}
```

Benefits:
- Eliminates SQL injection risk
- Better performance (query plan caching)
- Easier to audit (fixed query list in config)
- Clearer separation of concerns

### Rollback Instructions

If you encounter issues and need to rollback to 1.0.0:

1. **Backup Current Version**
   - Keep a copy of version 1.0.1 files for future upgrade attempt

2. **Restore 1.0.0 Files**
   - Replace all files in `extensions/ODBC/` with 1.0.0 version

3. **Clear Caches**
   - Clear object cache
   - Restart PHP

4. **Restore Configuration**
   - Restore your `LocalSettings.php` backup
   - Note: 1.0.0 configuration is compatible with 1.0.1, so no changes needed

5. **Report Issues**
   - File a bug report with details of the issue
   - Include PHP version, MediaWiki version, ODBC driver info
   - Include (sanitized) error logs

### Getting Help

If you encounter issues during upgrade:

1. Check the [Troubleshooting section](README.md#troubleshooting) in README.md
2. Review [SECURITY.md](SECURITY.md) for security best practices
3. Check [CHANGELOG.md](CHANGELOG.md) for detailed change list
4. File an issue on GitHub with:
   - MediaWiki version
   - PHP version
   - ODBC driver name and version
   - Operating system
   - Error messages (sanitized to remove credentials)
   - Steps to reproduce

### Important Security Note

Version 1.0.1 fixes a critical SQL injection vulnerability. **Do not skip this upgrade** if you have `$wgODBCAllowArbitraryQueries = true` or any source with `allow_queries: true`.

Even if you only use prepared statements, upgrading is recommended for the other improvements and fixes.
