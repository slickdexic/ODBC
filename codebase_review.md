# Codebase Review: MediaWiki ODBC Extension

**Review Date:** 2026-03-03 (initial); updated 2026-03-03 (v1.1.0 re-review); updated 2026-03-05 (v1.1.0 final pass)  
**Extension Version:** 1.1.0  
**Reviewer:** GitHub Copilot (automated critical review)

---

## Executive Summary

The ODBC extension is an ambitious and largely functional MediaWiki extension that covers connection management, SQL query execution, parser functions, an admin special page, and integration with the External Data extension. A significant number of bugs, security vulnerabilities, and documentation errors identified in earlier reviews were addressed in v1.0.2 and v1.0.3. The v1.1.0 release further resolved 7 code bugs (KI-023 through KI-028, KI-032) and added Progress OpenEdge database support.

Of the original 15 most critical issues, all have now been resolved. What remains reflects deeper architectural debt that cannot be patched incrementally. The codebase is now in a substantially better state, but still carries **structural weaknesses** and **absent quality infrastructure** that prevent it from being considered world-class.

This document reflects the v1.1.0 state. Findings from prior reviews that have since been fixed are noted as resolved.

---

## Issues Resolved Since the v1.0.2 Review

The following issues from the original review have been fixed. They are retained here for historical reference but are not repeated in full below.

| # | Summary | Fixed In |
|---|---------|----------|
| 1.1 | Magic word case-sensitivity flag was `1` (case-sensitive); changed to `0` | v1.0.3 |
| 1.2 | Connection liveness check used `odbc_error()` (error history, not state); replaced with `SELECT 1` ping | v1.0.3 |
| 1.3 | Cache key collision due to `implode(',', $params)`; replaced with `json_encode()` | v1.0.3 |
| 1.4 | Double loop over `$columns` in `executeComposed()`; merged into single pass | v1.0.3 |
| 1.5 | Query timeout set on connection handle (ignored by drivers); moved to statement level | v1.0.3 |
| 1.6 | `$wgODBCMaxRows` not enforced in ED connector `fetch()` | v1.0.3 |
| 1.7 | ED connector always emitted `LIMIT N` even for SQL Server/Access | v1.0.3 |
| 1.8 | `getTableColumns()` only checked `COLUMN_NAME` and `column_name`; now uses `array_change_key_case()` | v1.0.3 |
| 1.9 | `wfDebugLog()` call had misaligned indentation in `executeRawQuery()` | v1.0.3 |
| 2.2 | XSS in admin query results via `Html::rawElement()` | v1.0.2 |
| 2.3 | Wikitext injection via `{{{` and `\|` in `display_odbc_table` and `for_odbc_table` | v1.0.2 |
| 3.3 | DSN building logic duplicated in ED connector; now delegates to `ODBCConnectionManager::buildConnectionString()` | v1.0.3 |
| 3.6 | `MAX_CONNECTIONS` hardcoded constant; replaced with `$wgODBCMaxConnections` config key | v1.0.3 |
| 5.4 | Magic number `100` in `runTestQuery()`; replaced with `ADMIN_QUERY_MAX_ROWS` constant | v1.0.3 |
| 1.1 | `pingConnection()` fails on MS Access (`SELECT 1` without FROM); now uses driver-aware `MSysObjects` probe | v1.1.0 |
| 1.2 | `UNION` blocked as substring; moved to word-boundary regex `/\bUNION\b/i` | v1.1.0 |
| 1.3 | `buildConnectionString()` values not escaped; `escapeConnectionStringValue()` added | v1.1.0 |
| 1.4 | `validateConfig()` was dead code; now called from `connect()` | v1.1.0 |
| 1.5 | ED `odbc_source` mode ignores driver name; `__construct()` now inherits from `$wgODBCSources` | v1.1.0 |
| 1.6 | `=== false` strict check on `$wgODBCExternalDataIntegration`; changed to `!...` | v1.1.0 |
| 1.8 | `sanitize()` keyword patterns missing trailing `\b`; all patterns fixed to `/\bKEYWORD\b/i` | v1.1.0 |
| 4.7 | `wiki/Architecture.md` 5 factual errors (static/instance, signatures, expandTemplate, LRU, getTableList) | v1.1.0 |
| 4.8 | `wiki/Known-Issues.md` KI-008 description wrong (partial data= vs omitted entirely) | v1.1.0 |
| 4.9 | README magic word claim said v1.0.1+, corrected to v1.0.3+ | v1.1.0 |
| 4.11 | UPGRADE.md `$GLOBALS['wgODBCMaxConnections']` → `$wgODBCMaxConnections` | v1.1.0 |
| 4.12 | README troubleshooting still said "modify MAX_CONNECTIONS constant" | v1.1.0 |
| 4.13 | CHANGELOG v1.1.0 tagged "Unreleased" instead of release date | v1.1.0 |
| 4.14 | `wiki/Architecture.md` `buildConnectionString()` described as not handling Mode 1 or Mode 3 | v1.1.0 |
| 4.15 | `wiki/Security.md` stale KI-024 warning after fix was shipped | v1.1.0 |
| 4.16 | `SECURITY.md` Known Limitations described old double-emit LIMIT bug | v1.1.0 |
| 4.17 | `UPGRADE.md` v1.0.1 section falsely claimed magic word case fix | v1.1.0 |
| 4.18 | `wiki/Parser-Functions.md` `data=` marked Required when it is optional | v1.1.0 |
| 5.7 | `@odbc_setoption()` failure was silent; now logs via `wfDebugLog()` | v1.1.0 |
| 1.9 | `validateConfig()` rejected valid Progress OpenEdge configs using `host` key; `empty($config['host'])` added | v1.1.0 |

---

---

## 1. Bugs

### 1.1 `pingConnection()` Fails Silently on MS Access ✅ Fixed in v1.1.0

**File:** `includes/ODBCConnectionManager.php`, `pingConnection()`  
**Status:** ✅ Fixed in v1.1.0 — driver-aware probe using `SELECT 1 FROM MSysObjects WHERE 1=0`

The connection liveness check uses:

```php
$result = odbc_exec( $conn, 'SELECT 1' );
```

`SELECT 1` (without a `FROM` clause) is not valid MS Access SQL. MS Access requires the Jet/ACE engine form `SELECT 1 FROM MSysObjects` (or equivalent), and even that requires read permission on system tables. As a result, every cached connection to an MS Access database will fail the ping, be discarded, and a fresh connection will be opened on every query — effectively defeating the connection pool for the most common "desktop database" use case.

**Impact:** MS Access users experience connection overhead on every query, and repeated `odbc_close()` + reconnection may leave ODBC handle counts high under load. Worse, the reconnection may also fail if the driver enforces a maximum connection count.

**Fix:** The ping query should be driver-aware. For Access, use `SELECT COUNT(*) FROM MSysObjects LIMIT 1` or skip the probe and rely on exception-catching on first query use instead.

---

### 1.2 `UNION` Keyword Check Causes False Positives on Valid Identifiers ✅ Fixed in v1.1.0

**File:** `includes/ODBCQueryRunner.php`, `sanitize()`  
**Status:** ✅ Fixed in v1.1.0 — `UNION` moved to `$keywords` list with `/\bUNION\b/i`

`UNION` is placed in `$charPatterns` and matched via `strpos()`:

```php
$charPatterns = [ ';', '--', '#', '/*', '*/', '<?', 'CHAR(', 'CONCAT(', 'UNION' ];
```

Unlike the `$keywords` list which uses word-boundary regex (`\bKEYWORD\b`), `strpos()` is a pure substring match. This means any table name, column name, alias, or value that *contains* the substring `UNION` will be rejected — including perfectly legitimate identifiers like `LABOUR_UNION`, `UNION_ID`, `TRADE_UNION_TYPE`, or a database named `UNION_DB`. Wiki editors using these identifiers in `from=`, `where=`, or `data=` parameters will receive an unhelpful `"Illegal SQL pattern 'UNION'"` error with no obvious workaround.

**Impact:** False-positive rejections of legitimate queries. No workaround available to wiki editors.

**Fix:** Move `UNION` from `$charPatterns` to the word-boundary `$keywords` list, changing the match to `'/\bUNION\b/i'`. This correctly blocks `UNION SELECT` and standalone `UNION` while permitting identifiers that happen to contain the substring.

---

### 1.3 `buildConnectionString()` Does Not Escape Special Characters in Values ✅ Fixed in v1.1.0

**File:** `includes/ODBCConnectionManager.php`, `buildConnectionString()`  
**Status:** ✅ Fixed in v1.1.0 — `escapeConnectionStringValue()` wraps values per ODBC spec

When building a driver-based connection string, the method interpolates config values directly:

```php
$parts[] = 'Server=' . $config['server'];
$parts[] = 'Database=' . $config['database'];
```

If any value contains a `;` character (e.g., a server address with an unusual format, or a database name with a semicolon), the resulting connection string is syntactically invalid or allows injection of additional connection string key-value pairs. ODBC connection strings use `;` as a delimiter; an unescaped `;` in a value terminates that value and begins a new attribute. An attacker who can influence `$wgODBCSources` configuration values (e.g., via a compromised LocalSettings.php) could inject arbitrary connection attributes such as `Trusted_Connection=yes` or driver-specific options.

A similar issue exists for `{` and `}` characters in the `Driver=` component, where the driver name is wrapped in braces: if the driver string already contains a `}`, the wrapping logic produces `Driver={...}...}`, which is invalid.

**Impact:** Configuration errors leading to connection failures, or in adversarial scenarios, connection string attribute injection.

**Fix:** Values that may contain `;`, `=`, `{`, `}` must be enclosed in curly braces `{...}` per the ODBC connection string spec, with any `}` inside the value escaped as `}}`.

---

### 1.4 `validateConfig()` Is Dead Code ✅ Fixed in v1.1.0

**File:** `includes/ODBCConnectionManager.php`, `validateConfig()`  
**Status:** ✅ Fixed in v1.1.0 — called from `connect()` before any connection attempt

`ODBCConnectionManager::validateConfig()` exists and is documented, but it is called **nowhere** in the codebase — not from `connect()`, not from `SpecialODBCAdmin`, not from `ODBCQueryRunner`. Configuration errors (missing `dsn`, `driver`, or `connection_string`) are not detected until a connection attempt fails, at which point the ODBC driver error message is the only feedback. Admin interfaces benefit from early validation that surfaces missing config keys with clear messages before a connection is attempted.

**Impact:** Misconfiguration is more difficult to diagnose; the validation method's existence implies safety that is not provided.

**Fix:** Call `validateConfig()` from within `connect()` before attempting `odbc_connect()`, and from the `showTestResult()` admin action before `testConnection()`.

---

### 1.5 ED Connector `odbc_source` Mode Always Uses LIMIT Syntax for SQL Server ✅ Fixed in v1.1.0

**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `getQuery()`  
**Status:** ✅ Fixed in v1.1.0 — `__construct()` now inherits `driver` from `$wgODBCSources`

When the ED connector uses the `odbc_source` mode (an `$wgExternalDataSources` entry that references an `$wgODBCSources` key via `'odbc_source' => '...'`), the driver name is read from `$this->credentials['driver']`. However, `setCredentials()` only populates `$this->credentials['driver']` from the **External Data** config array — it does **not** read back the driver from the referenced `$wgODBCSources` entry. For an `odbc_source` reference, the External Data source config typically only has `type` and `odbc_source` keys, not `driver`. This means `credentials['driver']` remains empty, `requiresTopSyntax()` returns `false`, and LIMIT syntax is used even when the underlying `$wgODBCSources` entry is a SQL Server database.

```php
// In setCredentials(), driver is only read from External Data config:
$this->credentials['driver'] = $params['driver'] ?? ''; // empty for odbc_source refs
```

**Impact:** Users running External Data parser functions against a SQL Server source configured via `odbc_source` receive a syntax error from SQL Server on every query that uses a row limit.

**Fix:** In `connect()`, when `$this->odbcSourceId` is set, read the driver from `ODBCConnectionManager::getSourceConfig( $this->odbcSourceId )['driver']` and populate `$this->credentials['driver']` accordingly.

---

### 1.6 `$wgODBCExternalDataIntegration = 0` Does Not Disable Integration ✅ Fixed in v1.1.0

**File:** `includes/ODBCHooks.php`, `registerExternalDataConnector()`  
**Status:** ✅ Fixed in v1.1.0 — check changed to `!$wgODBCExternalDataIntegration`

The integration disable check uses strict identity comparison:

```php
if ( $wgODBCExternalDataIntegration === false ) {
    return;
}
```

An operator who sets `$wgODBCExternalDataIntegration = 0;` (integer zero — a natural choice for a boolean-like flag in PHP) will find the integration is still registered, because `0 === false` is `false` in PHP. The same applies to `null`, empty string `''`, and other falsy values. Only the literal boolean `false` disables the feature.

**Impact:** Operators who intend to disable ED integration via a falsy value find the integration is silently still active.

**Fix:** Change the check to `if ( !$wgODBCExternalDataIntegration )` or add a comment explicitly documenting that only `= false` (not `= 0`) disables the feature.

---

### 1.7 `SELECT *` by Default Can Return Unbounded Column Sets

**File:** `includes/ODBCParserFunctions.php` and `includes/ODBCQueryRunner.php`

When no `data=` parameter is provided, the code sets:

```php
$dbColumns = !empty( $columns ) ? $columns : [ '*' => '*' ];
```

This causes `SELECT *` to be issued, fetching all columns from the table. Subsequently, all column names and values are stored in `$storedData`. For wide tables or tables with sensitive columns, this silently exposes all columns to the wiki page. There is no warning or indication that this behavior is occurring. This is both a data exposure concern and a memory/performance concern for large result sets.

---

### 1.8 `sanitize()` Keyword Patterns Lack a Trailing Word Boundary ✅ Fixed in v1.1.0

**File:** `includes/ODBCQueryRunner.php`, `sanitize()`  
**Status:** ✅ Fixed in v1.1.0 — all patterns changed to `/\bKEYWORD\b/i`

The `$keywords` list is matched via per-keyword regex patterns built as:

```php
$pattern = '/\b' . preg_quote( $keyword, '/' ) . '/i';
```

This produces `/\bKEYWORD/i` — a **leading** word boundary only, with **no trailing `\b`**. As a result, a keyword such as `DECLARE` will match any string that *starts with* that keyword, even when the match is a longer valid identifier:

- Column name `DECLARED_AT` → blocked by `DECLARE`
- Column name `DELETION_FLAG` → blocked by `DELETE`
- Column name `GRANTED_BY` → blocked by `GRANT`
- Table name `MERGERS` → blocked by `MERGE`
- WHERE clause `WHERE status='DECLARED'` → `DECLARE` matches inside the string value

The existing review (section 1.2) correctly identified that `UNION` in `$charPatterns` has no word-boundary protection and proposed moving it to the `$keywords` list with `/\bUNION\b/i`. However, that section described the `$keywords` list as already using `\bKEYWORD\b` — this is **incorrect**. The actual regex in the code is `/\bKEYWORD/i` (no trailing `\b`). The two bugs are related but distinct: UNION uses `strpos()` (no boundaries at all), while the rest of the keyword list uses `\bKEYWORD` (leading boundary only).

**Impact:** False-positive query rejections for wiki editors whose database schema uses column or table names that *begin with* a SQL reserved word — a realistic scenario (e.g., `deleted_at`, `granted_by`, `merged_on`, `declared`, `executed_by` are common in audit-trail schemas). WHERE clause string values that begin with a blocked keyword are also rejected with no workaround except using a prepared statement.

**Fix:** Add the trailing word boundary to all patterns in the keywords loop:

```php
$pattern = '/\b' . preg_quote( $keyword, '/' ) . '\b/i';
```

Patterns already ending in a non-word character (`SLEEP(`, `CHAR(`, `XP_`, `SP_`, `SYS.`) need individual handling since `(`, `.`, and `_` require different word-boundary treatment. Document the special-case handling explicitly in a code comment.

---

### 1.9 `validateConfig()` Passes Driver+Host Configurations as Invalid ✅ Fixed in v1.1.0 (P2-035)

**File:** `includes/ODBCConnectionManager.php`, `validateConfig()`  
**Status:** ✅ Fixed in v1.1.0 (P2-035) — `empty( $config['host'] )` added to the driver-mode server check

The validation check for driver-mode configuration tests `server` and `dsn` as the only acceptable alternatives:

```php
if ( $hasDriver && empty( $config['server'] ) && empty( $config['dsn'] ) ) {
    $errors[] = 'server (required when using driver mode)';
}
```

However, Progress OpenEdge drivers use the `host` key (not `server`). All official documentation — README, UPGRADE.md — explicitly instructs operators to configure Progress sources using `host` and `db`. A valid, correctly-configured Progress source such as:

```php
$wgODBCSources['progress-erp'] = [
    'driver' => 'Progress OpenEdge 11.7 Driver',
    'host'   => 'db.example.com',
    'port'   => '32770',
    'db'     => 'sports2000',
    'user'   => 'admin',
    'password' => 'pw',
];
```

will **fail `validateConfig()`** with `"missing required field(s): server (required when using driver mode)"` because `$config['server']` is empty and `$config['dsn']` is empty. `buildConnectionString()` would produce a valid `Host=db.example.com;...` string if allowed to run, but `validateConfig()` — which is now called from `connect()` before any connection attempt — blocks it first.

This is a regression introduced by v1.1.0: the Progress OpenEdge support was added to `buildConnectionString()` but `validateConfig()` was not updated to recognise `host` as a valid server-equivalent key.

**Impact:** All Progress OpenEdge operators who follow the documented configuration examples will get a misleading config-validation error at connection time, with no explanation that `host` is being rejected as an alternative to `server`. Connections to Progress databases are completely broken for correctly-configured sources.

**Fix:** Add `|| !empty( $config['host'] )` to the driver-mode server check:
```php
if ( $hasDriver && empty( $config['server'] ) && empty( $config['host'] ) && empty( $config['dsn'] ) ) {
    $errors[] = 'server or host (required when using driver mode)';
}
```

---

## 2. Security Issues

### 2.1 Keyword Blocklist Sanitization Is Fundamentally Weak

**File:** `includes/ODBCQueryRunner.php`, `sanitize()`

The sanitize method is the primary SQL injection defence for composed queries. It relies on a blocklist of keywords and patterns. Blocklist-based SQL injection prevention is well-documented as insufficient — it is always possible to find evasions. The v1.0.3 release added several keywords (`#`, `WAITFOR`, `SLEEP(`, `BENCHMARK(`, `PG_SLEEP(`, `DECLARE`, `UTL_FILE`, `UTL_HTTP`) but the approach has structural gaps:

- `EXTRACTVALUE()`, `UPDATEXML()` — MySQL out-of-band data extraction techniques — are not blocked
- `LOAD XML LOCAL INFILE` is not blocked (only `LOAD DATA` and `LOAD_FILE`)  
- `CAST(` and `CONVERT(` can be used in some database fingerprinting techniques
- Encoding tricks using hex literals (`0x41 0x44 0x4D 0x49 0x4E`) can represent blocked keywords in some database contexts  
- The WHERE/ORDER BY/GROUP BY/HAVING inputs are still raw user-controlled strings that are only blocked by this list — they are not parameterized

The sanitization approach provides a useful layer of defence but should never be the **only** layer. The README and SECURITY.md both state this clearly, but the architecture still depends on it as the primary barrier for composed queries.

---

### 2.2 Admin Interface Runs Arbitrary SELECT Without Checking `$wgODBCAllowArbitraryQueries`

**File:** `includes/specials/SpecialODBCAdmin.php`, `runTestQuery()`

```php
$runner = new ODBCQueryRunner( $sourceId );
$rows = $runner->executeRawQuery( $sql, [], $maxRows );
```

`executeRawQuery()` is invoked directly, bypassing the `$wgODBCAllowArbitraryQueries` check that is enforced in `executeComposed()`. An administrator can run arbitrary SELECT queries via the admin interface even when `$wgODBCAllowArbitraryQueries = false`. The admin-only SELECT restriction is enforced, but the underlying config intent is violated when the admin accesses sources that should be prepared-statement-only.

**Impact:** The permission boundary `$wgODBCAllowArbitraryQueries` is not respected uniformly — it can be bypassed by anyone with `odbc-admin` permission. This may surprise operators who set the global to false expecting all ad-hoc SQL execution to be blocked.

**Recommendation:** Either document clearly that `odbc-admin` supersedes the arbitrary queries restriction (accept the design), or add a check in `runTestQuery()` that enforces the same config constraint.

---

### 2.3 No Rate Limiting on Parser Function Query Execution

A single wiki page can call `{{#odbc_query:}}` many times through template transclusions. There is no per-page or per-request limit on how many ODBC queries execute. A user with `odbc-query` permission and edit access could construct a template included on many pages that generates dozens of database queries per page view, effectively mounting a denial-of-service attack on the target database.

**Status:** Partially mitigated by restricting `odbc-query` to trusted groups only (the default gives it only to sysops). But there is no technical enforcement — the risk scales with how broadly the permission is granted.

---

### 2.4 `odbc_setoption()` for Timeout Is Still Silently Suppressed ✦ ✅ Already resolved (v1.1.0 — KI-033 / P2-023)

**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`

```php
@odbc_setoption( $stmt, self::SQL_HANDLE_STMT, self::SQL_QUERY_TIMEOUT, $timeout );
```

The `@` error suppression operator is **intentional** here. `withOdbcWarnings()` converts PHP warnings from `ext-odbc` into `MWException`s; using it around `odbc_setoption()` would throw on every unsupported driver. The `@` suppressor prevents that conversion so `odbc_setoption` can return `false` gracefully. The code already has:

```php
if ( $timeoutSet === false ) {
    wfDebugLog( 'odbc', "Timeout $timeout s could not be set on source '{$this->sourceId}': " .
        "odbc_setoption() returned false (driver may not support statement-level timeouts)" );
}
```

This was fixed in v1.1.0 (KI-033). No further action required.

---

## 3. Design Problems

### 3.1 All-Static Design for Connection Management Violates Testability and Modern PHP

**File:** `includes/ODBCConnectionManager.php`

`ODBCConnectionManager` is a fully static class — no instance, no constructor injection, no interfaces. This is 2010-era PHP style. In 2026, with PHP 8.x and MediaWiki's own shift to dependency injection via `MediaWikiServices`, this pattern:

- Makes unit testing impossible (cannot mock static classes without specialized tooling)
- Cannot be swapped for a mock or alternative implementation in tests
- Creates global state (via `self::$connections`) that can leak between test cases
- Violates the Dependency Inversion Principle

The correct MediaWiki pattern is a service class registered in `ServiceWiring.php`, retrieved via `MediaWikiServices` and injectable into dependent classes.

---

### 3.2 Mixed Static/Instance Methods on `ODBCQueryRunner`

**File:** `includes/ODBCQueryRunner.php`

`ODBCQueryRunner` is an instance class but has several `static` methods (`sanitize()`, `validateIdentifier()`, `requiresTopSyntax()`). `sanitize()` is called externally by the ED connector. This hybrid design blurs the class interface — public static methods are effectively a global API accessed without instantiation. `sanitize()` should be on a dedicated `ODBCSanitizer` class or made part of a proper instance-based service.

---

### 3.3 Inconsistent Result Representation Between Code Paths

When queries run via `ODBCQueryRunner::executeRawQuery()`, results are returned as an **array of associative arrays** (via `odbc_fetch_array()`). When running via `EDConnectorOdbcGeneric::fetch()`, results are returned as an **array of `stdClass` objects** (via `odbc_fetch_object()`). These two paths represent the same kind of data in incompatible structures. Code consuming results directly from the ED connector must handle objects, while the parser function code expects arrays.

---

### 3.4 The ED Connector Bypasses All Query Runner Features

When the ED connector routes a query through `odbc_source` mode, it gives External Data full direct access to the ODBC connection. Not only does this bypass `$wgODBCMaxRows` (now fixed in v1.0.3), it also bypasses:

- Query result caching (`$wgODBCCacheExpiry`)
- UTF-8 encoding conversion
- Query debug logging
- Statement-level timeout configuration

The two code paths provide fundamentally different quality-of-service. Using the same data source via the ED connector vs. the native parser functions gives inconsistent behaviour.

---

### 3.5 Connection Pool Eviction Is FIFO, Not LRU ✦ NEW

**File:** `includes/ODBCConnectionManager.php`, `connect()`

When the connection pool reaches the `$wgODBCMaxConnections` limit, the eviction logic is:

```php
$firstKey = array_key_first( self::$connections );
if ( $firstKey !== null ) {
    self::disconnect( $firstKey );
}
```

`array_key_first()` returns the **first key by insertion order** — First In, First Out (FIFO). This is an incorrect eviction policy for a connection pool. The oldest connection is not necessarily the least recently used. If the first connection added to the pool serves the most-queried data source, it will be repeatedly evicted to make room for new connections, while newer less-used connections are kept. Proper pool eviction should track last-access timestamps and use Least Recently Used (LRU) order.

**Impact:** Active, frequently-used connections are evicted, causing unnecessary reconnection overhead for popular sources.

**Fix:** Record the last-used timestamp for each connection alongside the connection handle. Evict the connection with the oldest last-use timestamp.

---

### 3.6 No PHP Namespaces; Legacy `AutoloadClasses` Format ✦ NEW

**Files:** All PHP files, `extension.json`

All PHP classes (`ODBCConnectionManager`, `ODBCQueryRunner`, `ODBCParserFunctions`, `ODBCHooks`, `SpecialODBCAdmin`, `EDConnectorOdbcGeneric`) are declared in the **global namespace**. Modern PHP (7.4+) and modern MediaWiki extensions use PHP namespaces (e.g., `MediaWiki\Extension\ODBC\`) to avoid naming collisions with other extensions and to enable PSR-4 autoloading.

`extension.json` uses the legacy `AutoloadClasses` format (explicit class-to-file mapping) rather than `AutoloadNamespaces` (PSR-4 autoloading by namespace prefix). This is technically functional but is the deprecated legacy approach.

**Impact:** Risk of class name collisions with other extensions using identical names (e.g., a different extension also registering a global `ODBCQueryRunner` class). No unit testing namespace isolation.

**Fix:** Introduce the `MediaWiki\Extension\ODBC\` namespace in all PHP files, switch `extension.json` to `AutoloadNamespaces`, and add `ServiceWiring.php`. This is a v2.0 breaking-change item but should be planned now.

---

### 3.7 `extension.json` `callback` Key Is Deprecated in Modern MediaWiki ✦ NEW

**File:** `extension.json`

```json
"callback": "ODBCHooks::onRegistration"
```

The `callback` key in `extension.json` was the early mechanism for running one-time setup code at extension load time. It is deprecated in modern MediaWiki releases in favor of using the `ExtensionRegistration` hook or `onRegistration` static method pattern through the hooks system. While still functional in MW 1.39+, it will eventually be removed.

**Fix:** Register setup logic using the proper `ExtensionRegistration` hook or move the External Data connector registration to `onParserFirstCallInit` with an appropriate guard.

---

### 3.8 `getMainConfig()` Called Repeatedly Inside Hot Methods

**Files:** `includes/ODBCQueryRunner.php`

`MediaWikiServices::getInstance()->getMainConfig()` is called inside `executeRawQuery()`, `executeComposed()`, and `executePrepared()` — all of which may be called multiple times per page parse. While `MediaWikiServices::getInstance()` is cheap (it returns a singleton), redundantly fetching individual config values on every call of a hot path is wasteful. These values should be cached in the constructor or in private properties.

---

### 3.9 `$where` and Query Clause Values Are Passed Raw to SQL

**File:** `includes/ODBCQueryRunner.php`, `executeComposed()`

After `sanitize()` passes, the WHERE/ORDER BY/GROUP BY/HAVING strings are directly interpolated into the SQL string with string concatenation. There is no quoting of values, no parameterization. A value like `1=1 OR 2=2` or `col > (SELECT MIN(id) FROM users)` passes all checks and executes as written. The blocklist sanitization is the only barrier. This is not meaningful parameterization.

---

### 3.10 `EDConnectorOdbcGeneric` Is Always Autoloaded Even Without External Data

**File:** `extension.json`

```json
"AutoloadClasses": {
    "EDConnectorOdbcGeneric": "includes/connectors/EDConnectorOdbcGeneric.php"
}
```

`EDConnectorOdbcGeneric` extends `EDConnectorComposed`, which only exists when the External Data extension is installed. If PHP ever tries to autoload `EDConnectorOdbcGeneric` without External Data installed, the class definition will fail with a fatal error (`Class 'EDConnectorComposed' not found`). While the class is only instantiated by External Data itself, the autoload registration in the global class map means any stray code reference could trigger the fatal error unpredictably.

---

### 3.11 No Unit Tests, No CI, No Static Analysis ✦ REINFORCED

The entire codebase has **zero test files**. For a security-sensitive extension that executes user-controlled SQL against external databases, the absence of tests is a critical quality failure. There is no:

- `phpunit.xml` test configuration
- `tests/` directory
- `require-dev` section in `composer.json` for PHPUnit or code standards tools
- `.phpcs.xml` coding standards configuration
- `.github/workflows/` CI pipeline

Without automated tests, there is no safety net for regressions. The v1.0.2 release fixed multiple CVE-level bugs that should have been caught by tests. The v1.0.3 release fixed a cache key collision and a connection liveness error — both are precisely the kind of logic bug that unit tests catch instantly. The total absence of any testing infrastructure is the single largest quality deficiency in this codebase.

---

## 4. Documentation Issues

### 4.1 SECURITY.md Security Release History Is Incomplete ✦ NEW

**File:** `SECURITY.md`, "Security Release History" section

The release history documents v1.0.0 and v1.0.1 only. Versions 1.0.2 (which contained multiple critical security fixes — XSS, wikitext injection, SQL injection via UNION, password exposure) and 1.0.3 (connection liveness, cache key, blocklist expansion) are completely absent. Security-sensitive users reviewing the document cannot see the full history of vulnerabilities and their resolutions. This is a significant omission in a security policy document.

**Fix:** Add v1.0.2 and v1.0.3 entries to the Security Release History section.

---

### 4.2 CHANGELOG v1.0.3 Is Marked "Unreleased" Despite Being the Shipped Version ✦ NEW

**File:** `CHANGELOG.md`

```markdown
## [1.0.3] - Unreleased
```

The `extension.json` `version` field is `"1.0.3"`, and KNOWN_ISSUES.md declares issues fixed "in v1.0.3". The code is the v1.0.3 release. The CHANGELOG should carry the actual release date, not "Unreleased". An "Unreleased" tag is correct in pre-release development but incorrect once the version is shipped.

**Fix:** Replace "Unreleased" with the actual release date.

---

### 4.3 README Troubleshooting References Obsolete `MAX_CONNECTIONS` Constant ✦ NEW

**File:** `README.md`, Performance Issues troubleshooting section

> "Connection pool is limited to 10; increase if needed by modifying MAX_CONNECTIONS constant"

The hard-coded `MAX_CONNECTIONS` constant was replaced in v1.0.3 by the `$wgODBCMaxConnections` configuration variable. The troubleshooting note now instructs users to edit PHP source code for something that is properly configurable via `LocalSettings.php`. This is misleading and outdated.

**Fix:** Replace with: "The connection pool limit defaults to 10; increase by setting `$wgODBCMaxConnections` in `LocalSettings.php`."

---

### 4.4 README `Complete Example` Demonstrates Insecure Configuration Without Inline Warning

**File:** `README.md`, Complete Example section

The primary code example in the documentation sets:

```php
$wgODBCAllowArbitraryQueries = true;
$wgGroupPermissions['user']['odbc-query'] = true;
```

This is the least secure possible configuration: it allows any logged-in user to run arbitrary SQL against the database. Presenting this in the main example without any inline callout normalises dangerous configuration. The security note in the Security Considerations section is many screens away and easily missed.

**Fix:** Add a prominent warning note immediately before or after the example `LocalSettings.php` block, clearly stating this is for demonstration only and explaining the security risks.

---

### 4.5 `EDConnectorOdbcGeneric.php` PHPDoc Shows Ambiguous Config Key for Database Name

**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, class-level PHPDoc

The class doc shows `'name' => 'MyDatabase'` as the External Data config key for the database name, but `setCredentials()` internally uses `$this->credentials['dbname']` (External Data's convention). The mapping between the user-facing key `name` and the internal `credentials['dbname']` key happens deep inside `EDConnectorComposed` (the parent class) and is not documented in the class header. Developers debugging connection issues may not understand why `$params['database']` in their config isn't being picked up as expected.

---

### 4.6 KNOWN_ISSUES.md Uses Mojibake Characters (Encoding Issue) ✦ NEW

**File:** `KNOWN_ISSUES.md`

The file contains garbled multi-byte character sequences throughout: `â€"` instead of `—`, `âœ…` instead of `✅`, `â€™` instead of `'`. This is a classic UTF-8/Latin-1 double-encoding artifact, where UTF-8 multi-byte sequences were saved or re-read as if they were single-byte characters. While the file remains technically readable, it looks professionally embarrassing and indicates a workflow or editor encoding configuration problem.

**Fix:** Re-save the file with correct UTF-8 encoding. All em dashes, checkmarks, and curly quotes should be proper Unicode characters, not their multi-byte escaped representations.

---

### 4.7 `wiki/Architecture.md` Contains Multiple Major Factual Errors ✅ Fixed in v1.1.0

**File:** `wiki/Architecture.md`  
**Status:** ✅ Fixed in v1.1.0 — all 5 errors corrected in wiki/Architecture.md

The Architecture page, which is the primary reference for contributors, contains at least five significant factual inaccuracies about the actual code:

**Error 1 — "All methods are static" is false for `ODBCQueryRunner`.**  
The description states "All methods are static." This is wrong. `executeComposed()`, `executePrepared()`, `executeRawQuery()`, `getTableColumns()`, `getTables()`, and `getSourceId()` are **instance methods** on an object that holds `$this->sourceId`, `$this->config`, and `$this->connection`. Only `sanitize()`, `validateIdentifier()`, and `requiresTopSyntax()` are static. Describing the class as all-static completely misrepresents its design and will cause developers to try calling instance methods statically, producing fatal PHP errors.

**Error 2 — Method signatures include a non-existent `$sourceId` parameter.**  
The "Key methods" list shows `executeComposed( $sourceId, $from, $columns, ... )` and `executePrepared( $sourceId, $queryName, ... )`. The actual signatures are `executeComposed( string $from, array $columns, ... )` and `executePrepared( string $queryName, array $parameters = [] )`. The `$sourceId` is not a parameter — it is set in the constructor (`ODBCQueryRunner::__construct( $sourceId )`). Any developer copy-pasting calls from the documentation will produce wrong-argument-count errors.

**Error 3 — `displayOdbcTable()` does not use `frame->expandTemplate()`.**  
The description says it "calls a named wiki template once per row via `frame->expandTemplate()`." This is false. The method returns wikitext template-call strings (e.g., `{{TemplateName|param=value|...}}`), which are expanded by the MediaWiki parser during the normal parse cycle. There is no call to `frame->expandTemplate()` or any equivalent in `ODBCParserFunctions::displayOdbcTable()`.

**Error 4 — LRU eviction claim is false and internally contradictory.**  
The component description says "The pool uses LRU-style eviction (last-used timestamp)." The actual code uses `array_key_first( self::$connections )` — FIFO (First In, First Out). No `$lastUsed` timestamp array exists in the class. The same document's Design Limitations table correctly lists "FIFO connection eviction" as an open bug but then adds "(recently changed to LRU per code review)," which is also wrong — the code was never changed. The document is contradictory within itself, and neither version is correct about LRU being implemented.

**Error 5 — Wrong method name `getTableList()` vs actual `getTables()`.**  
The component description lists `getTableList( $sourceId )` as a method of `ODBCQueryRunner`. The actual method is named `getTables()` (no `$sourceId` parameter — it is an instance method using `$this->connection`).

**Impact:** Developers reading Architecture.md to understand the codebase before contributing will form incorrect mental models. Errors 1 and 2 are the most damaging — they would cause fatal PHP errors in code written from this documentation. Error 4 creates false confidence that the connection pool uses proper LRU eviction.

**Fix:** Audit all method signatures, class descriptions, and design notes in `wiki/Architecture.md` against the live source code. Pay particular attention to static vs instance distinction, actual parameter lists, and the connection eviction mechanism.

---

### 4.8 `wiki/Known-Issues.md` KI-008 Description Is Inaccurate ✅ Fixed in v1.1.0

**File:** `wiki/Known-Issues.md`  
**Status:** ✅ Fixed in v1.1.0 — description updated to reflect `data=` omitted entirely

The wiki KI-008 description reads:

> "When `data=` is specified but some column mappings are omitted, the extension falls back to `SELECT *` for those columns rather than restricting the query to only the columns actually needed."

This is incorrect. KI-008 (per the canonical `KNOWN_ISSUES.md` and the source code) is triggered when the `data=` parameter is **omitted entirely**, not when some mappings within `data=` are omitted. When `data=` is present with explicit mappings, only those mapped columns are selected — there is no partial `SELECT *` fallback. The inaccurate description implies that partial `data=` specifications are unsafe, when the real issue is the complete absence of `data=`.

**Fix:** Update the KI-008 description in `wiki/Known-Issues.md` to accurately state: "`SELECT *` is issued when the `data=` parameter is omitted entirely from `{{#odbc_query:}}`."

---

### 4.9 `README.md` Magic Word Version Claim Is Inaccurate ✅ Fixed in v1.1.0

**File:** `README.md`, Troubleshooting section ("Magic words not working")  
**Status:** ✅ Fixed in v1.1.0 — updated to say "version 1.0.3+"

The troubleshooting note states:

> "After updating to version 1.0.1+, uppercase variants also work: `{{#ODBC_QUERY:}}`"

This is factually wrong. The case-insensitive magic word flag (`0`) was not correctly applied until **v1.0.3**. Versions 1.0.0–1.0.2 had the flag set to `1` (case-sensitive), which was a known bug (KI-001). Asserting that v1.0.1+ supports uppercase variants is incorrect and misleading — operators running v1.0.1 or v1.0.2 who read this note will trust that uppercase works when it silently does not. The v1.0.1 CHANGELOG entry is the source of this confusion: it says "Fixed case sensitivity in magic words (changed from 0 to 1)" which describes the exact opposite of the correct fix (the fix should have been 1→0, not 0→1).

**Fix:** Change "After updating to version 1.0.1+, uppercase variants also work" to "After updating to **version 1.0.3+**, uppercase variants also work."

---

### 4.10 `KNOWN_ISSUES.md` Had a Garbled Duplicate Footer Line ✅ Fixed in prior session

**File:** `KNOWN_ISSUES.md`, final lines  
**Status:** ✅ Fixed (P2-032)

The file ends with two conflicting footer statements printed back-to-back:

```
*Last updated: v1.0.3 re-review (2026-03-02) — 18 issues resolved; 13 open (KI-008, KI-018, KI-019, KI-020, KI-023 through KI-031).*
 — 18 issues resolved; 4 open (KI-008, KI-018, KI-019, KI-020).*
```

The second fragment (beginning with ` — 18 issues resolved; 4 open`) is the remnant of an earlier footer that was not fully removed when the document was updated to the 13-open-issue count. The two lines disagree on the number of open issues (13 vs 4), and the orphaned fragment is syntactically invalid markdown (a stray ` — ` floating in the document). This creates confusion about the actual issue count and looks unprofessional.

**Fix:** Remove the orphaned second footer fragment. Ensure the first footer line accurately reflects the current open issue count after all new issues are added.

---

### 4.11 `UPGRADE.md` Uses Non-Standard `$GLOBALS` Notation ✅ Fixed in v1.1.0

**File:** `UPGRADE.md`, v1.0.3 upgrade section  
**Status:** ✅ Fixed in v1.1.0 — changed to `$wgODBCMaxConnections = 10;`

The new configuration key example in the v1.0.3 upgrade section reads:

```php
$GLOBALS['wgODBCMaxConnections'] = 10;
```

The standard MediaWiki convention for `LocalSettings.php` configuration is:

```php
$wgODBCMaxConnections = 10;
```

Using `$GLOBALS[...]` is redundant, more verbose, and inconsistent with every other configuration example in this extension's own documentation and with standard MediaWiki practice. All other configuration examples in the README, SECURITY.md, and UPGRADE.md itself use the `$wg*` direct form. A user following the UPGRADE.md example may unnecessarily adopt the non-standard `$GLOBALS` form as a template for all their configuration.

**Fix:** Change `$GLOBALS['wgODBCMaxConnections'] = 10;` to `$wgODBCMaxConnections = 10;`.

---

### 4.12 `README.md` Performance Troubleshooting Still References `MAX_CONNECTIONS` Constant ✦ NEW (v1.1.0 re-review)

**File:** `README.md`, Performance Issues troubleshooting section  
**Status:** ✅ Fixed in v1.1.0 (P2-027 — README.md updated; `MAX_CONNECTIONS` no longer present in the file)

The troubleshooting entry for performance issues still reads:

> "**Check connection pooling**: Connection pool is limited to 10; increase if needed by modifying **MAX_CONNECTIONS constant**"

The hard-coded `MAX_CONNECTIONS` constant was replaced in v1.0.3 by the `$wgODBCMaxConnections` configuration variable. Improvement plan item P2-027 was marked as "✅ Done" but the README file was not actually updated. This is a documentation tracking error — the plan says fixed, the file says otherwise.

**Impact:** Operators reading the troubleshooting section receive incorrect instructions to edit PHP source code for something that is properly configurable via `LocalSettings.php`.

**Fix:** Replace with: "Connection pool defaults to 10 simultaneous connections. Increase by setting `$wgODBCMaxConnections` in `LocalSettings.php`."

---

### 4.13 `CHANGELOG.md` v1.1.0 Marked "Unreleased" Despite Being the Shipped Version ✦ NEW (v1.1.0 re-review)

**File:** `CHANGELOG.md`  
**Status:** ✅ Fixed in v1.1.0 (P2-036)

The CHANGELOG entry for v1.1.0 reads `## [1.1.0] - Unreleased`. However, `extension.json` declares `"version": "1.1.0"`, and this codebase IS the v1.1.0 release. The same issue occurred for v1.0.3 (KI-030) and was fixed for that entry. The pattern has recurred for v1.1.0.

**Fix:** Replace "Unreleased" with the actual release date.

---

### 4.14 `wiki/Architecture.md` — `buildConnectionString()` Described as Not Handling Mode 1 or Mode 3 ✦ NEW (v1.1.0 re-review)

**File:** `wiki/Architecture.md`, `ODBCConnectionManager` component section  
**Status:** ✅ Fixed in v1.1.0 (P2-037)

The Architecture page documents `buildConnectionString()` as:

> "Does not handle Mode 1 (DSN) or Mode 3 (full string)."

This is factually wrong. The actual implementation handles **all three modes**:

```php
// Mode 3 — full connection string returned as-is:
if ( !empty( $config['connection_string'] ) ) { return $config['connection_string']; }

// Mode 1 — simple DSN name returned as-is:
if ( !empty( $config['dsn'] ) && empty( $config['driver'] ) ) { return $config['dsn']; }

// Mode 2 — driver/server/database construction (the default):
// ... builds parts[] array
```

A developer reading the Architecture docs will incorrectly believe only driver-based configs go through this method, when in fact all three configuration modes are centralised here.

**Fix:** Update the description to: "Handles all three connection modes: a full `connection_string` (returned as-is), a simple DSN name (returned as-is), and driver/server/database-style construction. Values in driver mode are escaped per the ODBC specification."

---

### 4.15 `wiki/Security.md` — KI-024 Note Still Present After Fix ✦ NEW (v1.1.0 re-review)

**File:** `wiki/Security.md`, SQL injection protection section  
**Status:** ✅ Fixed in v1.1.0 (P2-038)

The SQL injection section includes a callout:

> "**Known issue (KI-024):** `UNION` is matched as a substring — identifiers like `LABOUR_UNION` or `TRADE_UNION_TYPE` are incorrectly blocked. See [[Known-Issues#ki-024]]."

KI-024 was fixed in v1.1.0: `UNION` was moved from the substring `$charPatterns` list to the word-boundary `$keywords` regex list. The note remains, falsely implying the issue is still active. Editors reading this document will avoid `UNION`-containing identifiers unnecessarily, or add workarounds for a problem that no longer exists.

**Fix:** Update the note to reflect the fix: "~~KI-024 — fixed in v1.1.0~~: `UNION` now uses word-boundary matching; identifiers like `TRADE_UNION_ID` are no longer blocked."

---

### 4.16 `SECURITY.md` Known Limitations Section Is Outdated ✦ NEW (v1.1.0 re-review)

**File:** `SECURITY.md`, Known Limitations section  
**Status:** ✅ Fixed in v1.1.0 (P2-039)

The Known Limitations section states:

> "LIMIT syntax handling tries both TOP (SQL Server) and LIMIT (MySQL/PostgreSQL)"

This is factually wrong and was corrected in v1.0.2. Emitting both `TOP` and `LIMIT` in the same query was the pre-v1.0.2 bug. The current behaviour (since v1.0.2, extended in v1.1.0) is driver-aware selection: `TOP n` for SQL Server/Access/Sybase, `FIRST n` for Progress OpenEdge, `LIMIT n` for all others. It no longer "tries both."

Additionally, the section makes no mention of Progress OpenEdge (`FIRST n` syntax), which was added in v1.1.0.

**Fix:** Update to: "Row-limit syntax is automatically selected based on the ODBC driver: `TOP n` for SQL Server, Access, and Sybase; `FIRST n` for Progress OpenEdge; `LIMIT n` for all others. For System DSN configurations where no `driver` key is specified, `LIMIT n` is used by default."

---

### 4.17 `UPGRADE.md` v1.0.1 Section Falsely Claims Magic Word Case Fix ✦ NEW (v1.1.0 re-review)

**File:** `UPGRADE.md`, "Upgrading to 1.0.1 from 1.0.0" section  
**Status:** ✅ Fixed in v1.1.0 (P2-040)

The v1.0.1 upgrade section includes:

> "Magic Word Case Sensitivity Fixed — `{{#ODBC_QUERY:}}` now works (previously only lowercase worked). **Action**: No action required, both cases now work"

This is factually incorrect. In v1.0.1, the magic word flag was changed from `0` (case-insensitive) to `1` (case-sensitive), which made the behaviour **worse** — uppercase variants stopped working entirely. The actual fix — restoring flags to `0` — was released in **v1.0.3** (KI-001). An operator reading this note on v1.0.1 or v1.0.2 will incorrectly believe uppercase variants work when they do not.

**Fix:** Either remove the entry entirely (since it documented a regression, not a fix), or correct it to: "v1.0.1 incorrectly changed magic word flags from `0` to `1`, inadvertently making case sensitivity *more* restrictive. This was corrected in v1.0.3 — uppercase variants only work from **v1.0.3** onwards."

---

### 4.18 `wiki/Parser-Functions.md` — `data=` Marked as Required When It Is Optional ✦ NEW (v1.1.0 re-review)

**File:** `wiki/Parser-Functions.md`, `{{#odbc_query:}}` Parameters table  
**Status:** ✅ Fixed in v1.1.0 (P2-041)

The parameters table for `{{#odbc_query:}}` lists `data=` with `Required: Yes` for both prepared and composed modes. This is incorrect: `data=` is **optional**. When omitted, the extension issues `SELECT *` and stores all returned columns using lowercase names.

Marking `data=` as required creates two problems:
1. Editors who trust the documentation believe the function will fail or produce an error without `data=`.
2. Editors are not warned that omitting `data=` will silently fetch all columns — potentially including sensitive ones — from the table.

**Fix:** Change the `Required` column for `data=` from `Yes` to `No` and add a note: "If omitted, `SELECT *` is issued and all columns are stored under their lowercase names. This may expose sensitive columns unintentionally — see KI-008."

---

### 4.19 `odbc-error-too-many-queries` i18n Message Recommends Ineffective Workaround ✦ NEW (v1.1.0 final pass)

**File:** `i18n/en.json`, `includes/ODBCParserFunctions.php`  
**Status:** ✅ Fixed in v1.2.0 (P2-047) — `{{#odbc_clear:}}` recommendation removed; message now correctly directs editors to reduce `{{#odbc_query:}}` calls or raise `$wgODBCMaxQueriesPerPage`.

The localised error message shown when a page exceeds `$wgODBCMaxQueriesPerPage` reads:

```
ODBC error: Per-page query limit reached ($1 queries allowed). Use {{#odbc_clear:}} to separate
logical sections, reduce the number of {{#odbc_query:}} calls, or raise $wgODBCMaxQueriesPerPage
in LocalSettings.php.
```

The advice to "Use `{{#odbc_clear:}}`" is **incorrect**. `odbcClear()` only clears the data storage key (`ODBCData` / `PARSER_OUTPUT_KEY`). The per-page query counter is stored separately under `PARSER_OUTPUT_QUERY_COUNT_KEY` and is **never reset** by `odbcClear()` — it increments monotonically for the lifetime of the page parse. A wiki editor who follows this advice will still hit the limit on every subsequent `{{#odbc_query:}}` call.

**Impact:** Editors acting on the advice perform a useless `{{#odbc_clear:}}` call, conclude the feature is broken, and either leave it broken or incorrectly raise `$wgODBCMaxQueriesPerPage` higher than necessary.

**Fix:** Remove the `{{#odbc_clear:}}` recommendation from the message. Replace with accurate guidance: the limit applies across the whole page render and cannot be bypassed by clearing data — only by reducing the number of `{{#odbc_query:}}` calls or raising the limit. The note about raising `$wgODBCMaxQueriesPerPage` is accurate and should be retained.

---

### 4.20 `wiki/Architecture.md` — Four Stale Descriptions Not Updated When P2-024 Was Implemented ✦ NEW (v1.1.0 final pass)

**File:** `wiki/Architecture.md`  
**Status:** ✅ Fixed in v1.2.0 (P2-048) — all four locations corrected: FIFO→LRU in two places, Design Limitations table updated to show P2-024 Done, caching section corrected to `ObjectCache::getLocalClusterInstance()`.

P2-024 (LRU connection pool eviction) was marked Done in v1.1.0. The code correctly uses `$lastUsed` timestamps with `asort()` to evict the least-recently-used connection. However, four places in `wiki/Architecture.md` were not updated alongside the code change:

1. **`connect()` description (line 67):** "Evicts the oldest connection **(FIFO)** when the pool is full." — Should say LRU, not FIFO.
2. **Connection Pool subsection (line 75):** "The pool uses **FIFO eviction (`array_key_first()`)** when `$wgODBCMaxConnections` is exceeded." — FIFO is wrong; `array_key_first()` applied after `asort($lastUsed)` gives the LRU entry, not necessarily the oldest-inserted.
3. **Design Limitations table (line 197):** `"FIFO connection eviction | Oldest entries evicted even if recently active; LRU planned | P2-024"` — This row should be **removed** or updated to show "✅ Fixed in v1.1.0" because P2-024 is complete.
4. **Caching section (line 229):** `"Query result caching uses MediaWiki's WANObjectCache (from MediaWikiServices::getInstance()->getMainWANObjectCache())."` — The code at `ODBCQueryRunner.php` line 206 actually uses `ObjectCache::getLocalClusterInstance()`. `WANObjectCache` and `getLocalClusterInstance()` are fundamentally different: WANObjectCache provides cross-datacenter consistency and stale-while-revalidate semantics; `getLocalClusterInstance()` is the local cluster cache (typically APCu or a local pool). The distinction matters for multi-server deployments.

**Fix:**
- Update `connect()` description: "Evicts the least-recently-used connection (LRU) when the pool is full."
- Update connection pool description: "The pool uses LRU eviction: the connection with the oldest `$lastUsed` timestamp is evicted per `asort($lastUsed)` + `array_key_first()` when `$wgODBCMaxConnections` is exceeded."
- Remove the FIFO limitation row from the Design Limitations table (or add a P2-024 ✅ Done annotation).
- Correct the caching sentence: "Query result caching uses `ObjectCache::getLocalClusterInstance()` (the local cluster cache — typically APCu in production). This is a node-local cache; results are **not** shared across multiple app servers."

---

### 4.21 `wiki/Known-Issues.md` — KI-020 Status Not Updated to Reflect Partial Fix ✦ NEW (v1.1.0 final pass)

**File:** `wiki/Known-Issues.md`  
**Status:** ✅ Fixed in v1.2.0 (P2-049) — KI-020 updated with "⚠ Partially fixed in v1.1.0" and mode-by-mode breakdown.

KI-020 ("External Data Connector Has No Caching or UTF-8 Conversion") is listed in the wiki as a fully open issue with "Planned fix: v1.1.0 (P2-016)". The improvement plan marks P2-016 as `✅ Partial`.

The actual state is:
- **`odbc_source` mode** (where the ED config references an entry in `$wgODBCSources` via `odbc_source=`): results now route through `ODBCQueryRunner::executeRawQuery()`, which **does** apply `$wgODBCCacheExpiry` caching and per-cell UTF-8 conversion. ✅ Fixed.
- **Standalone mode** (where the ED config specifies its own `driver`/`host`/`database`: results are fetched directly via `odbc_fetch_object()` without caching or encoding conversion. ✗ Still open.

The wiki page should reflect this distinction clearly so users know which mode has which capabilities.

**Fix:** Update KI-020 status to "⚠ Partially fixed in v1.1.0": describe `odbc_source` mode as now supporting caching and UTF-8 conversion; describe standalone mode as still lacking both.

---

### 4.22 `$wgODBCMaxConnections` Described as "Per Source" in Five Locations ✦ NEW (v1.1.0 final pass)

**Status:** ✅ Fixed in v1.2.0 (P2-050) — all six instances corrected to "across all sources combined" across `extension.json`, `README.md` (×2), `CHANGELOG.md`, `UPGRADE.md`, `SECURITY.md`.  
**Files:** `extension.json`, `README.md` (2×), `CHANGELOG.md`, `UPGRADE.md`, `SECURITY.md`

The description `"maximum simultaneous ODBC connections per source"` (or equivalent wording with "per source") appears in six places across five files. It is **incorrect**: `$wgODBCMaxConnections` is a global pool limit shared across **all** configured sources. A site with three sources and `$wgODBCMaxConnections = 10` has a maximum of 10 ODBC handles total — not 10 per source. Once 10 handles are open (across any mix of sources), the LRU-eviction path kicks in.

The "per source" description causes operator confusion in two directions:
1. Operators with many sources may set the value too low, expecting each source to have its own pool.
2. Operators with one source assume the limit only applies to that source and may unknowingly allow 10× more handles than intended if a second source is added.

**Instances to correct:**
| File | Line | Current text |
|------|------|--------------|
| `extension.json` | 54 | `"Maximum number of simultaneous ODBC connections per source."` |
| `README.md` | 471 | `"Maximum of $wgODBCMaxConnections (default: 10) cached connections per source"` |
| `README.md` | 520 | `"connection pool defaults to 10 simultaneous connections per source"` |
| `CHANGELOG.md` | 75 | `"maximum simultaneous connections per source"` |
| `UPGRADE.md` | 69 | `"maximum number of simultaneous ODBC connections per source"` |
| `SECURITY.md` | 58 | `"Maximum $wgODBCMaxConnections (default: 10) cached connections per source"` |

**Fix:** Replace "per source" with "across all sources combined" in every instance.

---

## 5. Code Quality and Style

### 5.1 Inline Error Handler Closures Duplicated Across Files ✅ Completed in v1.2.0 (P2-051)

**Files:** `ODBCConnectionManager.php`, `ODBCQueryRunner.php`, `EDConnectorOdbcGeneric.php`

`ODBCConnectionManager::withOdbcWarnings()` has been promoted to `public static`. All five raw `set_error_handler` closures that remained after P2-008 have been replaced with calls to the shared helper:
- `ODBCQueryRunner::executeRawQuery()` — closure with `use(&$stmt, $sql, $params, ...)`
- `ODBCQueryRunner::getTableColumns()` — closure with `use($tableName)`
- `ODBCQueryRunner::getTables()` — closure (captures `$this` implicitly)
- `EDConnectorOdbcGeneric::connect()` — arrow function `fn() => odbc_connect(...)`
- `EDConnectorOdbcGeneric::fetch()` — arrow function `fn() => odbc_exec(...)`

P2-008 is now fully complete.

---

### 5.2 `'noparse' => true` Returns Are Inconsistent ✅ Fixed in v1.2.0 (P2-052)

**File:** `includes/ODBCParserFunctions.php`

`odbcQuery()` returns `[ '', 'noparse' => true ]` on success and previously returned `[ self::formatError(...), 'noparse' => false ]` on error. `formatError()` produces raw HTML (`<span class="error odbc-error">…</span>`), which must be returned with `'noparse' => true, 'isHTML' => true` to prevent the wikitext parser from re-processing the HTML attributes.

**Fixed:** All five error returns in `odbcQuery()` now use `[ self::formatError(...), 'noparse' => true, 'isHTML' => true ]`. The method docblock was updated accordingly. `forOdbcTable()` is unchanged (it returns rendered wikitext with `'noparse' => false`, which is correct for that method's output type).

---

### 5.3 `$params['source'] ?? ( $params[0] ?? '' )` Positional Fallback Is Undocumented

**File:** `includes/ODBCParserFunctions.php`

The first positional argument is accepted as the source ID: `{{#odbc_query: mydb | from=table }}` is valid but this is undocumented in the README or any PHPDoc. Undocumented features are bugs waiting to happen.

---

### 5.4 Log Messages Use Inconsistent Separator Characters

**File:** `includes/ODBCQueryRunner.php`

Some log messages use `—` (em dash) as a separator, others use `:`. Log formatting should be consistent to aid grep-based log parsing and filtering.

---

### 5.5 `Html::textarea()` Uses Deprecated `cols` Attribute

**File:** `includes/specials/SpecialODBCAdmin.php`

```php
$html .= Html::textarea( 'sql', '', [
    'rows' => 6,
    'cols' => 80,
```

`cols` is a deprecated presentation attribute in HTML5. The width should be controlled via CSS.

---

### 5.6 Silent Truncation of `data=` Mappings Exceeding 256 Characters ✦ NEW

**File:** `includes/ODBCParserFunctions.php`, `parseDataMappings()`

```php
if ( strlen( $pair ) > 256 ) {
    continue;  // silently drop
}
```

Individual data mappings longer than 256 characters are silently skipped. The wiki editor receives no error, warning, or indication that their `data=` parameter was partially ignored. The result is silent data loss — some variables simply are not populated, which can produce misleading output without any diagnostic.

**Fix:** Log a debug message and return a user-visible error (or at minimum a warning alongside the partial result) when mappings are silently dropped.

---

### 5.7 `odbc_setoption()` Timeout Call Uses `@` Suppression Without Fallback Logging ✦ NEW ✅ Fixed in v1.1.0 (P2-023)

**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`  
**Status:** ✅ Fixed in v1.1.0 (P2-023 — return value checked; `wfDebugLog()` entry written when driver does not support per-statement timeout)

```php
@odbc_setoption( $stmt, self::SQL_HANDLE_STMT, self::SQL_QUERY_TIMEOUT, $timeout );
```

The `@` suppression operator hides any PHP warning. The `@` is still used to prevent the outer `set_error_handler` from converting the PHP warning to an `MWException`, but the return value is now checked and a `wfDebugLog('odbc', ...)` entry is written when `$timeoutSet === false`. Operators can determine whether the driver supports per-statement timeout by checking the ODBC debug log.

~~**Fix:** Remove `@`, wrap in `set_error_handler`, and add a `wfDebugLog()` entry when the call returns `false`.~~ Applied in v1.1.0.

---

## 6. Missing Features / Incomplete Implementations

### 6.1 No Rate Limiting on `{{#odbc_query:}}`

A page with many template inclusions could trigger dozens or hundreds of ODBC queries per page view. There is no per-page or per-request limit on the number of queries that can execute. This can be weaponized for database denial-of-service.

---

### 6.2 No Mechanism to Access Specific Row Values from `{{#odbc_value:}}`

`{{#odbc_value:varName}}` always returns the first row. There is no `{{#odbc_value:varName|row=3}}` or equivalent. This is a genuine functional limitation for use cases where single-row access to non-first rows is needed without using a loop.

---

### 6.3 No Connection Test for External Data Sources

`Special:ODBCAdmin` tests sources from `$wgODBCSources` only. If using the ED connector path (sources in `$wgExternalDataSources`), there is no admin UI to test those connections.

---

### 6.4 `EDConnectorOdbcGeneric` Does Not Apply `$wgODBCCacheExpiry` or UTF-8 Conversion

Result caching and automatic UTF-8 encoding conversion are absent from the ED connector code path. Queries via External Data always hit the database and may return results in non-UTF-8 encodings. This creates an inconsistency between the two code paths for the same ODBC source.

---

### 6.5 No `composer.json` `require-dev` for Testing Tools

**File:** `composer.json`

There is no `require-dev` section defining PHPUnit, MediaWiki's test utilities, or coding standards tools (PHP_CodeSniffer). This is standard practice for MediaWiki extensions — it enables `composer install --dev` to set up a test environment. Without it, there is no defined way to run tests or check coding standards.

---

### 6.6 No Slow Query Logging ✦ NEW

**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`

There is no timing or slow query log. All executed queries are logged at the debug level with SQL and row count, but execution time is not recorded. For performance monitoring, it would be valuable to log queries that exceed a configurable threshold (e.g., > 5 seconds). Without slow query logging, performance problems in production are difficult to identify from logs alone.

---

## 7. Summary Scorecard

| Category | Count (v1.2.0 pass) | Notes |
|----------|--------------------------|-------|
| Bugs | **1 open** (KI-008 — SELECT * logged but still issued) | KI-019 fixed (row= param); KI-050 fixed |
| Security Issues | 4 structural | 1 Structural (blocklist-only defence), 2 Moderate (admin bypass, no parameterised WHERE), 1 Minor |
| Design Problems | 11 | 3 Major (no tests, no namespace, static design), 5 Moderate, 3 Minor |
| Documentation Errors | **~2 open** (KI-020 partial still open for standalone mode; KI-051/052/053 all fixed) | |
| Code Quality Issues | **6** (P2-051 complete — all raw error-handler closures replaced; §5.1 resolved) | Style/maintenance |
| Missing Features | **4** (KI-018 per-page limit addressed; KI-019 row= param added) | Functional gaps |

**Previously open issues now confirmed fixed (v1.1.0 final pass):**
- **KI-031** (README MAX_CONNECTIONS constant): confirmed fixed — `MAX_CONNECTIONS` string no longer present in README.md
- **KI-035** (Architecture.md 5 factual errors): P2-029 Done — ODBCQueryRunner instance/static, method signatures, expandTemplate description, and method name all corrected. New doc errors introduced by P2-024 not being fully reflected (now tracked as KI-051)
- **KI-036** (wiki/Known-Issues.md KI-008 description): confirmed fixed — wiki description now correctly describes the `data=` omitted-entirely case
- **KI-037** (README magic word version claim): P2-031 Done — updated to say "v1.0.3+"
- **KI-040** (`validateConfig()` Progress host key): confirmed fixed — `empty($config['host'])` present at `ODBCConnectionManager.php` line 364
- **KI-041–KI-048** (documentation errors from v1.1.0 re-review): all confirmed fixed per improvement_plan P2-036/037/038/039/040/041/042/043/044 Done markers and file content verification

**New findings (v1.1.0 final pass, 2026-03-05):**
- **1 user-facing bug (KI-050):** `odbc-error-too-many-queries` i18n message recommends `{{#odbc_clear:}}` as a workaround for the per-page query limit, but `odbcClear()` only resets data storage (`ODBCData`) — it never resets the query counter (`ODBCQueryCount`). The advice is actively incorrect.
- **1 documentation issue (KI-051):** `wiki/Architecture.md` was not updated when P2-024 (LRU eviction) was implemented. Four places still say "FIFO" or describe WANObjectCache when the code uses `ObjectCache::getLocalClusterInstance()`.
- **1 documentation issue (KI-052):** `wiki/Known-Issues.md` KI-020 still shows as fully open ("Planned fix: v1.1.0") when P2-016 was partially applied — `odbc_source` mode now caches and converts encoding, standalone mode still does not.
- **1 documentation issue (KI-053):** `$wgODBCMaxConnections` is described as "per source" in six places across five files (`extension.json`, `README.md` ×2, `CHANGELOG.md`, `UPGRADE.md`, `SECURITY.md`). It is a global pool limit shared across all sources combined.
- **1 code quality note (§5.1 update):** `withOdbcWarnings()` was added (P2-008) but declared `private static`, making it impossible to use outside `ODBCConnectionManager`. Five raw `set_error_handler` closures remain in `ODBCQueryRunner.php` and `EDConnectorOdbcGeneric.php`.

**Cumulative fixed total (all versions):** 51 issues resolved across v1.0.1 through v1.2.0.

**New fixes and improvements (v1.2.0 pass, 2026-03-06):**
- **KI-019 fixed:** `{{#odbc_value:}}` now accepts a row selector parameter (`2`, `last`, `row=N`, `row=last`). Out-of-range → silent default fallback.
- **KI-050 fixed (P2-047):** `odbc-error-too-many-queries` i18n message corrected; `{{#odbc_clear:}}` advice removed.
- **KI-051 fixed (P2-048):** `wiki/Architecture.md` all four FIFO/LRU/WANObjectCache errors corrected.
- **KI-052 fixed (P2-049):** `wiki/Known-Issues.md` KI-020 updated to reflect partial v1.1.0 fix.
- **KI-053 fixed (P2-050):** `$wgODBCMaxConnections` "per source" corrected in all six locations.
- **P2-051 complete:** `withOdbcWarnings()` made `public static`; all five raw closures replaced.
- **KI-008 partially addressed:** `wfDebugLog('odbc', ...)` warning now emitted when `SELECT *` is issued via omitted `data=`.
- **wiki/Parser-Functions.md updated:** `{{#odbc_value:}}` section rewritten to document the new row selector parameter.

**Overall Assessment (v1.2.0 pass, 2026-03-06):** All P2-047 through P2-051 items are complete. The extension is now in a significantly cleaner state: the `withOdbcWarnings()` DRY refactor is fully applied across all three PHP files; all documentation inaccuracies from the v1.1.0 final pass review are corrected; the `{{#odbc_value:}}` parser function now supports indexing into multi-row results; and operators gain visibility into accidental `SELECT *` queries via the `odbc` debug log channel. The remaining open items are the same long-standing architectural gaps (no test suite, no PHP namespaces, static service design, no CI pipeline, and no parameterised WHERE clause support) — all of which are scoped to v2.0.0. For a MediaWiki extension of this scope, the codebase is now in a solid, production-ready state.
