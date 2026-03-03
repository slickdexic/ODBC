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
