# Known Issues

This document lists known bugs, limitations, and inaccuracies in the MediaWiki ODBC Extension.

Issues are grouped by severity. Each entry includes a description, the cause, the impact, and where applicable a workaround until the issue is properly fixed.

---

## Fixed in v1.0.3

The following issues were resolved in v1.0.3 (see [CHANGELOG.md](CHANGELOG.md) for details):

| ID | Summary |
|----|---------|
| KI-001 | Magic words case-insensitive flag corrected |
| KI-002 | Cache key collision fixed (`json_encode` instead of `implode`) |
| KI-003 | ED connector LIMIT vs TOP syntax corrected |
| KI-004 | `$wgODBCMaxRows` now enforced in ED connector |
| KI-005 | Connection liveness check replaced with `SELECT 1` ping |
| KI-006 | Query timeout moved to statement level |
| KI-007 | `getTableColumns()` now uses `array_change_key_case()` for driver portability |
| KI-009 | Indentation bug in `executeRawQuery()` fixed |
| KI-010 | Duplicate column loop in `executeComposed()` merged |
| KI-011 | Stray email removed from README |
| KI-012 | `UPGRADE.md` maintenance script corrected |
| KI-013 | `SECURITY.md` CSRF documentation corrected |
| KI-014 | README magic word claim now accurate (case-insensitive fixed at source) |
| KI-015 | `SECURITY.md` Known Limitations updated |
| KI-016 | `LICENSE` file created |
| KI-017 | `UPGRADE.md` now includes v1.0.3 upgrade notes |
| KI-021 | `$wgODBCMaxConnections` config key added |
| KI-022 | Column browser now shows type, size, and nullable |

---

## Critical Bugs

### KI-001 ” Magic Words Are Case-Sensitive (Only Lowercase Works)

**Severity:** Critical  
**File:** `ODBCMagic.php`  
**Status:** ✅ Fixed in v1.0.3 ” flag changed from `1` (case-sensitive) to `0` (case-insensitive) in `ODBCMagic.php`.

**Description:**  
All magic words are declared with case-sensitivity flag `1`:

```php
'odbc_query' => [ 1, 'odbc_query' ],
```

In MediaWiki's magic word system, `1` means **case-sensitive** ” only the exact listed string is recognised. This means `{{#ODBC_QUERY:}}`, `{{#Odbc_Query:}}`, and all other case variants **do not work**. Only lowercase `{{#odbc_query:}}` (and corresponding lowercase for all other functions) is recognised.

**CHANGELOG claim:** "Fixed case sensitivity in magic words (changed from 0 to 1) ” now `{{#ODBC_QUERY:}}` works correctly."  
**Actual effect:** Changing `0` → `1` made things *more* restrictive. The statement in CHANGELOG and README is factually wrong.

**Impact:** Editors using uppercase or mixed-case parser function names get silent failures ” the parser function name appears as literal text on the page instead of executing.

**Workaround:** Always use exact lowercase for all parser functions: `{{#odbc_query:}}`, `{{#odbc_value:}}`, `{{#for_odbc_table:}}`, `{{#display_odbc_table:}}`, `{{#odbc_clear:}}`.

**Fix:** Change all `1` values to `0` in `ODBCMagic.php`. This makes the magic words case-insensitive, as the documentation claims they are.

---

### KI-002 ” Cache Key Collision for Prepared Statement Parameters

**Severity:** Critical  
**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`  
**Description:**  
The cache key for query results is built as:

```php
md5( $sql . '|' . implode( ',', $params ) . '|' . $maxRows )
```

`implode(',', $params)` cannot distinguish between `['a,b', 'c']` and `['a', 'b,c']`. These two different parameter sets produce identical cache keys but would return different data from the database. If caching is enabled (`$wgODBCCacheExpiry > 0`), the first query to execute gets cached and subsequent queries with different-but-collision-matched parameters silently receive stale/wrong data.

**Impact:** Silent data corruption when caching is enabled and prepared statement parameters happen to contain commas (e.g., user names, addresses, comma-separated values).

**Status:** ✅ Fixed in v1.0.3 ” replaced `implode(',', $params)` with `json_encode($params)` in cache key.

---

## High-Priority Bugs

### KI-003 ” External Data Connector Always Uses LIMIT Syntax (Breaks SQL Server / MS Access)

**Severity:** High  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `limit()` method  
**Description:**  
The External Data connector always generates `LIMIT n` in its SQL template, regardless of the database engine. SQL Server and MS Access do not support `LIMIT` ” they use `TOP n` in the SELECT clause. This was fixed for the native parser functions in v1.0.2 (`requiresTopSyntax()`), but the ED connector was not updated.

**Impact:** Using `{{#get_db_data:}}` or other External Data parser functions with an ODBC SQL Server or MS Access source fails with a driver query error.

**Status:** ✅ Fixed in v1.0.3 ” `getQuery()` now calls `ODBCQueryRunner::requiresTopSyntax()` and emits `SELECT TOP N` for SQL Server / Access / Sybase.

---

### KI-004 ” `$wgODBCMaxRows` Not Enforced via External Data Connector

**Severity:** High  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `fetch()`  
**Description:**  
When the External Data connector uses `odbc_source` mode (referencing a `$wgODBCSources` entry), it obtains the raw ODBC connection and executes queries directly via `odbc_exec()`, bypassing `ODBCQueryRunner::executeRawQuery()`. The global row limit (`$wgODBCMaxRows`) is never applied in this code path.

**Impact:** Queries executed via External Data's parser functions may return unlimited rows, potentially causing memory exhaustion or performance issues contrary to the configured global limit.

**Status:** ✅ Fixed in v1.0.3 — `fetch()` now reads `$wgODBCMaxRows` and stops fetching once the limit is reached.

---

### KI-005 — Connection Liveness Check Does Not Detect Dead Connections

**Severity:** High  
**File:** `includes/ODBCConnectionManager.php`, `connect()` and `EDConnectorOdbcGeneric::connect()`  
**Description:**  
The code checks for a live connection by calling `odbc_error()` and testing whether the result is an empty string:

```php
if ( @odbc_error( self::$connections[$sourceId] ) === '' ) {
    return self::$connections[$sourceId];
}
```

`odbc_error()` returns the **last recorded error string** on the handle. An empty string means no error was previously recorded ” not that the connection is currently alive. A connection that has been killed by the database server (idle timeout, network reset, etc.) will still pass this check, causing subsequent queries to fail unexpectedly.

**Impact:** Under PHP-FPM where worker processes may handle multiple requests, or when connections are held during long operations, dead connections can be returned from the cache causing hard-to-reproduce query failures.

**Status:** ✅ Fixed in v1.0.3 ” connection liveness now validated by `pingConnection()` (`SELECT 1` probe) before returning a cached handle.

---

### KI-006 ” Query Timeout Likely Does Not Work

**Severity:** High  
**File:** `includes/ODBCConnectionManager.php`, `connect()`  
**Description:**  
The connection timeout is set via:

```php
$result = @odbc_setoption( $conn, self::SQL_HANDLE_DBC,
    self::SQL_ATTR_QUERY_TIMEOUT, (int)$timeout );
```

`odbc_setoption()` with handle type `SQL_HANDLE_DBC` (connection handle) and option `0` attempts to set `SQL_QUERY_TIMEOUT` on the connection. The ODBC standard defines query timeout as a **statement-level** attribute, not a connection-level attribute. Most drivers ignore this setting silently and the function returns `false`. The code logs "Failed to set query timeout" only when `$result` is falsy, but many drivers return `true` while ignoring the option.

**Impact:** The `$wgODBCQueryTimeout` and per-source `timeout` configuration values have no effect in practice for most ODBC drivers. Long-running queries will not be time-bounded.

**Status:** ✅ Fixed in v1.0.3 ” timeout is now applied at statement level via `odbc_setoption($stmt, SQL_HANDLE_STMT, SQL_QUERY_TIMEOUT, $timeout)` after `odbc_prepare()`.

---

## Moderate Bugs

### KI-007 ” `getTableColumns()` Drops Columns with Mixed-Case Column Name Keys

**Severity:** Moderate  
**File:** `includes/ODBCQueryRunner.php`, `getTableColumns()`  
**Description:**  

```php
$result[] = $row['COLUMN_NAME'] ?? $row['column_name'] ?? '';
```

Only two case variants of the numeric/metadata column name key are checked. Some ODBC drivers return mixed-case keys such as `Column_Name`. When this occurs, both lookups miss, an empty string is appended, and `array_filter()` discards it ” silently dropping columns from the result.

**Status:** ✅ Fixed in v1.0.3 ” `getTableColumns()` now uses `array_change_key_case($row, CASE_LOWER)` and returns structured arrays with `name`, `type`, `size`, and `nullable` keys.

---

### KI-008 — `SELECT *` Issued When No `data=` Parameter Is Provided

**Severity:** Moderate  
**File:** `includes/ODBCParserFunctions.php` and `includes/ODBCQueryRunner.php`  
**Status:** ⚠ Partially addressed in v1.2.0 — `ODBCParserFunctions::odbcQuery()` now emits a `wfDebugLog('odbc', ...)` warning when `data=` is omitted so operators can audit exposure via the `odbc` log channel. The underlying behaviour (issuing `SELECT *`) is unchanged; using a wiki-facing error or restriction is a v2.0.0 concern.

**Description:**  
When `{{#odbc_query:}}` is called without a `data=` parameter, `SELECT *` is issued and all columns are fetched and stored. For wide tables, this can return sensitive columns unintentionally and consume significant memory.

**Workaround:** Always specify explicit `data=` column mappings in `{{#odbc_query:}}`.

---

### KI-009 ” Inconsistent Indentation in `executeRawQuery()`

**Severity:** Minor  
**File:** `includes/ODBCQueryRunner.php`, approx. line 263  
**Status:** ✅ Fixed in v1.0.3 ” indentation corrected.

---

### KI-010 ” Duplicate Column-Iteration Loop in `executeComposed()`

**Severity:** Minor  
**File:** `includes/ODBCQueryRunner.php`, `executeComposed()`  
**Status:** ✅ Fixed in v1.0.3 ” the two loops are merged into a single pass; variable naming is now consistent.

---

## Documentation Errors

### KI-011 ” Stray Email Address in README Security Section

**Severity:** Documentation Error  
**File:** `README.md`, "Important Security Note" section  
**Status:** ✅ Fixed in v1.0.3 ” stray email removed.

---

### KI-012 ” UPGRADE.md Cites Wrong Maintenance Script for Cache Clearing

**Severity:** Documentation Error  
**File:** `UPGRADE.md`, "Upgrade Steps" section  
**Description:**  
Step 4 of the upgrade instructions states:

```
MediaWiki object cache: php maintenance/rebuildrecentchanges.php
```

**Status:** ✅ Fixed in v1.0.3 ” corrected to `php maintenance/rebuildall.php`.

---

### KI-013 ” SECURITY.md Documents Removed CSRF Token Behaviour as Current

**Severity:** Documentation Error  
**File:** `SECURITY.md`, CSRF Protection section  
**Description:**  
The SECURITY.md CSRF Protection section states: _"Tokens are validated for both GET (with `token` parameter) and POST (with `wpEditToken`) requests."_

**Status:** ✅ Fixed in v1.0.3 ” SECURITY.md updated to correctly document that only POST `runquery` validates a CSRF token.

---

### KI-014 ” README Claims Uppercase Magic Words Work (They Do Not)

**Severity:** Documentation Error  
**File:** `README.md`, Troubleshooting section; `CHANGELOG.md`, v1.0.1 section  
**Description:**  
**Status:** ✅ Fixed in v1.0.3 ” magic word flags corrected to `0` (case-insensitive); uppercase variants now genuinely work.

---

### KI-015 ” SECURITY.md "Known Limitations" Section Is Outdated

**Severity:** Documentation Issue  
**File:** `SECURITY.md`, Known Limitations section  
**Description:**  
**Status:** ✅ Fixed in v1.0.3 ” SECURITY.md Known Limitations section updated.

---

### KI-016 ” No LICENSE File in Repository

**Severity:** Documentation Issue  
**File:** Repository root  
**Description:**  
**Status:** ✅ Fixed in v1.0.3 ” `LICENSE` file created with GPL-2.0 text.

---

### KI-017 ” No Upgrade Notes for v1.0.2 in UPGRADE.md

**Severity:** Documentation Issue  
**File:** `UPGRADE.md`  
**Description:**  
**Status:** ✅ Fixed in v1.0.3 ” `UPGRADE.md` now includes a v1.0.3 upgrade section.

---

## Functional Limitations

### KI-018 ” No Per-Page Query Count Limit

**Severity:** Design Limitation  
**Status:** Fixed in v1.1.0 — `$wgODBCMaxQueriesPerPage` configuration key added (default: `0` = no limit); per-page counter stored in `ParserOutput` extension data; limit check inserted in `odbcQuery()` after permission check

**Description:**  
A wiki page can invoke `{{#odbc_query:}}` any number of times ” including through template transclusions. There is no limit on how many ODBC queries execute per page render. A user with `odbc-query` permission could craft a heavily-transcluded template that generates many database queries per page view, constituting a targeted database denial-of-service.

**Workaround:** Restrict `odbc-query` permission only to highly trusted editors. Monitor query rates in the debug log.

---

### KI-019 — No Access to Specific Row Values from `{{#odbc_value:}}`

**Severity:** Functional Limitation  
**Status:** ✅ Fixed in v1.2.0 — `{{#odbc_value:}}` now accepts an optional third parameter for row selection. Examples: `{{#odbc_value:varName|default|2}}` (row 2, 1-indexed), `{{#odbc_value:varName|default|last}}` (final row), `{{#odbc_value:varName|default|row=3}}` (named form). Out-of-range indices return the default value silently.

**Description:**  
`{{#odbc_value:varName}}` previously returned only the first row's value. There was no mechanism to access specific row indices without a loop construct.

---

### KI-020 ” External Data Connector Does Not Apply Caching or Encoding Conversion

**Severity:** Functional Limitation  
**Status:** Partially fixed in v1.1.0 (2026-03-03) — when the connector is bridging a `$wgODBCSources` entry (`odbc_source` mode), queries are now routed through `ODBCQueryRunner::executeRawQuery()`, gaining `$wgODBCCacheExpiry` result caching, UTF-8 encoding conversion, and audit logging. Standalone External Data connections still do not cache but do apply UTF-8 conversion (P2-016).

**Description:**  
When queries are executed via the External Data connector, neither `$wgODBCCacheExpiry` result caching nor UTF-8 encoding detection/conversion is applied. This creates an inconsistency between the native parser function code path and the ED connector code path for the same ODBC source.

---

### KI-021 ” `MAX_CONNECTIONS` Is Not User-Configurable

**Severity:** Functional Limitation  
**File:** `includes/ODBCConnectionManager.php`  

**Status:** ✅ Fixed in v1.0.3 ” `$wgODBCMaxConnections` config key added (default: `10`).

---

### KI-022 ” `Special:ODBCAdmin` Column Browser Shows Only Column Names

**Severity:** Functional Limitation  
**File:** `includes/specials/SpecialODBCAdmin.php`  

**Status:** ✅ Fixed in v1.0.3 ” column browser now shows SQL type, size/precision, and nullability in a sortable table.

---

---

## Open Bugs (Discovered in v1.0.3 and 2026-03-03 Re-Review)

### KI-023 — `pingConnection()` Fails on MS Access

**Severity:** High  
**File:** `includes/ODBCConnectionManager.php`, `pingConnection()`  
**Status:** ✅ Fixed in v1.1.0 (P2-017)

**Fix:** `pingConnection()` now detects MS Access drivers (names containing "access") and uses `SELECT 1 FROM MSysObjects WHERE 1=0` instead of bare `SELECT 1`. Connection pooling works correctly for Access sources.

~~**Description:**  
The connection liveness probe executes `SELECT 1` against the cached connection handle. MS Access (Jet/ACE engine) does not support `SELECT 1` without a `FROM` clause — it requires a table reference. As a result, every cached connection to an MS Access source will fail the ping, be discarded, and a fresh connection will be opened on every subsequent query. This defeats the connection pool for the most common "desktop database" use case.~~

**Impact:** Repeated reconnection overhead for all MS Access users. Potential resource exhaustion if the driver enforces a maximum connection count per process.

**Workaround:** None available to wiki editors. Operators can work around the issue by increasing `$wgODBCMaxConnections` to compensate for the constant re-opens, but the root cause remains.

---

### KI-024 — `sanitize()` Blocks Valid Identifiers Containing the Substring "UNION"

**Severity:** Moderate  
**File:** `includes/ODBCQueryRunner.php`, `sanitize()`  
**Status:** ✅ Fixed in v1.1.0 (P2-018)

**Fix:** `UNION` moved from `$charPatterns` (substring `strpos()` match) to `$keywords` list (word-boundary regex `/\bUNION\b/i`). Identifiers containing "union" as a substring are no longer blocked.

~~**Description:**  
`UNION` is in the `$charPatterns` list and matched via `strpos()` (substring match), not with a word-boundary regex like the other keywords. Any table name, column name, alias, or value containing the substring `UNION` is rejected.~~

**Impact:** False-positive rejections of legitimate queries. Wiki editors using identifiers that contain "union" as a substring receive `"Illegal SQL pattern 'UNION'"` with no workaround.

**Workaround:** Rename database columns or tables to avoid the substring, or use a prepared statement (which bypasses `sanitize()` for the SQL itself).

---

### KI-025 — `buildConnectionString()` Does Not Escape Special Characters in Values

**Severity:** Moderate  
**File:** `includes/ODBCConnectionManager.php`, `buildConnectionString()`  
**Status:** ✅ Fixed in v1.1.0 (P2-019)

**Fix:** New private `escapeConnectionStringValue()` helper wraps values containing `;`, `{`, or `}` in `{...}` braces with internal `}` doubled, per the ODBC specification. All server/database/port values now go through this helper.

~~**Description:**  
When building a driver-based connection string, parameter values are interpolated directly without escaping. A semicolon in any value terminates that attribute and begins the next one, potentially injecting arbitrary connection attributes.~~

```php
$parts[] = 'Server=' . $config['server'];
$parts[] = 'Database=' . $config['database'];
```

A semicolon (`;`) in any value terminates that attribute and begins the next one in ODBC connection strings, potentially injecting arbitrary connection attributes. Similarly, a `}` within the driver name string breaks the `Driver={...}` wrapping syntax.

**Impact:** Mis-configuration leading to connection failures, or in adversarial scenarios where `$wgODBCSources` values can be influenced, connection string attribute injection.

**Workaround:** Ensure `$wgODBCSources` server, database, and driver values do not contain `;`, `{`, or `}`. Use a System DSN (configured via ODBC Data Source Administrator) for connection strings requiring special characters.

---

### KI-026 — `validateConfig()` Is Dead Code and Never Called

**Severity:** Minor  
**File:** `includes/ODBCConnectionManager.php`  
**Status:** ✅ Fixed in v1.1.0 (P2-020)

**Fix:** `connect()` now retrieves config at the very top of the method and immediately calls `validateConfig()`. Invalid configs produce a clear `odbc-error-config-invalid` message.

~~**Description:**  
`ODBCConnectionManager::validateConfig()` exists and is documented, but is called **nowhere** in the codebase. Configuration errors are only detected when a connection attempt fails.~~

**Workaround:** Check source configuration manually before deploying. Test connections via Special:ODBCAdmin.

---

### KI-027 — ED Connector `odbc_source` Mode Always Uses LIMIT for SQL Server

**Severity:** High  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `getQuery()` and `setCredentials()`  
**Status:** ✅ Fixed in v1.1.0 (P2-021)

**Fix:** `EDConnectorOdbcGeneric::__construct()` now looks up the referenced `$wgODBCSources` entry and copies `driver` into `$this->credentials['driver']`. Correct `TOP`/`FIRST`/`LIMIT` syntax is applied automatically in all ED modes.

~~**Description:**  
When the External Data connector uses `odbc_source` mode, the driver name is not inherited from the referenced `$wgODBCSources` entry. `getQuery()` therefore always falls back to `LIMIT` syntax, which fails for SQL Server.~~

**Impact:** All queries via External Data's parser functions against a SQL Server source configured via `odbc_source` fail with a T-SQL syntax error at runtime.

**Workaround:** In `$wgExternalDataSources`, redundantly add `'driver' => 'ODBC Driver 17 for SQL Server'` (or the relevant driver name) alongside the `odbc_source` reference, even though the driver is already defined in the referenced `$wgODBCSources` entry.

---

### KI-028 — `$wgODBCExternalDataIntegration = 0` Does Not Disable Integration

**Severity:** Minor  
**File:** `includes/ODBCHooks.php`, `registerExternalDataConnector()`  
**Status:** ✅ Fixed in v1.1.0 (P2-022)

**Fix:** Check changed from `=== false` to `!$wgODBCExternalDataIntegration`. Any falsy value now correctly disables ED integration.

~~**Description:**  
The disable check uses strict identity comparison `=== false`. PHP integer `0`, `null`, and empty string `''` are falsy but not `=== false`.~~

**Workaround:** Use the exact boolean literal `false`: `$wgODBCExternalDataIntegration = false;`

---

### KI-032 — `sanitize()` Keyword Patterns Are Missing a Trailing Word Boundary ✅ Fixed in v1.1.0

**Severity:** High  
**File:** `includes/ODBCQueryRunner.php`, `sanitize()`  
**Status:** ✅ Fixed in v1.1.0 (P2-028)

**Fix:** All keyword patterns changed from `/\bKEYWORD/i` to `/\bKEYWORD\b/i`. Column names starting with a blocked keyword (e.g., `DECLARED_AT`, `DELETED_AT`, `GRANTED_BY`) are no longer incorrectly blocked.

~~**Description:**  
The regex pattern for each blocked keyword uses a leading `\b` word boundary but no trailing boundary, causing false-positive matches on identifiers that begin with a blocked keyword (e.g., `DECLARED_AT` matches `DECLARE`).~~

```php
$pattern = '/\b' . preg_quote( $keyword, '/' ) . '/i';
```

Without a trailing `\b`, the pattern matches any string that *starts with* the blocked keyword, not just the keyword as a complete word. This causes false-positive rejections on valid SQL inputs:

| Column / value | Blocked keyword matched | Rejected erroneously? |
|---|---|---|
| `DECLARED_AT` | `DECLARE` | Yes |
| `DELETED_AT` | `DELETE` | Yes |
| `GRANTED_BY` | `GRANT` | Yes |
| `INSERTING_TIMESTAMP` | `INSERT` | Yes |
| `EXECUTIVE` | `EXEC` | Yes |
| `WHERE status = 'DECLARED'` | `DECLARE` | Yes |

Any editor whose database schema uses column or table names that begin with a blocked keyword will receive `"Illegal SQL pattern 'DECLARE'"` (or similar) with no actionable fix available from the query side.

**Fix:**

```php
// BEFORE (missing trailing boundary — false positives):
$pattern = '/\b' . preg_quote( $keyword, '/' ) . '/i';

// AFTER (correct — keyword matched only as a standalone word):
$pattern = '/\b' . preg_quote( $keyword, '/' ) . '\b/i';
```

Apply to all entries in `$keywordPatterns`. Note: `UNION` in `$charPatterns` uses `strpos()` and is a separate issue (KI-024).

**Workaround:** Rename columns/tables that start with a blocked keyword, or use prepared queries (which bypass `sanitize()` for the SQL template itself).

---

### KI-033 — `odbc_setoption()` Timeout Failures Are Silently Suppressed

**Severity:** Minor  
**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`  
**Status:** Fixed in v1.1.0 — `@odbc_setoption()` return value is now checked; failure logged via `wfDebugLog( 'odbc', ... )`; `@` suppressor retained to prevent outer error handler converting the PHP warning to `MWException`

**Description:**  
The `odbc_setoption()` call that sets the statement-level query timeout uses the `@` error suppressor. When the call returns `false`, the code logs a warning. However, many ODBC drivers silently accept the call and return `true` while ignoring the timeout value. In these cases the extension logs nothing, and `$wgODBCQueryTimeout`/per-source `timeout` values appear accepted but are never enforced. An operator seeing unexpectedly long queries has no log evidence that the configured timeout is non-functional for their driver.

**Workaround:** Verify timeout enforcement at the database server level (e.g., `max_execution_time` for MySQL, `statement_timeout` for PostgreSQL). Monitor query durations independently of this extension.

---

### KI-034 — Connection Pool Uses FIFO Eviction Instead of LRU

**Severity:** Minor  
**File:** `includes/ODBCConnectionManager.php`, `connect()`  
**Status:** Fixed in v1.1.0 (2026-03-03) — LRU eviction implemented via a `$lastUsed` timestamp array; pool now evicts the least-recently-used connection on overflow. `disconnect()` and `disconnectAll()` reset timestamps accordingly (P2-024).

**Description:**  
When the connection pool is full, the pool evicts the connection opened *first* (FIFO — `array_key_first( self::$connections )`), regardless of how recently each connection was last used. A rarely-used connection opened early is retained over a frequently-queried connection added later, forcing unnecessary reconnections for the high-traffic source. LRU eviction would retain the most-recently-used connections and discard the idle one.

**Workaround:** Set `$wgODBCMaxConnections` high enough to hold all configured sources simultaneously, preventing eviction.

---

## Open Documentation Issues (Discovered in v1.0.3 and 2026-03-03 Re-Review)

### KI-029 — SECURITY.md Release History Missing v1.0.2, v1.0.3, and v1.1.0

**Severity:** Documentation Error  
**File:** `SECURITY.md`, "Security Release History" section  
**Status:** Fixed in v1.1.0 — v1.0.2, v1.0.3, and v1.1.0 entries added to SECURITY.md Security Release History

**Description:**  
The Security Release History section documents only v1.0.0 and v1.0.1. Three subsequent releases all contain significant security-relevant changes that should be documented for operators assessing their vulnerability exposure:
- **v1.0.2** (critical): XSS, wikitext injection, UNION injection, password exposure, CSRF token fixes
- **v1.0.3**: Connection liveness, cache key collision, blocklist expansion, column browser, max rows enforcement
- **v1.1.0**: UNION word-boundary fix (KI-024), connection string escaping (KI-025), sanitizer word boundaries (KI-032)

---

### KI-030 — CHANGELOG v1.0.3 Marked "Unreleased" Despite Being the Shipped Version ✅ Fixed

**Severity:** Documentation Error  
**File:** `CHANGELOG.md`  
**Status:** Fixed — v1.0.3 entry now dated 2026-03-02; pattern recurred in v1.1.0 (see KI-041)

**Description:**  
`## [1.0.3] - Unreleased` should carry the actual release date. The code at this version IS v1.0.3, `extension.json` says `"version": "1.0.3"`, and KNOWN_ISSUES.md already references it as a release. "Unreleased" is misleading.

**Resolution:** The v1.0.3 CHANGELOG entry was updated to `2026-03-02`. The same pattern recurred for v1.1.0 (see KI-041).

---

### KI-031 — README Troubleshooting References Obsolete `MAX_CONNECTIONS` Constant

**Severity:** Documentation Error  
**File:** `README.md`, Performance Issues troubleshooting section  
**Status:** Fixed in v1.1.0 — README.md updated to reference `$wgODBCMaxConnections` configuration key

**Description:**  
The README still says: _"Connection pool is limited to 10; increase if needed by modifying MAX_CONNECTIONS constant."_ The hard-coded constant was replaced by the user-configurable `$wgODBCMaxConnections` key in v1.0.3. Instructing users to edit PHP source code for a configurable setting is incorrect and will cause confusion.

**Workaround:** Set `$wgODBCMaxConnections` in `LocalSettings.php`.

---

### KI-035 — `wiki/Architecture.md` Contains 5 Major Factual Errors ✦ NEW (2026-03-03)

**Severity:** High (Documentation)  
**File:** `wiki/Architecture.md`  
**Status:** ✅ Fixed in v1.1.0 (P2-029) — all five errors corrected. Note: P2-024 (LRU eviction) was later implemented but Architecture.md was not fully updated; those new inaccuracies are tracked as KI-051.

**Description:**  
The Architecture.md wiki page contains five distinct factual errors that would mislead contributors trying to understand or extend the codebase:

1. **"All static" is wrong.** ODBCQueryRunner is described as having all-static methods. It is an instance-based class with a constructor. Only three helpers (`sanitize()`, `validateIdentifier()`, `requiresTopSyntax()`) are static; all others are instance methods.
2. **Wrong method signatures.** Method signatures include a non-existent `$sourceId` parameter on `executeComposed()` and related instance methods. Code written from these signatures would produce fatal errors.
3. **`displayOdbcTable()` does not use `expandTemplate()`.** The page states the function calls `$parser->getPreprocessor()->expandTemplate()`. It does not — it returns a wikitext template call string that MediaWiki processes through normal page parsing.
4. **LRU contradicted by code.** The connection pool is described as using "LRU eviction". The actual implementation uses FIFO (`array_key_first()`). The same page later correctly describes FIFO, creating an internal contradiction.
5. **Wrong method name.** The page refers to `getTableList()` — the actual method is `getTables()`.

**Fix:** Correct all five errors. See `codebase_review.md` §4.7 for the precise corrections.

---

### KI-036 — `wiki/Known-Issues.md` KI-008 Description Is Inaccurate ✦ NEW (2026-03-03)

**Severity:** Minor (Documentation)  
**File:** `wiki/Known-Issues.md`  
**Status:** ✅ Fixed in v1.1.0 (P2-030)

**Description:**  
The wiki known-issues page describes KI-008 as occurring "when `data=` specifies mappings but omits some columns." This is inaccurate. `SELECT *` is issued when the `data=` parameter is **omitted entirely**. When `data=` is provided, only the listed columns are queried. The inaccurate description leads editors to try adding a partial `data=` mapping rather than completing it.

**Fix:** Update the KI-008 description to: "`SELECT *` is issued and all columns are fetched when the `data=` parameter is omitted entirely from `{{#odbc_query:}}`."

---

### KI-037 — `README.md` States Magic Words Were Fixed in "Version 1.0.1+" ✦ NEW (2026-03-03)

**Severity:** Minor (Documentation)  
**File:** `README.md`, Troubleshooting section  
**Status:** ✅ Fixed in v1.1.0 (P2-031)

**Description:**  
The README troubleshooting entry for "Magic words not working" states: "After updating to **version 1.0.1+**, uppercase variants also work correctly." This is wrong: (1) v1.0.1 changed the magic word flag from `0` to `1`, which made case sensitivity *more* restrictive; (2) the actual fix — changing flags back to `0` (case-insensitive) — was released in **v1.0.3**.

**Fix:** Change "version 1.0.1+" to "version 1.0.3+".

---

### KI-038 — `KNOWN_ISSUES.md` Had a Garbled Duplicate Footer Line ✦ NEW (2026-03-03)

**Severity:** Minor (Presentation)  
**File:** `KNOWN_ISSUES.md`, final lines  
**Status:** Fixed in this document update

**Description:**  
The v1.0.3 version of this file ended with two consecutive footer fragments from different revision states:

```
*Last updated: v1.0.3 re-review (2026-03-02) — 18 issues resolved; 13 open (...)*
 — 18 issues resolved; 4 open (KI-008, KI-018, KI-019, KI-020).*
```

The second fragment was an orphaned remnant not removed when the footer was updated. The two lines reported conflicting open-issue counts and the orphan was syntactically invalid markdown.

**Fix:** The orphaned fragment has been removed as part of the 2026-03-03 re-review update to this file.

---

### KI-039 — `UPGRADE.md` Uses Non-Standard `$GLOBALS` Notation ✅ Fixed in v1.1.0

**Severity:** Minor (Documentation Quality)  
**File:** `UPGRADE.md`, v1.0.3 upgrade section  
**Status:** Fixed in v1.1.0 — changed to `$wgODBCMaxConnections = 10;`

**Description:**  
The v1.0.3 upgrade section shows the new `$wgODBCMaxConnections` configuration key as:

```php
$GLOBALS['wgODBCMaxConnections'] = 10;
```

The standard MediaWiki `LocalSettings.php` convention is `$wgODBCMaxConnections = 10;`. The `$GLOBALS[...]` form is redundant, verbose, and inconsistent with every other configuration example in this extension's documentation and with standard MediaWiki practice.

**Fix:** Change the example to `$wgODBCMaxConnections = 10;`.

---

### KI-040 — `validateConfig()` Rejects Valid Progress OpenEdge Configurations Using `host` ✦ NEW (v1.1.0 re-review)

**Severity:** Moderate (Code Bug — Regression)  
**File:** `includes/ODBCConnectionManager.php`, `validateConfig()` method  
**Status:** Fixed in v1.1.0 — `&& empty( $config['host'] )` added to the `validateConfig()` condition; error message updated to `'server or host (required when using driver mode)'`

**Description:**  
The `buildConnectionString()` method added in v1.1.0 accepts both `server` and `host` as the server key for Progress OpenEdge connections. However, `validateConfig()` — which runs before any connection attempt — only checks for `server`:

```php
if ( $hasDriver && empty( $config['server'] ) && empty( $config['dsn'] ) ) {
    $errors[] = 'server (required when using driver mode)';
}
```

A Progress OpenEdge configuration that legitimately uses `host` instead of `server` will therefore fail validation and never reach `buildConnectionString()`. The error message returned will incorrectly tell the operator to provide `server`, when in fact `host` is a valid alternative for Progress driver configs.

**Fix:** Add `&& empty( $config['host'] )` to the condition:

```php
if ( $hasDriver && empty( $config['server'] ) && empty( $config['host'] ) && empty( $config['dsn'] ) ) {
    $errors[] = 'server or host (required when using driver mode)';
}
```

---

### KI-041 — `CHANGELOG.md` v1.1.0 Marked "Unreleased" Despite Being the Shipped Version ✦ NEW (v1.1.0 re-review)

**Severity:** Minor (Documentation Quality)  
**File:** `CHANGELOG.md`  
**Status:** Fixed in v1.1.0 — CHANGELOG.md v1.1.0 entry dated 2026-03-03

**Description:**  
`## [1.1.0] - Unreleased` — the code IS v1.1.0: `extension.json` declares `"version": "1.1.0"`. The same pattern occurred for v1.0.3 (KI-030) and was fixed for that entry. It recurred for v1.1.0. A release checklist step to update the CHANGELOG date on release would prevent this recurring.

**Fix:** Replace `Unreleased` with the release date.

---

### KI-042 — `wiki/Architecture.md` Incorrectly Documents `buildConnectionString()` as Not Handling Modes 1 or 3 ✦ NEW (v1.1.0 re-review)

**Severity:** Minor (Documentation Error — Factually Wrong)  
**File:** `wiki/Architecture.md`, `ODBCConnectionManager` component section  
**Status:** Fixed in v1.1.0 — `wiki/Architecture.md` updated with accurate three-mode description of `buildConnectionString()`

**Description:**  
The Architecture page states that `buildConnectionString()` "does not handle Mode 1 (DSN) or Mode 3 (full string)". In fact, the implementation handles all three modes: a full `connection_string` is returned as-is (Mode 3), a plain DSN name without a `driver` key is returned as-is (Mode 1), and driver/server/database-style configuration is constructed (Mode 2).

**Fix:** Update the description to accurately state that all three modes are handled and explain each code path.

---

### KI-043 — `wiki/Security.md` Notes KI-024 as an Open Issue After It Was Fixed in v1.1.0 ✦ NEW (v1.1.0 re-review)

**Severity:** Minor (Documentation Error — Stale Reference)  
**File:** `wiki/Security.md`, SQL injection protection section  
**Status:** Fixed in v1.1.0 — `wiki/Security.md` callout updated to "Fixed in v1.1.0 (KI-024)"; KI-024 and KI-025 rows removed from Known Limitations table; v1.1.0 row added to Security Release History

**Description:**  
The SQL injection section includes a callout labelled "Known issue (KI-024)" warning that `UNION` is matched as a substring and blocks valid identifiers like `TRADE_UNION_ID`. KI-024 was fixed in v1.1.0 — `UNION` was moved to the word-boundary matched `$keywords` list. The note was not removed/updated and now misleads editors into avoiding `UNION`-containing identifiers that are no longer blocked.

**Fix:** Remove the callout or update it to: "~~KI-024 — fixed in v1.1.0~~: `UNION` now uses word-boundary matching."

---

### KI-044 — `SECURITY.md` Known Limitations Describes Obsolete Double-Emit Row Limit Behaviour ✦ NEW (v1.1.0 re-review)

**Severity:** Minor (Documentation Error — Factually Wrong)  
**File:** `SECURITY.md`, Known Limitations section  
**Status:** Fixed in v1.1.0 — `SECURITY.md` Known Limitations updated with accurate driver-aware description (`TOP n` / `FIRST n` / `LIMIT n`)

**Description:**  
The Known Limitations section states: "LIMIT syntax handling tries both TOP (SQL Server) and LIMIT (MySQL/PostgreSQL)." Emitting both syntaxes in the same query was the pre-v1.0.2 bug (KI-003). The current behavior (since v1.0.2, extended in v1.1.0 with `getRowLimitStyle()`) selects the appropriate syntax based on the driver: `TOP n` for SQL Server/Access/Sybase, `FIRST n` for Progress OpenEdge, `LIMIT n` for all others. The description also omits Progress OpenEdge support added in v1.1.0.

**Fix:** Update to describe the current driver-aware selection logic.

---

### KI-045 — `UPGRADE.md` v1.0.1 Section Falsely Claims Magic Word Case Sensitivity Was Fixed in v1.0.1 ✦ NEW (v1.1.0 re-review)

**Severity:** Minor (Documentation Error — Factually Wrong)  
**File:** `UPGRADE.md`, "Upgrading to 1.0.1 from 1.0.0" section  
**Status:** Fixed in v1.1.0 — `UPGRADE.md` v1.0.1 section corrected to state that v1.0.1 made case sensitivity **worse** and that the actual fix was in v1.0.3

**Description:**  
The v1.0.1 upgrade section states: "Magic Word Case Sensitivity Fixed — `{{#ODBC_QUERY:}}` now works (previously only lowercase worked)." This is false: v1.0.1 changed the magic word flag from `0` (case-insensitive) to `1` (case-sensitive), which made uppercase variants stop working. The actual fix — restoring the flag to `0` — was released in **v1.0.3** (KI-001). An operator on v1.0.1 or v1.0.2 who reads this note will be incorrectly reassured that uppercase variants work.

**Fix:** Correct to: "~~v1.0.1 incorrectly changed magic word flags~~: uppercase variants only work from **v1.0.3** onwards."

---

### KI-046 — `wiki/Parser-Functions.md` Marks `data=` as Required When It Is Optional ✦ NEW (v1.1.0 re-review)

**Severity:** Minor (Documentation Error)  
**File:** `wiki/Parser-Functions.md`, `{{#odbc_query:}}` parameters table  
**Status:** Fixed in v1.1.0 — `wiki/Parser-Functions.md` `data=` parameter marked `No` in Required column; note added about `SELECT *` default and KI-008 exposure risk

**Description:**  
The parameter table for `{{#odbc_query:}}` lists `data=` with `Required: Yes`. The parameter is optional: when omitted, the extension issues `SELECT *` and stores all returned columns under lowercase names (KI-008). Marking it as required causes editors to always include `data=` when selectively fetching columns may not always be desired, and conceals the implicit `SELECT *` default behaviour.

**Fix:** Change `Required: Yes` → `Required: No` and add: "If omitted, `SELECT *` is issued and all columns are stored under their lowercase names. This may expose sensitive columns unintentionally (KI-008)."

---

### KI-047 — `KNOWN_ISSUES.md` Footer Open Issue Count and List Were Incorrect ✦ NEW (v1.1.0 re-review)

**Severity:** Minor (Presentation / Tracking Accuracy)  
**File:** `KNOWN_ISSUES.md`, footer line  
**Status:** Fixed in this document update

**Description:**  
The footer stated "14 open" and listed KI-035 through KI-039 as open. However:
- KI-030 (CHANGELOG v1.0.3 dated) is fixed — should be removed from open list
- KI-038 (garbled footer) is marked "Fixed in this document update" in the body — should not be in open list
- KI-039 (UPGRADE.md $GLOBALS) is fixed in v1.1.0 — should not be in open list

The open count was therefore overstated by three, and the listed open issues were wrong.

**Fix:** Updated in this document revision (v1.1.0 re-review).

---

### KI-048 — `KNOWN_ISSUES.md` Contains Mojibake Encoding Throughout Earlier Entries ✦ NEW (v1.1.0 re-review)

**Severity:** Minor (Presentation)  
**File:** `KNOWN_ISSUES.md`  
**Status:** Fixed in v1.1.0 (2026-03-03) — KNOWN_ISSUES.md re-saved as strict UTF-8; all mojibake sequences (em dash, check mark, arrows, smart quotes) replaced with correct Unicode characters via PowerShell string replacement.

**Description:**  
Many entries in the "Issues Resolved" section and some open issue bodies render multibyte Unicode characters as ISO-8859-1 sequences. Examples: `â€"` instead of `—`, `✅` instead of `✅`, `‘` instead of `'`. The file was likely saved or transferred as UTF-8 but interpreted or re-encoded as Latin-1 at some point during editing. The mojibake characters do not prevent parsing but reduce readability and are likely to confuse editors who copy text from the file.

**Fix:** Re-save the file as UTF-8 without BOM and correct the specific garbled sequences in the resolved-issues section.

---

### KI-049 — `sanitize()` Word-Boundary and Whitespace Evasion Vulnerabilities ✦ NEW (v1.1.0 re-review)

**Severity:** High (Security)  
**File:** `includes/ODBCQueryRunner.php`, `sanitize()`  
**Status:** Fixed in v1.1.0 (2026-03-03) — trailing word boundary corrected per keyword type; whitespace normalization added (P2-044).

**Description:**  
Three related evasion paths were present in the `sanitize()` SQL injection blocklist:

1. **`XP_` / `SP_` stored-procedure prefix not blocked** — `_` is a PCRE word character (`\w`), so `/\bXP_\b/i` never matches `XP_cmdshell` because there is no word boundary between `_` and `c`. An attacker could inject `XP_cmdshell` or any `SP_*` stored procedure name without triggering the blocklist.

2. **`SLEEP()` / `BENCHMARK()` variants not fully blocked** — keywords ending with `(` were matched with a trailing `\b`, which in PCRE requires the immediately following character to be a word character. `/\bSLEEP\(\b/i` would block `SLEEP(1)` but NOT `SLEEP()` (empty args) or `SLEEP(0.5)` (decimal arg). A timing-attack payload using a non-integer delay would evade the check.

3. **Multi-space / tab whitespace evasion** — the multi-word patterns `INTO OUTFILE`, `LOAD DATA`, etc. were matched against the raw (whitespace-unnormalised) input string. Writing `INTO  OUTFILE` (two spaces) or `INTO\tOUTFILE` (tab) bypassed the check.

**Fix (P2-044):**  
- Whitespace normalization (`preg_replace('/\s+/', ' ', $clean)`) added immediately after control-char stripping.  
- Trailing `\b` is now only appended for keywords whose last character is alphanumeric (`ctype_alnum()`). Keywords ending with `_` (e.g. `XP_`, `SP_`) use leading-only `\b` for prefix matching; keywords ending with `(` use no trailing boundary so `SLEEP()`, `SLEEP(0.5)`, etc. are all blocked.

---

### KI-050 — `odbc-error-too-many-queries` Message Recommends Ineffective Workaround ✦ NEW (2026-03-05)

**Severity:** Minor (User-Facing Bug)  
**Files:** `i18n/en.json`, `includes/ODBCParserFunctions.php`  
**Status:** ✅ Fixed in v1.2.0 (P2-047) — `{{#odbc_clear:}}` recommendation removed from `i18n/en.json`. Message now correctly advises reducing `{{#odbc_query:}}` calls or raising `$wgODBCMaxQueriesPerPage`.

**Description:**  
The error message shown when a page hits the per-page query limit included the advice: "Use `{{#odbc_clear:}}` to separate logical sections." This was incorrect. `odbcClear()` calls `setStoredData($parser, [])` which writes only to the `ODBCData` key. The query counter lives in a separate key (`ODBCQueryCount`) and is **never reset** by `odbcClear()`. Following the advice has no effect on the limit — editors will be confused when the error reappears immediately.

**Fix:** Remove the `{{#odbc_clear:}}` recommendation. Replace with accurate advice, e.g.: "Reduce the number of `{{#odbc_query:}}` calls on this page, or raise `$wgODBCMaxQueriesPerPage` in `LocalSettings.php`." See `codebase_review.md` §4.19.

---

### KI-051 — `wiki/Architecture.md` Not Updated After P2-024 (LRU Eviction) Was Implemented ✦ NEW (2026-03-05)

**Severity:** Minor (Documentation Error)  
**File:** `wiki/Architecture.md`  
**Status:** ✅ Fixed in v1.2.0 (P2-048) — all four locations corrected: FIFO → LRU in two places, Design Limitations table updated to show P2-024 Done, caching section corrected to `ObjectCache::getLocalClusterInstance()`.

**Description:**  
Four locations in `wiki/Architecture.md` still reflected the old pre-P2-024 state:

1. `connect()` description: "Evicts the oldest connection (**FIFO**)" — wrong, code is now LRU via `asort($lastUsed)` + `array_key_first()`.
2. Connection pool subsection: "FIFO eviction (`array_key_first()`)" — wrong for the same reason.
3. Design Limitations table: row reads "FIFO connection eviction | LRU planned | P2-024" — P2-024 is Done; LRU is live.
4. Caching section: "**WANObjectCache** (from `MediaWikiServices::getInstance()->getMainWANObjectCache()`)" — wrong; code uses `ObjectCache::getLocalClusterInstance()` (node-local APCu-backed cache, not shared across app servers).

**Fix:** Correct all four locations. See `codebase_review.md` §4.20.

---

### KI-052 — `wiki/Known-Issues.md` KI-020 Not Updated for Partial v1.1.0 Fix ✦ NEW (2026-03-05)

**Severity:** Minor (Documentation Error)  
**File:** `wiki/Known-Issues.md`  
**Status:** ✅ Fixed in v1.2.0 (P2-049) — KI-020 entry updated with "⚠ Partially fixed in v1.1.0 (P2-016)" and clear mode-by-mode breakdown.

**Description:**  
The wiki Known-Issues page listed KI-020 as fully open: "Planned fix: v1.1.0 (P2-016)." P2-016 is marked **Partial**: `odbc_source` mode now routes through `executeRawQuery()` gaining caching and UTF-8 encoding (fixed); standalone External Data connections still do not cache (still open). The wiki page needs to be updated to reflect which mode is fixed and which remains open.

**Fix:** Update KI-020 entry on `wiki/Known-Issues.md` to show "Partially fixed in v1.1.0" with clear mode-by-mode breakdown. See `codebase_review.md` §4.21.

---

### KI-053 — `$wgODBCMaxConnections` Described as "Per Source" in Six Locations ✦ NEW (2026-03-05)

**Severity:** Minor (Documentation Error)  
**Files:** `extension.json`, `README.md` (x2), `CHANGELOG.md`, `UPGRADE.md`, `SECURITY.md`  
**Status:** ✅ Fixed in v1.2.0 (P2-050) — all six instances corrected to "across all sources combined" in the respective files.

**Description:**  
`$wgODBCMaxConnections` is a **global pool limit** shared across all ODBC sources combined. Six documentation instances described it as "per source" (or "per data source"), which was incorrect. A wiki with ten configured sources and `$wgODBCMaxConnections = 10` gets at most ten total connections across all of them — not ten per source. This could lead administrators to set an unexpectedly low value.

**Fix:** Replace "per source" / "per data source" with "across all sources combined" in all six instances. See `codebase_review.md` §4.22.

---

*Last updated: v1.2.0 (2026-03-06) — KI-019 fixed (row= parameter); KI-050/051/052/053 fixed (docs); P2-051 complete (withOdbcWarnings public, 5 closures refactored); KI-008 partially addressed (wfDebugLog warning added). 50 resolved/partial total; 1 open (KI-008 — SELECT * still issued, logging only).*

---

## Fixed in v1.3.0 (2026-03-xx)

### KI-054 — Admin `runTestQuery()` Bypassed `$wgODBCAllowArbitraryQueries` Setting

**Severity:** Moderate (Security / Consistency)  
**File:** `includes/specials/SpecialODBCAdmin.php`, `runTestQuery()`  
**Status:** ✅ Fixed in v1.3.0 (P2-056) — `runTestQuery()` now checks `ODBCAllowArbitraryQueries` (global) and `allow_queries` (per-source) before executing; consistent with `executeComposed()`.

**Description:** `runTestQuery()` called `executeRawQuery()` directly, bypassing the `$wgODBCAllowArbitraryQueries` gate that `executeComposed()` enforces. An administrator could run ad-hoc SQL via `Special:ODBCAdmin` against sources configured as prepared-statement-only, violating operator intent. Operators who set the global to `false` expecting all ad-hoc SQL to be blocked could still be bypassed by anyone with `odbc-admin` permission.

---

### KI-055 — `extension.json` `callback` Key Was Using Deprecated Mechanism

**Severity:** Minor (Forward-Compatibility)  
**File:** `extension.json`  
**Status:** ✅ Fixed in v1.3.0 (P2-054) — `"callback"` removed; `"ExtensionRegistration": "ODBCHooks::onRegistration"` added under `"Hooks"`.

**Description:** The `callback` key is the pre-MW1.25 one-time setup mechanism. Modern MW uses the `ExtensionRegistration` hook. No functional regression; the fix aligns the extension with current MW guidelines and prevents deprecation warnings in future MW versions.

---

### KI-056 — `getMainConfig()` Called Three Times Independently in Hot Query Paths

**Severity:** Minor (Performance / DRY)  
**File:** `includes/ODBCQueryRunner.php`  
**Status:** ✅ Fixed in v1.3.0 (P2-055) — `private $mainConfig` property cached in constructor; three method-level calls replaced.

**Description:** `MediaWikiServices::getInstance()->getMainConfig()` was called independently in `executeComposed()`, `executePrepared()`, and `executeRawQuery()` on every invocation. Caching in the constructor eliminates the redundant service-locator lookups on hot query paths.

---

### KI-057 — `Html::textarea()` Used Deprecated HTML5 `cols` Attribute

**Severity:** Minor (HTML5 Compliance)  
**File:** `includes/specials/SpecialODBCAdmin.php`  
**Status:** ✅ Fixed in v1.3.0 (P2-058) — `'cols' => 80` removed; replaced with `'style' => 'width: 100%; max-width: 60em; box-sizing: border-box;'`.

**Description:** `cols` is a deprecated presentational attribute in HTML5. Width should be controlled via CSS. Browsers still render `cols` but it produces HTML5 validation warnings.

---

### KI-058 — `parseDataMappings()` Silently Dropped Overlong Mapping Pairs

**Severity:** Minor (Silent Data Loss)  
**File:** `includes/ODBCParserFunctions.php`, `parseDataMappings()`  
**Status:** ✅ Fixed in v1.3.0 (P2-057) — `wfDebugLog('odbc', ...)` entry added when a pair is dropped; includes pair length and first 80 characters.

**Description:** Mapping pairs longer than 256 characters were silently skipped. Wiki editors received no error, warning, or indication that their `data=` parameter was partially ignored, causing silent variable-not-populated failures with no diagnostic path.

---

## Fixed in v1.4.0 (2026-03-xx)

### KI-059 — `EDConnectorOdbcGeneric` Autoloaded Without `class_exists` Guard

**Severity:** Moderate (Reliability)  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`  
**Status:** ✅ Fixed in v1.4.0 (P2-059) — `class_exists('EDConnectorComposed', false)` guard added; file returns early if External Data is absent.

**Description:** The class extends `EDConnectorComposed` (provided by External Data). MediaWiki's `AutoloadClasses` registration allowed PHP to autoload the file on any page. Without the guard, loading the file when External Data is not installed triggers a fatal `class not found` error at parse time. The early-return guard prevents the fatal error when External Data is absent.

---

### KI-060 — Positional `source=` Argument in `{{#odbc_query:}}` Was Undocumented

**Severity:** Minor (Documentation)  
**File:** `includes/ODBCParserFunctions.php`, README.md  
**Status:** ✅ Fixed in v1.4.0 (P2-060) — Inline comment and README `source=` parameter table row updated to document the positional form.

**Description:** The syntax `{{#odbc_query: mydb | from=...}}` (source as first positional argument, no `source=` keyword) was accepted by the code but not mentioned anywhere in documentation. Template authors who discovered it accidentally had no guarantee it was intentional.

---

### KI-061 — `wfDebugLog()` Prefix Format Was Inconsistent in Two Log Messages

**Severity:** Minor (Code Quality)  
**File:** `includes/ODBCQueryRunner.php`  
**Status:** ✅ Fixed in v1.4.0 (P2-061) — Two messages changed from `[{$sourceId}]:` to `on source '{$sourceId}':` format.

**Description:** Error-path log entries in `executePrepared()` used `Prepare failed [{$sourceId}]:` and `Execute failed [{$sourceId}]:` — a bracket-delimited format inconsistent with all other log messages which used `on source '{$sourceId}':`. The inconsistency made `grep` filtering of log files unnecessarily fiddly.

---

### KI-062 — No `require-dev` or Test Infrastructure Defined in `composer.json`

**Severity:** Minor (Developer Experience)  
**File:** `composer.json`  
**Status:** ✅ Fixed in v1.4.0 (P2-062) — `phpunit/phpunit ^9.0||^10.0` and `mediawiki/mediawiki-codesniffer ^44.0` added as dev dependencies; `composer test` and `composer phpcs` scripts defined; `.phpcs.xml` created with `MediaWiki` ruleset.

**Description:** There was no defined way for contributors to run tests or check coding standards. While no test files exist yet (see §3.11 of codebase_review.md), this change establishes the scaffolding so test files can be added without requiring manual `composer.json` configuration.

---

## Fixed in v1.5.0 (Discovered in v1.4.0 Review Pass — 2026-03-08)

### KI-063 — `CHANGELOG.md` v1.4.0 Again Marked `[Unreleased]` ✦ NEW (v1.4.0 pass)

**Severity:** Documentation Error  
**File:** `CHANGELOG.md`  
**Status:** ✅ Fixed in v1.5.0 (P2-063) — v1.4.0 CHANGELOG entry dated 2026-03-03. [Unreleased] — v1.5.0 section created for all v1.5.0 changes.

**Description:** `## [Unreleased] — v1.4.0` — `extension.json` declares `"version": "1.4.0"`. The same error occurred in v1.0.3 (KI-030), v1.1.0 (KI-041), and again in v1.2.0/v1.3.0 review passes. This is now the fourth consecutive release to ship with `[Unreleased]` in the CHANGELOG. A CI enforcement step is needed to prevent recurrence.

**Fix:** Replace `[Unreleased]` with the actual release date. Add a CI check (e.g., `grep "\[Unreleased\]" CHANGELOG.md && exit 1` in a release workflow) to enforce that no `[Unreleased]` entries exist at tag/release time.

---

### KI-064 — `executeComposed()` Accepts `having=` Without Requiring `group by=` ✦ NEW (v1.4.0 pass)

**Severity:** Moderate (Bug — Invalid SQL Generation)  
**File:** `includes/ODBCQueryRunner.php`, `executeComposed()`  
**Status:** ✅ Fixed in v1.5.0 (P2-064) — Pre-execution guard added; returns `odbc-error-having-without-groupby` i18n error if `having=` is non-empty and `group by=` is empty.

**Description:** The method accepts `$having` without validating that `$groupBy` is also non-empty. A query configured with `having=` but no `group by=` will generate `SELECT ... HAVING ...` — valid in MySQL/MariaDB but a hard error in PostgreSQL and SQL Server. Wiki editors see a raw DBMS error with no explanation that the extension configuration is the cause.

**Fix:** Add a pre-execution check: return an i18n error (e.g., `odbc-error-having-without-groupby`) if `$having` is non-empty and `$groupBy` is empty.

---

### KI-065 — `validateIdentifier()` Allows Trailing Dots and Unlimited Dot-Segment Depth ✦ NEW (v1.4.0 pass)

**Severity:** Low (Input Validation Gap)  
**File:** `includes/ODBCQueryRunner.php`, `validateIdentifier()`  
**Status:** ✅ Fixed in v1.5.0 (P2-065) — Regex replaced with `/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*){0,2}$/`; allows 1–3 segments, rejects trailing dots and over-deep chains. Method promoted to `public static`.

**Description:** The regex `^[a-zA-Z_][a-zA-Z0-9_\.]*$` accepts `tablename.` (trailing dot — invalid SQL), `a.b.c.d.e` (five-level depth — unsupported by any database), and `table..column` (double dot — also invalid). Valid qualified forms top out at three segments (`catalog.schema.table`).

**Fix:** Replace the current regex with a segment-validating form:
```php
'/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*){0,2}$/'
```
This allows one to three dot-separated identifier segments and rejects trailing/doubled dots.

---

### KI-066 — `withOdbcWarnings()` Captures All PHP `E_WARNING`, Not Only ODBC Errors ✦ NEW (v1.4.0 pass)

**Severity:** Low (Subtle Bug — Misleading Error Messages)  
**File:** `includes/ODBCConnectionManager.php`, `withOdbcWarnings()`  
**Status:** ✅ Fixed in v1.5.0 (P2-066) — Handler now checks for ODBC driver signatures in the warning message; non-ODBC warnings return `false` to pass through to the next handler.

**Description:** The error handler installed by `withOdbcWarnings()` catches all PHP `E_WARNING` emissions during the callback, not only ODBC-driver-generated warnings. Any unrelated PHP warning triggered transitively inside a callback (deprecated function call, type coercion warning, third-party library warning) is converted to `MWException($errstr)` with the PHP warning text as the message. This produces misleading wiki error output and can mask the actual ODBC failure.

**Fix:** Add an ODBC-specific origin check inside the handler before throwing, passing non-ODBC warnings through to the next handler via `return false`.

---

### KI-067 — `EDConnectorOdbcGeneric::from()` Injects Table Aliases Without Identifier Validation ✦ NEW (v1.4.0 pass)

**Severity:** Low (Defence-in-Depth Gap)  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `from()`  
**Status:** ✅ Fixed in v1.5.0 (P2-067) — `ODBCQueryRunner::validateIdentifier()` promoted to `public static`; called for all non-numeric alias keys in `from()` before SQL injection.

**Description:** Table name values in `$this->tables` are passed through `ODBCQueryRunner::sanitize()` via `checkComposedParams()`. However, alias keys (the associative array keys) are used directly in `"$table AS $alias"` SQL fragments without any call to `validateIdentifier()` or `sanitize()`. This breaks the invariant that all SQL identifiers generated by the extension are validated.

**Impact:** `$wgExternalDataSources` is admin-controlled, limiting the exploit surface. However, a misconfigured or compromised alias value can inject arbitrary SQL into FROM clauses of all ED-connector queries.

**Fix:** Call `ODBCQueryRunner::validateIdentifier( $alias )` on each alias key in `from()` before interpolating it into the SQL string.

---

### KI-068 — Database `NULL` Values Are Silently Coerced to Empty String ✦ NEW (v1.4.0 pass)

**Severity:** Functional Limitation  
**File:** `includes/ODBCParserFunctions.php`, `mergeResults()`; `includes/ODBCQueryRunner.php`, `executeRawQuery()`  
**Status:** ✅ Fixed in v1.5.0 (P2-068) — `null_value=` parameter added to `{{#odbc_query:}}` (default `''`). `mergeResults()` now distinguishes column-not-present (`''`) from column-present-with-NULL (`$nullValue`). Fully backward-compatible.

**Description:** PHP `null` returned by `odbc_fetch_array()` for a NULL database column is cast to `''` (empty string) via `(string)$value` in `mergeResults()`. Wiki templates cannot distinguish NULL from an empty-string value. There is no `null_value=` parameter to specify an alternative representation (e.g., `N/A`, `—`, `unknown`).

**Impact:** Reporting pages where NULL carries distinct semantic meaning (absent vs. empty) cannot display data accurately. Conditional template logic (`{{#if:{{{var|}}}|...}}`) treats NULL identically to `''` with no override option.

**Fix:** Add a `null_value=` parameter to `{{#odbc_query:}}` (default `''` for backward compatibility) used when `mergeResults()` encounters PHP `null`.

---

### KI-069 — `mb_detect_encoding()` Called Per-Cell, Per-Row: O(rows × columns) ✦ NEW (v1.4.0 pass)

**Severity:** Functional Limitation (Performance)  
**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`  
**Status:** ✅ Fixed in v1.5.0 (P2-069) — Encoding sampled once from the first non-empty string in the first row, then applied uniformly to all subsequent rows. Per-source `charset=` key in `$wgODBCSources` bypasses detection entirely. Same optimisation applied to `EDConnectorOdbcGeneric` standalone fetch path.

**Description:** The UTF-8 encoding conversion loop calls `mb_detect_encoding()` with a 5-candidate list on every non-null string value of every row fetched. For large result sets (1,000 rows × 10 columns = 10,000 detection calls per query), this adds measurable latency. The function is also O(string_length) per call.

**Better approaches:** (1) Detect encoding once per result set using the first row. (2) Add a per-source `charset=` config option so operators can specify encoding explicitly, eliminating runtime detection.

---

### KI-070 — `$wgODBCMaxConnections` Per-PHP-Process Nature Not Documented ✦ NEW (v1.4.0 pass)

**Severity:** Documentation Error  
**File:** `extension.json`, `README.md`, `SECURITY.md`  
**Status:** ✅ Fixed in v1.5.0 (P2-070) — `extension.json` config description, README, and troubleshooting section updated with per-process qualifier and PHP-FPM multiplication example.

**Description:** `$wgODBCMaxConnections` is a per-PHP-worker-process limit. In PHP-FPM deployments, total system-wide connections = limit × number-of-workers. A deployment with 50 workers and limit=10 opens up to 500 ODBC handles simultaneously. No documentation states this; operators who set the limit to prevent database connection exhaustion while their infrastructure has many FPM workers are getting a false sense of security.

**Fix:** Add a prominent note to the `extension.json` config description, README "Performance" section, and SECURITY.md: "`$wgODBCMaxConnections` is a **per-PHP-worker-process** limit. In PHP-FPM deployments, total system connections = `$wgODBCMaxConnections × [FPM worker count]`."

---

### KI-071 — `wiki/Architecture.md` `ODBCHooks` Section References Deprecated `callback` Key ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `wiki/Architecture.md`, `ODBCHooks` component description  
**Status:** ✅ Fixed in v1.5.0 (P2-079, 2026-03-03) — wiki/Architecture.md `ODBCHooks` description updated to reference `ExtensionRegistration` hook; stale strikethrough rows removed from the Design Limitations table.

**Description:** The `ODBCHooks` component description in `wiki/Architecture.md` contains: *"Called by MediaWiki at load time via the `callback` key in `extension.json`."* The `callback` key was removed from `extension.json` in v1.3.0 (P2-054) and replaced with the `ExtensionRegistration` hook. The wiki Architecture page was not updated at that time. Developers reading the page will look for a `callback` key that no longer exists and misunderstand the module's entry point.

**Fix:** Change the `ODBCHooks` description to reference the `ExtensionRegistration` hook: *"Called via the `ExtensionRegistration` hook in `extension.json`. `ODBCHooks::onRegistration()` is called by MediaWiki at extension load time."*

---

### KI-072 — `extension.json` `ODBCSources` Config Description Cites a Non-Existent `options` Key ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `extension.json`, `ODBCSources` config description  
**Status:** ✅ Fixed in v1.5.0 (P2-073, 2026-03-03) — phantom `options (optional)` key removed; description rewritten to enumerate all valid keys including `charset=` and Progress OpenEdge keys.

**Description:** The `ODBCSources` config description in `extension.json` mentions `options (optional)` as one of the supported sub-keys. No `options` key is referenced anywhere in `ODBCConnectionManager::buildConnectionString()`, `validateConfig()`, or any other code in the extension. An operator who reads the description and adds an `options` key to their source configuration will silently get no effect, with no warning or error.

**Fix:** Remove `options (optional)` from the `ODBCSources` config description. If there is intent to add such a key in the future, track it as a planned feature rather than documenting it prematurely.

---

### KI-073 — Slow-Query Timer Measures Row-Fetch Time Only, Not Total Query Execution Time ✦ NEW (v1.5.0 review)

**Severity:** Bug  
**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`  
**Status:** ✅ Fixed in v1.5.0 (P2-074, 2026-03-03) — `$queryStart = microtime(true)` moved to immediately before `odbc_execute()`; timer now covers total DB + fetch time.

**Description:** Inside the `withOdbcWarnings()` closure in `executeRawQuery()`, the sequence is:

```php
$success = odbc_execute( $stmt, $params );  // ← DB-side SQL execution happens here
// ...
$queryStart = microtime( true );            // ← timer starts AFTER execute() returns
while ( $row = odbc_fetch_array( $stmt ) ) {
    $rows[] = $row;
}
$elapsed = microtime( true ) - $queryStart; // measures fetch loop only
```

`$queryStart` is set *after* `odbc_execute()` returns. The `$elapsed` time therefore measures only the PHP-side row-fetch loop (`odbc_fetch_array` iterations), not the database-side query execution time. The slow-query log entry (`"Returned N rows in Xs"`) implies total query duration, but long-running DB-side queries — the primary use case for `$wgODBCSlowQueryThreshold` — are never counted. A query that takes 29 seconds on the database and 0.1 seconds to fetch rows will not appear in the slow-query log if the threshold is 10 seconds.

**Impact:** `$wgODBCSlowQueryThreshold` is unreliable for detecting slow database-side execution. Operators who rely on it for performance monitoring will miss genuinely slow queries.

**Fix:** Move `$queryStart = microtime( true )` to immediately before `odbc_execute()`:

```php
$queryStart = microtime( true );
$success = odbc_execute( $stmt, $params );
// ...fetch loop...
$elapsed = microtime( true ) - $queryStart;
```

This is a one-line change with no risk of regression.

---

### KI-074 — `EDConnectorOdbcGeneric` Standalone Fetch Uses `odbc_exec()` Directly — No Timeout Applied ✦ NEW (v1.5.0 review)

**Severity:** Functional Limitation  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `fetch()` (standalone code path)  
**Status:** ✅ Fixed in v1.5.0 (P2-075, 2026-03-03) — standalone fetch now uses `odbc_prepare()` / `odbc_setoption()` / `odbc_execute()`; `$wgODBCQueryTimeout` and per-source `timeout=` now applied.

**Description:** When the ED connector runs in standalone mode (direct ODBC credentials, no `odbc_source` reference), `fetch()` uses `odbc_exec( $this->odbcConnection, $query )` directly. Unlike `ODBCQueryRunner::executeRawQuery()`, the standalone path does not call `odbc_prepare()` + `odbc_setoption()` before executing. Consequently:

1. The `$wgODBCQueryTimeout` and per-source `timeout=` settings have no effect — no per-statement timeout is ever set on standalone ED queries.
2. There is no mechanism to interrupt a long-running query via the ODBC timeout path.

This inconsistency means that the same data source configured in two different ways (`odbc_source` reference vs. standalone ED credentials) has fundamentally different timeout behaviour.

**Fix:** Replace `odbc_exec()` with `odbc_prepare()` + `odbc_setoption()` + `odbc_execute()` in the standalone fetch path, mirroring the pattern in `executeRawQuery()`.

---

### KI-075 — `requiresTopSyntax()` Is `@deprecated` Since v1.1.0 but Emits No `wfDeprecated()` Call ✦ NEW (v1.5.0 review)

**Severity:** Code Quality  
**File:** `includes/ODBCQueryRunner.php`, `requiresTopSyntax()`  
**Status:** ✅ Fixed in v1.5.0 (P2-076, 2026-03-03) — `wfDeprecated( __METHOD__, '1.1.0', 'ODBC' )` added as first statement.

**Description:** The method is annotated:

```php
/** @deprecated since 1.1.0 Use getRowLimitStyle() instead */
public static function requiresTopSyntax( string $driver ): bool {
    return self::getRowLimitStyle( $driver ) === 'top';
}
```

The `@deprecated` PHPDoc tag signals intent to MediaWiki code reviewers, but it emits no runtime deprecation warning to callers. PHP's MediaWiki framework provides `wfDeprecated( __METHOD__, '1.1.0', 'ODBC' )` for exactly this purpose. Without a `wfDeprecated()` call, any third-party code or future contributor that calls `requiresTopSyntax()` receives no runtime notice that the method is deprecated, and the method will never be removed because there is no pressure on callers to migrate.

**Fix:** Add `wfDeprecated( __METHOD__, '1.1.0', 'ODBC' );` as the first line of `requiresTopSyntax()`. This emits a MediaWiki deprecation notice to the `deprecated` log channel on each call, giving callers a clear migration signal without breaking them.

---

### KI-076 — `UPGRADE.md` Has No v1.5.0 Section ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `UPGRADE.md`  
**Status:** ✅ Fixed in v1.5.0 (P2-077, 2026-03-03) — full v1.5.0 upgrade section added including new parameters, security changes, internal improvements, and upgrade steps.

**Description:** `UPGRADE.md` contains upgrade sections for every version from v1.0.1 through v1.4.0, but has no v1.5.0 section. Version 1.5.0 introduces several operator-visible changes that require explicit documentation:

- `null_value=` parameter added to `{{#odbc_query:}}`
- Per-source `charset=` key added to `$wgODBCSources` (bypasses runtime encoding detection)
- `$wgODBCMaxConnections` documentation updated to clarify per-process behaviour
- `$wgODBCSlowQueryThreshold` and `$wgODBCCacheExpiry` operational notes
- `having=` without `group by=` now returns an error (was previously silent invalid SQL)
- `validateIdentifier()` tightened to reject trailing dots and >3 dot-segments
- `withOdbcWarnings()` now scoped to ODBC warnings only (non-ODBC PHP warnings now propagate)

Without an upgrade section, operators who read UPGRADE.md before upgrading will find the document stops at v1.4.0 and have no guidance for v1.5.0.

**Fix:** Add a "Upgrading to 1.5.0 from 1.4.0" section documenting all breaking changes and new config options.

---

### KI-077 — `SECURITY.md` v1.5.0 Release History Entry Shows "(Unreleased)" ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `SECURITY.md`, "Security Release History" section  
**Status:** ✅ Fixed in v1.5.0 (P2-078, 2026-03-03) — `(Unreleased)` replaced with `(2026-03-03)`. Known Limitations table also corrected (stale KI-033 resolved row removed).

**Description:** The Security Release History section in `SECURITY.md` contains the header `### Version 1.5.0 (Unreleased)`. This is the same recurring pattern as KI-030 (v1.0.3), KI-041 (v1.1.0), and KI-063 (v1.4.0). While technically correct during development, the `(Unreleased)` tag must be replaced with the actual release date when v1.5.0 is tagged and published.

**Fix:** Replace `(Unreleased)` with the release date when the v1.5.0 release is finalised. This should be part of the release checklist (see KI-063 / P2-063 in which a CI check was added — verify it covers `SECURITY.md` in addition to `CHANGELOG.md`).

---

### KI-078 — `wiki/Architecture.md` Design Limitations Table Contains Stale Resolved-Item Rows ✦ NEW (v1.5.0 review)

**Severity:** Documentation Quality  
**File:** `wiki/Architecture.md`, Design Limitations table  
**Status:** ✅ Fixed in v1.5.0 (P2-079, 2026-03-03) — three stale strikethrough rows removed; table now shows only current open limitations.

**Description:** The Design Limitations table in `wiki/Architecture.md` contains at least three rows for issues that have been fixed, displayed with strikethrough formatting rather than being removed:

| Stale row | Fixed in |
|-----------|----------|
| `~~FIFO connection eviction~~ — Fixed in v1.1.0: LRU with `$lastUsed` timestamps` | v1.1.0 (P2-024) |
| `~~Connection ping fails for MS Access~~ — Fixed in v1.1.0: MSysObjects probe` | v1.1.0 (P2-017) |
| `~~validateConfig() is dead code~~ — Fixed in v1.1.0: called from connect()` | v1.1.0 (P2-020) |

A "Design Limitations" table should describe current limitations, not preserved history in strikethrough form. The history is already captured in KNOWN_ISSUES.md. Keeping resolved rows clutters the table and makes it harder to identify what is actually a current limitation.

**Fix:** Remove the resolved rows from the Design Limitations table. The remaining open limitations (static class design, blocklist-only WHERE sanitization, KI-008 SELECT *, KI-020 partial ED caching) should remain.

---

### KI-079 — `wiki/Known-Issues.md` Is Severely Out of Date (Last Updated v1.1.0) ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `wiki/Known-Issues.md`  
**Status:** ✅ Fixed in v1.5.0 (P2-080, 2026-03-03) — page completely rewritten: KI-019 marked fixed (v1.2.0); KI-020 updated with v1.5.0 timeout fix; resolved summary table covers v1.0.1 through v1.5.0; footer updated.

**Description:** The wiki Known Issues page has a footer stating `Last updated: v1.1.0, 2026-03-03`. It shows:

- **KI-019** ("Cannot Access Non-First Rows") as still open with `Planned fix: v2.0.0` — this was **fixed in v1.2.0** as P2-019 (row selector added to `{{#odbc_value:}}`).
- KI-018, KI-023–KI-028 as fixed in v1.1.0 (correct), but no mention of any fixes in v1.2.0, v1.3.0, v1.4.0, or v1.5.0.
- The "Resolved Issues" section summary says: "a further 10 were fixed in v1.1.0" with no subsequent updates.
- No mention of KI-029 through KI-070 at all.
- No mention of the `null_value=` parameter (KI-068, fixed v1.5.0), `charset=` (KI-069), or other v1.2.0–v1.5.0 improvements.

The wiki page is the public-facing issue reference for wiki editors and operators. Having it show v1.2.0–v1.5.0 improvements as absent or still open significantly degrades trust and usefulness.

**Fix:** Update `wiki/Known-Issues.md` to reflect the current state: KI-019 fixed (v1.2.0), move all issues with fixes through v1.5.0 into the Resolved section, update the open-issues section to show only KI-008 and KI-020 (partial), and update the footer to v1.5.0.

---

### KI-080 — `wiki/Security.md` Security Release History Table Is Incomplete (Only Through v1.1.0) ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `wiki/Security.md`, "Security Release History" table  
**Status:** ✅ Fixed in v1.5.0 (P2-081, 2026-03-03) — v1.2.0–v1.5.0 rows added; double-pipe formatting bug fixed; Known Limitations table updated.

**Description:** The Security Release History table in `wiki/Security.md` only covers up to v1.1.0. There are no entries for v1.2.0, v1.3.0, v1.4.0, or v1.5.0. Security-conscious operators who check the wiki page for the full vulnerability and fix history see an incomplete picture ending four versions ago.

Additionally, the last table row has a double-pipe formatting bug:

```markdown
| v1.0.3 | Cache key collision fix ... || v1.1.0 | UNION word-boundary ...
```

The `||` (double pipe) on a single line makes this two cells jammed into one row. The v1.1.0 content is not rendered as its own row — it appears tacked on to the v1.0.3 row.

**Fix:** (1) Add separate table rows for v1.2.0, v1.3.0, v1.4.0, and v1.5.0. (2) Fix the double-pipe formatting by splitting the v1.1.0 content onto its own `| v1.1.0 | … |` row.

---

### KI-081 — `wiki/Parser-Functions.md` Does Not Document `null_value=` Parameter (Added v1.5.0) ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `wiki/Parser-Functions.md`, `{{#odbc_query:}}` parameters table  
**Status:** ✅ Fixed in v1.5.0 (P2-082, 2026-03-03) — `null_value=` row added to the parameters table with description, default, and version note.

**Description:** The `null_value=` parameter was added to `{{#odbc_query:}}` in v1.5.0 (KI-068, P2-068). The `wiki/Parser-Functions.md` parameters table does not include this parameter. Wiki editors reading the reference page cannot discover that `null_value=` exists or how to use it to distinguish database `NULL` from empty string in their templates.

**Fix:** Add a `null_value=` row to the `{{#odbc_query:}}` parameters table: `No | Both | Value substituted for database NULL in stored results. Default: `` (empty string — backward compatible). Use e.g. `null_value=N/A` to distinguish NULL from empty.`

---

### KI-082 — `wiki/Configuration.md` Does Not Document the `charset=` Per-Source Key (Added v1.5.0) ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `wiki/Configuration.md`, Connection Options Reference table  
**Status:** ✅ Fixed in v1.5.0 (P2-082/P2-083/P2-084, 2026-03-03) — `charset=`, `host=`, and `db=` rows added to the Connection Options Reference table.

**Description:** The `charset=` per-source configuration key was added in v1.5.0 (KI-069, P2-069) to allow operators to specify the database character encoding explicitly, bypassing the runtime `mb_detect_encoding()` sampling. The `wiki/Configuration.md` Connection Options Reference table does not include `charset=`. Operators who know their database uses `Windows-1252` or `ISO-8859-15` but cannot discover how to tell the extension this are forced to rely on the auto-detection path (which still works, but adds overhead and can detect incorrectly for short strings).

**Fix:** Add a `charset=` row to the Connection Options Reference table: `charset | string | No | Override auto-detected source character encoding. Example: `charset=Windows-1252`. When set, `mb_detect_encoding()` is skipped for all rows from this source.`

---

### KI-083 — `wiki/Configuration.md` Connection Options Table Missing `host` and `db` Keys for Progress OpenEdge ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `wiki/Configuration.md`, Connection Options Reference table  
**Status:** ✅ Fixed in v1.5.0 (P2-084, 2026-03-03) — `host=` and `db=` rows added to the Connection Options Reference table with Progress OpenEdge notes.

**Description:** Progress OpenEdge ODBC drivers use `host=` and `db=` (or `database=`) as connection string keys rather than `server=`. The v1.1.0 code fix (P2-034/P2-035) added support for the `host` key in both `buildConnectionString()` and `validateConfig()`, but the `wiki/Configuration.md` Connection Options Reference table only lists `server=`. Operators following the wiki to configure a Progress OpenEdge source will use `server=` instead of `host=`, which will either fail `validateConfig()` or produce a malformed OpenEdge connection string.

The `wiki/Installation.md` and `README.md` do contain Progress OpenEdge examples using `host=` — the wiki/Configuration.md reference table is the only location where this is missing, creating an inconsistency that sends operators to the wrong key.

**Fix:** Add `host` and `db` rows to the Connection Options Reference table: `host | string | Mode 2 (OpenEdge) | Host name for Progress OpenEdge driver-based connections (alternative to server=). Use with db= instead of database=.` and `db | string | Mode 2 (OpenEdge) | Database name alias for Progress OpenEdge (alternative to database=).`

---

### KI-084 — `wiki/Parser-Functions.md` Uses `{{#` as an Inline Comment — Not Valid MediaWiki Syntax ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `wiki/Parser-Functions.md`, `{{#odbc_value:}}` examples section  
**Status:** ✅ Fixed in v1.5.0 (P2-085, 2026-03-03) — invalid `{{# ... }}` comment replaced with `<!-- ... -->` HTML comment syntax.

**Description:** The examples section for `{{#odbc_value:}}` contains this line in a wikitext code block:

```wiki
{{# Access a specific row of a multi-row result: }}
```

The intent is clearly an inline comment, but `{{#` is the start of a parser function call in MediaWiki. On a real wiki page, this would be parsed as an invalid call to the function `#` and would produce either an error or unwanted output. The correct MediaWiki syntax for a wiki comment is `<!-- comment text -->`.

While this is inside a fenced code block in the Markdown source (and so renders correctly in GitHub), it is misleading for editors who copy-paste examples into wiki pages — including anyone following the documentation verbatim.

**Fix:** Replace `{{# Access a specific row of a multi-row result: }}` with `<!-- Access a specific row of a multi-row result: -->` in the example.

---

### KI-085 — `wiki/Security.md` "Known Security Limitations" Table Is Incomplete (Only 2 Rows; Outdated) ✦ NEW (v1.5.0 review)

**Severity:** Documentation Error  
**File:** `wiki/Security.md`, "Known Security Limitations" table  
**Status:** ✅ Fixed in v1.5.0 (P2-086, 2026-03-03) — stale resolved KI-033 row removed; table restructured (removed broken `#` column); current limitations documented accurately.

**Description:** The Known Security Limitations table in `wiki/Security.md` contains only two rows (the keyword blocklist caveat and the KI-033 / odbc_setoption note), the latter of which references "Fixed in v1.1.0" and is therefore stale (a fixed issue should not appear in a current-limitations table). Newer known security limitations identified in `codebase_review.md` §2 — including `withOdbcWarnings()` capturing all PHP E_WARNING (§2.6), `validateIdentifier()` dot-segment depth (§2.5), and the absence of rate limiting per user/IP (§2.3) — are absent from the table.

**Fix:** (1) Remove the resolved KI-033 row. (2) Add rows for: `withOdbcWarnings() captures all PHP E_WARNING | Can produce misleading exception messages from unrelated PHP warnings | Low probability; monitor PHP upgrade notes` and `No per-user rate limiting | odbc-query users can trigger many DB queries | Restrict odbc-query to trusted groups only`.

---

## Fixed in v1.5.0 (Discovered in Post-Release Review, Fixed Same Cycle)

The following issues were identified during a comprehensive post-release review of the v1.5.0 codebase and fixed before the v1.5.0 release.

---

### KI-086 — `wiki/Installation.md` Special:Version Verification Step Cites Stale Version "1.0.3" ✔ Fixed — v1.5.0

**Severity:** Minor (Documentation Error)
**File:** `wiki/Installation.md`, verification step 4
**Status:** ✅ Fixed — v1.5.0 (P2-087)

**Description:**
The final installation verification step instructs the operator to confirm the extension loads correctly via `Special:Version`:

> "The ODBC extension should appear in the 'Parser hooks' section with **version 1.0.3**."

The current version is **1.5.0**. The verification step has not been updated since the initial v1.0.3 documentation was written. An operator following this guide on a freshly-installed v1.5.0 deployment would see "1.5.0" and have no way to know whether this is correct, potentially causing unnecessary confusion (or worse, leading them to conclude the wrong version is installed and repeatedly retry the installation).

**Impact:** Minor operator confusion during new installations. Low probability of causing real harm, but reflects a systematic failure to update the installation guide with each release.

**Workaround:** Compare the version shown in `Special:Version` against `extension.json` in the installed directory.

**Fix:** Change "version 1.0.3" to the current version "1.5.0". Consider replacing the hard-coded version string with a generic instruction — for example: "The ODBC extension should appear in the 'Parser hooks' section. The version shown should match the version in `extension.json`." This avoids the version reference becoming stale again with every future release.

---

### KI-087 — `wiki/Troubleshooting.md` UNION Troubleshooting Section Presents KI-024 as an Active Open Issue ✔ Fixed — v1.5.0

**Severity:** Minor (Documentation Error — Stale Reference)
**File:** `wiki/Troubleshooting.md`, "Illegal SQL pattern 'UNION'" section
**Status:** ✅ Fixed — v1.5.0 (P2-088)

**Description:**
The troubleshooting section for the error "Illegal SQL pattern 'UNION'" contains this explanation:

> "This is KI-024. The word 'union' appears somewhere in the query and the sanitiser matches it as a substring..."

KI-024 was **fixed in v1.1.0** (P2-018). The word `UNION` was moved from the substring `$charPatterns` list (matched via `strpos()`) to the word-boundary `$keywords` regex list (matched via `/\bUNION\b/i`). Identifiers like `TRADE_UNION_ID` or `LABOUR_UNION` are no longer blocked. The section presents this as a current bug requiring a workaround when the issue has been resolved for multiple versions.

This is analogous to KI-043 (`wiki/Security.md` still noting KI-024 as open — fixed in v1.1.0 as P2-038), but affecting the Troubleshooting page, which was not reviewed in the same pass.

**Impact:** Editors and administrators reading the troubleshooting guide for the `UNION`-blocked error will be sent on a wild goose chase applying workarounds (renaming database columns) for a problem that no longer exists. The guidance degrades trust in the documentation.

**Workaround:** The workaround documented (rewriting identifiers) is no longer necessary. Identifiers containing "union" as a substring now work correctly.

**Fix:** Replace the section body with an updated status note: "~~KI-024 (fixed in v1.1.0)~~: `UNION` is now matched with word-boundary regex `/\bUNION\b/i`. Identifiers such as `TRADE_UNION_ID` and `LABOUR_UNION` are no longer blocked. If you are seeing this error on v1.1.0+, you have a literal `UNION` keyword (e.g., `UNION SELECT`) in your query — which is blocked by design. Use a prepared statement if a `UNION` query is legitimately required."

---

### KI-088 — `sanitize()` Blocklist Does Not Include `CAST(` or `CONVERT(` ✔ Fixed — v1.5.0

**Severity:** Moderate (Security — Defence-in-Depth Gap)
**File:** `includes/ODBCQueryRunner.php`, `sanitize()`
**Status:** ✅ Fixed — v1.5.0 (P2-089)

**Description:**
The SQL sanitiser blocklist in `sanitize()` currently blocks `CHAR(` and `CONCAT(` (both used in basic SQL injection obfuscation), but does not block `CAST(` or `CONVERT(`. These two functions are widely used in database-fingerprinting and obfuscation techniques, particularly:

- **`CAST(0x44524F50 AS CHAR)`** — converts a hex literal to the string `DROP`; used to smuggle blocked keywords past substring checks on SQL Server and MySQL.
- **`CONVERT(0x44454C455445 USING utf8)`** — similar technique on MySQL; converts hex `DELETE` past text filters.
- **`CAST(... AS xml)`** / **`CAST(... AS varchar)`** — used in error-based SQL injection exfiltration on SQL Server.

The `codebase_review.md` §2.1 commentary notes these as structural blocklist gaps. However, no KI issue number was ever assigned and no P2 plan item was created to address them.

**Impact:** The risk is moderate in the context of the extension's threat model. The blocklist is not (and cannot be) a complete SQL injection defence — that is documented. But the absence of `CAST(` and `CONVERT(` means that the most common hex-encoding obfuscation vectors are not intercepted, which reduces the effectiveness of the defence-in-depth layer. Operators who rely on `allow_queries=true` are most exposed.

**Workaround:** Use prepared statements (`query=` + `parameters=`) instead of composed queries wherever possible. Keep `allow_queries` as `false` for all sources.

**Fix:** Add `CAST(` and `CONVERT(` to the `$charPatterns` blocklist in `sanitize()`. These do not have trailing word boundaries (unlike `CHAR(` and `CONCAT(` which are already blocked), so the existing `strpos()` substring match is appropriate:

```php
$charPatterns = [
    ';', '--', '#', '/*', '*/', '<?',
    'CHAR(', 'CONCAT(', 'CAST(', 'CONVERT(',  // ← add CAST( and CONVERT(
];
```

Note: `CONVERT(` has legitimate read-only uses in SQL (e.g., `SELECT CONVERT(price, UNSIGNED INTEGER)`) so this addition may cause false-positive rejections for some operators. Document the change in CHANGELOG and UPGRADE.md, and note the known false-positive risk.

---

### KI-089 — `withOdbcWarnings()` ODBC-Origin Filter Incomplete: Missing `[Progress]`, `[OpenEdge]`, and `[Oracle]` Driver Signatures ✔ Fixed — v1.5.0

**Severity:** Minor (Driver Compatibility — Edge Case)
**File:** `includes/ODBCConnectionManager.php`, `withOdbcWarnings()`
**Status:** ✅ Fixed — v1.5.0 (P2-090) — `[Oracle]` was already present; added `[Progress]`, `[OpenEdge]`, `[DataDirect]`, `[Easysoft]`

**Description:**
The P2-066 fix in v1.5.0 added an ODBC-origin filter to `withOdbcWarnings()` so that only ODBC-driver-generated PHP warnings are converted to `MWException`. The filter checks for the following strings in the warning message (case-insensitive):

- `odbc`
- `[unixODBC]`
- `[Microsoft]`
- `[IBM]`

However, several ODBC driver vendors produce warning messages that may not contain any of these strings:

| Driver | Typical warning prefix | Contains 'odbc'? |
|--------|------------------------|-----------------|
| Progress OpenEdge | `[Progress]` or `[OpenEdge]` | Only if driver name appears in message |
| Oracle instant client | `[Oracle]` | No |
| Easysoft | `[Easysoft]` | No |
| DataDirect | `[DataDirect]` | Possibly not |

A ODBC connection error from a Progress driver formatted as `[Progress] Connection refused to host db.example.com` does not contain the word `odbc`. The P2-066 filter would return `false` for this message, passing it to the original PHP error handler rather than throwing `MWException`. The `odbc_connect()` call would still return `false`, so the connection attempt would fail — but the failure would produce a PHP warning via the system error handler rather than a clean `MWException` with a formatted error message.

In practice, most Progress OpenEdge ODBC warnings include a format like `[Progress][ODBC Open Client][...]` which does contain `odbc` and would be caught. The filter gap is therefore narrow, but it represents an inconsistency in the protection offered to different driver users.

**Impact:** Low probability in practice. If encountered, the user sees a raw PHP warning message rather than a formatted `MWException` error, which may expose internal path information or driver details in non-production environments.

**Workaround:** None required for typical setups. Progress OpenEdge drivers typically include `ODBC` in their warning text.

**Fix:** Extend the filter to include additional known vendor signatures:

```php
if (
    stripos($errstr, 'odbc') === false &&
    stripos($errstr, '[unixODBC]') === false &&
    stripos($errstr, '[Microsoft]') === false &&
    stripos($errstr, '[IBM]') === false &&
    stripos($errstr, '[Progress]') === false &&
    stripos($errstr, '[OpenEdge]') === false &&
    stripos($errstr, '[Oracle]') === false &&
    stripos($errstr, '[DataDirect]') === false &&
    stripos($errstr, '[Easysoft]') === false
) {
    return false; // pass to next handler
}
```

---

### KI-090 — `displayOdbcTable()` Registered with Variadic Signature; Inconsistent with `SFH_OBJECT_ARGS` Pattern Used by Other Parser Functions ✔ Fixed — v1.5.0

**Severity:** Minor (Design / Consistency)
**File:** `includes/ODBCParserFunctions.php`, `displayOdbcTable()`; `includes/ODBCHooks.php`, `onParserFirstCallInit()`
**Status:** ✅ Fixed — v1.5.0 (P2-091) — intentional omission documented with inline comment in `onParserFirstCallInit()`

**Description:**
The five parser functions are registered in `ODBCHooks::onParserFirstCallInit()`. Two of the most complex functions — `odbcQuery()` and `forOdbcTable()` — are registered with the `SFH_OBJECT_ARGS` flag, which causes MediaWiki to pass arguments as a `PPFrame` object and an array of `PPNode` objects rather than as pre-expanded strings. This allows the function to receive unexpanded template arguments and handle edge cases of wiki template argument expansion correctly.

`displayOdbcTable()`, however, is registered with the simpler variadic call convention (`...$params` — no `SFH_OBJECT_ARGS`). This means:

1. MediaWiki pre-expands all arguments before passing them to the function.
2. The function cannot inspect unexpanded parameter nodes (e.g., to handle `{{{param|default}}}` expansion lazily).
3. Argument trimming behavior differs slightly from the `SFH_OBJECT_ARGS` path.

For `displayOdbcTable()`, which only needs to receive a template name and a variable prefix, the practical impact is minimal — the function's requirements do not demand the advanced capabilities of `SFH_OBJECT_ARGS`. However, the inconsistency between registration patterns across the five functions is an unnecessary source of confusion for contributors, and could become a real limitation if `displayOdbcTable()` is extended to accept more complex argument types in future (e.g., data source overrides per-call).

**Impact:** No functional regression in current usage. The inconsistency is a code quality and maintainability concern only.

**Workaround:** Not required.

**Fix (option A — minimal):** Add a comment in `onParserFirstCallInit()` explicitly documenting why `displayOdbcTable` does not use `SFH_OBJECT_ARGS`, so the omission is clearly intentional rather than an oversight:

```php
// Note: displayOdbcTable does not use SFH_OBJECT_ARGS because it only requires
// a template name and variable prefix — pre-expanded strings are sufficient.
// If the function is extended in future, consider promoting to SFH_OBJECT_ARGS.
```

**Fix (option B — full standardisation):** Promote `displayOdbcTable()` to use `SFH_OBJECT_ARGS`, consistent with `odbcQuery()` and `forOdbcTable()`. Update the function signature and argument extraction accordingly.

---

### KI-091 — `composer.json` References End-of-Life Dependency Versions ✔ Fixed — v1.5.0

**Severity:** Minor (Developer Tooling / Maintenance)
**File:** `composer.json`
**Status:** ✅ Fixed — v1.5.0 (P2-092)

**Description:**
The `composer.json` file references two dependency version ranges that include EOL releases:

1. **`composer/installers ^1.0 || ^2.0`** — `composer/installers` 1.x reached end-of-life. The current stable line is 2.x. The `^1.0 || ^2.0` constraint allows Composer to install an EOL version if it selects from the `^1.0` range (which it may do in some resolution scenarios with other packages constraining the 2.x range). The extension adds no installer-specific functionality that requires 1.x compatibility — the `^2.0` constraint alone is sufficient.

2. **`phpunit/phpunit ^9.0 || ^10.0`** — PHPUnit 9.x reached end-of-life in February 2024. Security fixes and bug fixes are only published for PHPUnit 10.x and 11.x. The `^9.0 || ^10.0` constraint permits installation of an EOL PHPUnit version. Projects targeting PHP 8.1+ should require at minimum `^10.0`; projects targeting PHP 8.2+ can use `^11.0`.

**Impact:** Low immediate risk — EOL libraries are not automatically insecure, but they no longer receive security patches. A vulnerability discovered in PHPUnit 9.x (even in a dev dependency used only for testing) would not see a backport. Additionally, CI matrix expansion to PHP 8.2 and 8.3 may surface compatibility warnings from PHPUnit 9.x.

**Workaround:** Developers can pin `phpunit/phpunit` to version `10.*` in `composer.lock` to avoid installing the EOL version. For `composer/installers`, Composer typically selects 2.x in new projects.

**Fix:**
```json
"require": {
    "composer/installers": "^2.0"
},
"require-dev": {
    "phpunit/phpunit": "^10.0 || ^11.0",
    "mediawiki/mediawiki-codesniffer": "^44.0"
}
```

---

### KI-092 — CI Composer Dependency Cache Uses `hashFiles('composer.json')` Instead of `hashFiles('composer.lock')` ✅ Fixed — v1.5.0

**Severity:** Minor (CI / Reproducibility)
**File:** `.github/workflows/ci.yml`
**Status:** ✅ Fixed — v1.5.0 (P2-093) — cache key updated to include `composer.lock`; `composer.lock` generated and committed

**Description:**
The GitHub Actions CI workflow caches the `vendor/` directory using a cache key derived from `hashFiles('composer.json')`. The Composer best-practice for reproducible dependency resolution is to cache on `composer.lock` (which pins exact versions), not on `composer.json` (which specifies version ranges). Two separate CI runs with the same `composer.json` and same cache key can install different transitive dependency versions if one of the dependencies released a new patch version between runs — `composer install` without a lockfile resolves the latest version within each constraint.

Additionally, no `composer.lock` file is present in the repository. Without a lockfile:
- Every fresh `composer install` anywhere in the ecosystem resolves dependencies fresh from Packagist.
- The installed dependency tree varies across developer machines, CI runners, and deployment environments.
- Two CI runs separated by a package release can produce different results despite identical code.

**Impact:** Low in practice — the extension has very few dependencies. However, it creates a category of CI failures that are difficult to diagnose: "the tests pass today but not tomorrow" due to a transitive dependency change, with no lockfile evidence of what changed.

**Workaround:** None beyond pinning specific dependency versions, which is worse than using a lockfile.

**Fix (two-part):**

1. **Commit a `composer.lock` file.** Run `composer install` locally and commit the resulting `composer.lock`. This pins all transitive dependencies to exact versions for reproducible builds.

2. **Update the CI cache key** to hash `composer.lock`:
   ```yaml
   key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
   restore-keys: |
     ${{ runner.os }}-composer-
   ```

---

### KI-093 — `SECURITY.md` v1.0.2 Release History Entry Uses Non-Standard Date Format ✔ Fixed — v1.5.0

**Severity:** Minor (Documentation Consistency)
**File:** `SECURITY.md`, "Security Release History" section
**Status:** ✅ Fixed — v1.5.0 (P2-094) — v1.0.2 and v1.0.1 entries both corrected

**Description:**
Every version entry in the `SECURITY.md` Security Release History section uses the format `Version X.Y.Z (YYYY-MM-DD)` — for example:

```
### Version 1.5.0 (2026-03-03)
### Version 1.4.0 (2026-03-08)
### Version 1.3.0 (2026-03-06)
...
### Version 1.0.1 (2026-03-01)
```

The v1.0.2 entry, however, reads:

```
### Version 1.0.2 (March 2026)
```

The approximate "Month YYYY" format is inconsistent with all other entries. It also reduces the precision of the security audit trail — an operator checking whether a specific vulnerability was present on a system cannot determine the exact date of the v1.0.2 release from this entry.

**Impact:** Cosmetic inconsistency. No functional or security impact. However, `SECURITY.md` is a document specifically reviewed when assessing vulnerability exposure timelines, where date precision matters.

**Workaround:** Not required.

**Fix:** Replace `(March 2026)` with the exact release date in `YYYY-MM-DD` format, consistent with all other entries. Based on the CHANGELOG, v1.0.2's date is `2026-03-02`:

```markdown
### Version 1.0.2 (2026-03-02)
```

---

## KI-094 — `escapeTemplateParam()` Pipe Character Produces Garbled Output

**Introduced:** v1.0.0  
**Severity:** Medium  
**Status:** ✅ Fixed in v1.5.0 — replaced `str_replace()` with `strtr()` for simultaneous replacement (P2-095).  
**Component:** `includes/ODBCParserFunctions.php`, `escapeTemplateParam()`  
**Affects:** `{{#display_odbc_table:}}` output for any database value containing `|`

**Description:**
The `escapeTemplateParam()` method uses sequential `str_replace()` to escape three patterns: `|`, `}}`, and `{{{`. Because `str_replace()` applies replacements in array order, the output of earlier replacements becomes input for later ones:

1. `|` is replaced with `{{!}}` (correct)
2. The `}}` at the end of `{{!}}` is caught by the second replacement → `&#125;&#125;`
3. Final output: `{{!&#125;&#125;` (garbled — renders literally in the browser)

**Impact:** Any database value containing a pipe character (`|`) — common in CSV-style data, URLs with query parameters, log messages, or concatenated identifiers — renders as the literal string `{{!&#125;&#125;` when displayed via `{{#display_odbc_table:}}`. The unit test `testEscapeTemplateParamPipe()` explicitly asserts this garbled output and documents it as a "known interaction."

**Workaround:** Avoid database values containing `|` in columns displayed via `{{#display_odbc_table:}}`. Use `{{#for_odbc_table:}}` instead, which uses direct variable substitution without `escapeTemplateParam()`.

**Fix:** Replace `str_replace()` with `strtr()`, which applies all replacements simultaneously:

```php
return strtr( $value, [
    '|'   => '{{!}}',
    '}}'  => '&#125;&#125;',
    '{{{' => '&#123;&#123;&#123;',
] );
```

---

## KI-095 — CHANGELOG.md v1.5.0 Still Marked `[Unreleased]` — Fifth Consecutive Occurrence

**Introduced:** v1.5.0  
**Severity:** Medium  
**Status:** ✅ Fixed in v1.5.0 — header dated `2026-03-03`; CI `changelog-check` job added on push to `main` (P2-096).  
**Component:** `CHANGELOG.md`, release process  
**Related:** KI-030, KI-041, KI-063

**Description:**
`CHANGELOG.md` line 8 reads `## [Unreleased] — v1.5.0`. This is the fifth consecutive release with an `[Unreleased]` header:

- v1.0.3: KI-030 → fixed by P2-026
- v1.1.0: KI-041 → fixed by P2-036
- v1.4.0: KI-063 → fixed by P2-063 (also added a CI check)
- v1.5.0: still `[Unreleased]` despite the CI check

The CI check added in P2-063 only fires on `refs/tags/` pushes, not on merges to `main`. It prevents tag creation but not the actual problem: merging code with `[Unreleased]` in the header. Meanwhile, `SECURITY.md` correctly shows `Version 1.5.0 (2026-03-03)`, creating a date inconsistency.

**Impact:** Operators reviewing CHANGELOG.md see a version that appears unreleased, contradicting `extension.json` and `SECURITY.md`. Downstream packaging tools that parse CHANGELOG headers may treat v1.5.0 as pre-release.

**Workaround:** None.

**Fix:** (1) Replace `[Unreleased]` with `2026-03-03`. (2) Add a CI check on `push` to `main` that warns when CHANGELOG contains `[Unreleased]` for a version matching `extension.json`. (3) Add a release checklist step.

---

## KI-096 — `wiki/Special-ODBCAdmin.md` Claims Test Query Bypasses Arbitrary Query Check

**Introduced:** v1.3.0 (documentation not updated after code change)  
**Severity:** Medium  
**Status:** ✅ Fixed in v1.5.0 — all three stale bypass claims updated across `wiki/Special-ODBCAdmin.md` and `wiki/Security.md` (P2-097).  
**Component:** `wiki/Special-ODBCAdmin.md`, `wiki/Security.md`

**Description:**
Three documentation locations state that the `Special:ODBCAdmin` test query bypasses `$wgODBCAllowArbitraryQueries`:

1. `wiki/Special-ODBCAdmin.md`: "The query bypasses the `$wgODBCAllowArbitraryQueries` check"
2. `wiki/Security.md`, Known Security Limitations section
3. `wiki/Security.md`, Attack Surface section (KI-026 reference)

P2-056 (v1.3.0) explicitly added enforcement of this check in `runTestQuery()`. The `wiki/Security.md` release history table correctly describes the v1.3.0 change but three other locations on the same page and `Special-ODBCAdmin.md` were not updated.

**Impact:** Admin operators relying on this documentation may not understand why test queries fail when `$wgODBCAllowArbitraryQueries` is `false`.

**Workaround:** Read the v1.3.0 entry in the Security Release History table.

**Fix:** Update all three locations to reflect the current enforcement behavior.

---

## KI-097 — `wiki/Home.md` and `wiki/_Footer.md` Version Frozen at 1.0.3

**Introduced:** v1.1.0 (first version after the displayed 1.0.3)  
**Severity:** Medium  
**Status:** ✅ Fixed in v1.5.0 — both updated to 1.5.0 (P2-098).  
**Component:** `wiki/Home.md`, `wiki/_Footer.md`

**Description:**
`wiki/Home.md` line 3 displays "**Version:** 1.0.3" and `wiki/_Footer.md` displays "v1.0.3". The actual current version is 1.5.0 (per `extension.json`). These files have not been updated in 5 releases. The GitHub wiki landing page presents a project that appears abandoned at v1.0.3.

**Impact:** First-impression credibility. Visitors may assume the extension is unmaintained.

**Workaround:** None.

**Fix:** Update both version references to 1.5.0. Consider using a version-agnostic approach (e.g., "See `extension.json` for current version") to prevent future staleness.

---

## KI-098 — `wiki/Contributing.md` Contains Multiple Stale Claims

**Introduced:** v1.4.0 (first version with `require-dev`); v1.5.0 (first version with tests)  
**Severity:** Medium  
**Status:** ✅ Fixed in v1.5.0 — removed false `require-dev` and testing claims; added test suite instructions; updated contribution areas (P2-099).  
**Component:** `wiki/Contributing.md`

**Description:**
Three distinct stale claims:

1. "There are currently no `require-dev` dependencies defined." — False since v1.4.0 (P2-062).
2. "There are currently no automated tests." — False since v1.5.0. Three test files exist with ~70 assertions.
3. The "Areas Needing Contribution" tables list P2-017, P2-018, P2-021, P2-007, P2-019, P2-020, P2-022, P2-023, P3-003, P3-004, P2-008 — all marked ✅ Done or partially done. The entire section suggests no contribution has been made since v1.0.3.

**Impact:** Contributors read incorrect information about the project's testing maturity and open work items.

**Workaround:** Cross-reference `improvement_plan.md` directly.

**Fix:** (1) Remove the stale `require-dev` and "no tests" notes. (2) Update the "Areas Needing Contribution" table to reflect currently-open items from the improvement plan (P3-001, P3-002, P3-005, P3-006, and the remaining portion of P3-003/P3-004). (3) Add instructions for running `composer test` and `composer phpcs`.

---

## KI-099 — `wiki/Architecture.md` Design Limitations Table Contains Stale Rows

**Introduced:** v1.3.0 (ODBCQueryRunner became instance-based); v1.5.0 (tests added)  
**Severity:** Low  
**Status:** ✅ Fixed in v1.5.0 — static-classes row updated for `ODBCConnectionManager` only; unit-tests row updated to reflect existing suite (P2-100).  
**Component:** `wiki/Architecture.md`

**Description:**
The Design Limitations table has four rows. Two are stale:

- "All-static classes / Not testable / P3-001" — `ODBCQueryRunner` is instance-based since v1.3.0 (P2-055). Only `ODBCConnectionManager` remains all-static.
- "No unit tests / No regression protection / P3-003" — 3 test files with ~70 assertions exist since v1.5.0.

The remaining two ("No PHP namespaces" and "No interfaces") are still accurate.

**Impact:** Contributors assessing the project's technical debt see a worse picture than reality.

**Workaround:** None.

**Fix:** Update the table to reflect current state. Change the static-classes row to note only `ODBCConnectionManager`. Update the unit-tests row to reflect existing tests and remaining gaps.

---

## KI-100 — `wiki/External-Data-Integration.md` Contains 3 Stale Warnings

**Introduced:** v1.1.0 (code fixes not reflected in wiki)  
**Severity:** Medium  
**Status:** ✅ Fixed in v1.5.0 — KI-027 workaround removed; feature parity table updated; KI-028 warning corrected (P2-101).  
**Component:** `wiki/External-Data-Integration.md`

**Description:**
Three stale warnings:

1. **KI-027 warning (line ~68):** States the ODBC connector "does not inherit the driver type from `$wgODBCSources`" and recommends adding `'driver'` redundantly. KI-027 was fixed in v1.1.0 (P2-021). The workaround is unnecessary.

2. **Feature parity table:** Shows "UTF-8 conversion: ❌ No" and "Query result caching: ❌ No" for External Data. Since v1.1.0, `odbc_source` mode routes through `executeRawQuery()`, gaining both UTF-8 conversion and caching. Both should be "⚠️ Partial (via `odbc_source`)."

3. **KI-028 warning (line ~146):** States "Only the boolean literal `false` disables the integration." P2-022 (v1.1.0) changed the check to `!$wgODBCExternalDataIntegration`, meaning any falsy value now disables integration. The warning is **factually incorrect**.

**Impact:** Operators follow unnecessary workarounds (KI-027), underestimate ED capabilities (feature parity), or are confused by behavior that contradicts the documented limitation (KI-028).

**Workaround:** None.

**Fix:** (1) Replace KI-027 warning with a "Fixed in v1.1.0" note. (2) Update feature parity table rows. (3) Remove or correct the KI-028 warning.

---

## KI-101 — `wiki/Security.md` Blocklist Table Missing `CAST(` and `CONVERT(`

**Introduced:** v1.5.0 (blocklist updated; wiki not)  
**Severity:** Low  
**Status:** ✅ Fixed in v1.5.0 — both patterns added to the blocklist table (P2-102).  
**Component:** `wiki/Security.md`

**Description:**
The "Keyword blocklist" table in `wiki/Security.md` lists all patterns blocked by `sanitize()` but omits `CAST(` and `CONVERT(`, which were added in P2-089 (v1.5.0). These are standard SQL obfuscation vectors (e.g., `CAST(0x44524F50 AS CHAR)` → `DROP`).

Additionally, the same page has three stale claims about `$wgODBCAllowArbitraryQueries` bypass (see KI-096).

**Impact:** Security auditors assessing the blocklist see incomplete coverage documentation.

**Workaround:** Review the actual `sanitize()` source code.

**Fix:** Add `CAST(` and `CONVERT(` rows to the blocklist table with appropriate "Obfuscation function" descriptions.

---

## KI-102 — `wiki/Parser-Functions.md` Worked Example References Non-Existent Variable

**Introduced:** v1.0.0 (original documentation)  
**Severity:** Low  
**Status:** ✅ Fixed in v1.5.0 — replaced `first_count` with `FirstName` (a mapped variable from the query) (P2-103).  
**Component:** `wiki/Parser-Functions.md`

**Description:**
The worked example at the bottom of the page contains:

```wiki
Total engineers: '''{{#odbc_value: first_count | 0}}'''
```

The `dept_employees` query maps `FirstName`, `LastName`, `Department`, `Email`. There is no `first_count` variable in the result. This expression always returns the default value `0`, misleading readers into thinking `#odbc_value` can count result rows.

**Impact:** Readers copy the example expecting a count and get a permanent `0`.

**Workaround:** None.

**Fix:** Replace with a valid variable like `FirstName` or remove the misleading count reference and add a comment noting that `#odbc_value` retrieves stored column values, not row counts.

---

## KI-103 — CI Workflow Does Not Run Existing PHPUnit Tests

**Introduced:** v1.5.0 (tests added but CI not updated)  
**Severity:** Medium  
**Status:** ✅ Fixed in v1.5.0 — `phpunit` job added to CI workflow (P2-104).  
**Component:** `.github/workflows/ci.yml`

**Description:**
The CI workflow runs PHP lint, PHPCS, and PHPStan but does not run PHPUnit. The workflow comments state "PHPUnit tests are planned for v2.0.0" but all prerequisites are already in place:

- `phpunit.xml.dist` exists and is fully configured
- 3 test files with ~70 assertions exist in `tests/unit/`
- `tests/bootstrap.php` provides standalone stubs (no MW installation required)
- `composer test` script is defined

Adding a PHPUnit job requires ~10 lines of YAML.

**Impact:** Regressions in `sanitize()`, `validateIdentifier()`, `buildConnectionString()`, `parseDataMappings()`, and other tested methods are not caught by CI.

**Workaround:** Run `composer test` locally before pushing.

**Fix:** Add a `phpunit` job to `.github/workflows/ci.yml`:

```yaml
phpunit:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.1'
    - run: composer install --no-progress
    - run: composer test
```

---

## KI-104 — Test Suite Documents Bug as Expected Behavior

**Introduced:** v1.5.0  
**Severity:** Low  
**Status:** ✅ Fixed in v1.5.0 — test updated to assert correct output `A{{!}}B` alongside the KI-094 fix (P2-105).  
**Component:** `tests/unit/ODBCParserFunctionsTest.php`

**Description:**
`testEscapeTemplateParamPipe()` asserts the garbled output `A{{!&#125;&#125;B` (see KI-094) as the expected result. The test comment acknowledges the "known interaction of the sequential str_replace approach." This has two negative effects:

1. If someone fixes the bug (KI-094), this test will fail even though the fix is correct
2. The test gives a false sense of passing coverage over broken behavior

**Impact:** Developers see 100% green tests and assume pipe escaping works correctly.

**Workaround:** None.

**Fix:** Update the test alongside the KI-094 fix to assert the correct output `A{{!}}B`.

---

## KI-105 — `MWException` Inheritance Mismatch Between Test Bootstrap and PHPStan Stubs

**Introduced:** v1.5.0  
**Severity:** Low  
**Status:** ✅ Fixed in v1.5.0 — stubs file changed to `extends Exception`; also fixed PHP syntax error from mixed global/namespaced code (P2-106).  
**Component:** `tests/bootstrap.php`, `stubs/MediaWikiStubs.php`

**Description:**
Two different `MWException` definitions exist:

- `tests/bootstrap.php`: `class MWException extends Exception`
- `stubs/MediaWikiStubs.php`: `class MWException extends RuntimeException`

In real MediaWiki core, `MWException extends Exception`. The PHPStan stubs file uses `RuntimeException`, which is technically incorrect. While both are `Throwable` and current tests are unaffected, `catch (RuntimeException $e)` blocks would behave differently depending on which stub is loaded.

**Impact:** Minimal for current tests. Could cause subtle test failures during future expansion.

**Workaround:** None.

**Fix:** Change `stubs/MediaWikiStubs.php` to `class MWException extends Exception` to match MW core.

---

*Last updated: v1.5.0 (2026-03-09) — KI-094 through KI-105 identified in Review Pass 10 and all resolved. 105 total issues tracked; 104 fully resolved; 1 remains open by design (KI-008 SELECT\* default). KI-020 (ED standalone caching) partially resolved. KI-092 fully resolved — composer.lock committed.*
