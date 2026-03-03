# Codebase Review: MediaWiki ODBC Extension

**Review Date:** 2026-03-03 (initial); updated 2026-03-03 (v1.1.0 re-review); updated 2026-03-05 (v1.1.0 final pass); updated 2026-03-06 (v1.2.0 pass); updated 2026-03-08 (v1.4.0 pass); updated 2026-03-08 (v1.5.0 implementation pass — all P2-063–P2-071 confirmed fixed); updated 2026-03-03 (v1.5.0 review pass — 15 new issues KI-071 through KI-085 identified and resolved); updated 2026-03-09 (v1.5.x post-release review pass — 8 new issues KI-086 through KI-093 identified); updated 2026-03-03 (v1.5.0 implementation pass — all 8 KI-086–KI-093 resolved)  
**Extension Version:** 1.5.0 (unreleased)  
**Reviewer:** GitHub Copilot (automated critical review)

---

## Executive Summary

The ODBC extension is an ambitious and largely functional MediaWiki extension that covers connection management, SQL query execution, parser functions, an admin special page, and integration with the External Data extension. A significant number of bugs, security vulnerabilities, and documentation errors identified in earlier reviews were addressed in v1.0.2 through v1.5.0. The extension has undergone eight review passes since initial release, each finding and fixing a meaningful set of issues.

Of the original 15 most critical issues, all have now been resolved. Subsequent review passes (v1.1.0 through v1.5.0) resolved a further 68 issues (including 13 documentation fixes from the v1.5.0 review pass), bringing the total fixed count to 83. Two issues remain partially or fully open by design: KI-008 (SELECT * exposure without data= mapping — cannot be fully fixed without breaking backward compatibility) and KI-020 (ED connector caching and per-row encoding are partial). The codebase is now in a substantially better state, and still carries **structural weaknesses** and **absent quality infrastructure** that represent the primary remaining debt.

This document reflects the v1.5.0 state. The v1.5.0 implementation pass (2026-03-08) confirmed all P2-063 through P2-071 items implemented. The v1.5.0 review pass (2026-03-03) identified 15 new issues (KI-071 through KI-085), primarily documentation accuracy gaps in wiki pages plus three PHP code issues. All 15 were subsequently fixed in the same development cycle: PHP fixes landed in `ODBCQueryRunner.php`, `EDConnectorOdbcGeneric.php`, and `extension.json`; documentation fixes updated `UPGRADE.md`, `SECURITY.md`, and all five affected wiki pages. A GitHub Actions CI pipeline (lint + phpcs + release-readiness checks) was also added. A further v1.5.x post-release review pass (2026-03-09) identified 8 additional issues (KI-086 through KI-093), comprising two stale wiki documentation errors, two PHP code quality gaps (sanitiser blocklist incompleteness, ODBC warning filter coverage), one parser function design inconsistency, two DevOps/tooling issues, and one documentation consistency error. These 8 issues are currently open. Findings from prior reviews that have since been fixed are noted as resolved inline.

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
| 4.19 | `odbc-error-too-many-queries` i18n message recommended `{{#odbc_clear:}}` which cannot reset the query counter | v1.2.0 |
| 4.20 | `wiki/Architecture.md` — 4 stale FIFO/WANObjectCache descriptions not updated after LRU eviction shipped | v1.2.0 |
| 4.21 | `wiki/Known-Issues.md` KI-020 status not updated for partial P2-016 fix | v1.2.0 |
| 4.22 | `$wgODBCMaxConnections` described as "per source" in six locations; corrected to "across all sources combined" | v1.2.0 |
| 5.1 | Raw `set_error_handler` closures duplicated in five places; `withOdbcWarnings()` promoted to `public static` | v1.2.0 |
| 5.2 | `odbcQuery()` error returns used `'noparse' => false` with raw HTML; corrected to `'noparse' => true, 'isHTML' => true` | v1.2.0 |
| 6.6 | No slow-query logging; `$wgODBCSlowQueryThreshold` config key and `odbc-slow` log channel added | v1.2.0 |
| 2.2 | Admin `runTestQuery()` bypassed `$wgODBCAllowArbitraryQueries` check; now enforced consistently | v1.3.0 |
| 3.7 | `extension.json` `callback` key deprecated; replaced with `ExtensionRegistration` hook | v1.3.0 |
| 3.8 | `getMainConfig()` called three times independently in ODBCQueryRunner; cached in constructor | v1.3.0 |
| 5.5 | `Html::textarea()` `cols` attribute is HTML5-deprecated; replaced with CSS `width` style | v1.3.0 |
| 5.6 | `parseDataMappings()` silently dropped overlong pairs with no diagnostic; now logs via `wfDebugLog()` | v1.3.0 |
| 3.10 | `EDConnectorOdbcGeneric` autoloaded without `class_exists('EDConnectorComposed')` guard; early-return guard added | v1.4.0 |
| 5.3 | Positional `source=` argument (`{{#odbc_query: mydb \| ...}}`) was undocumented; now documented in code and README | v1.4.0 |
| 5.4 | Log message prefix format was inconsistent (`[{$sourceId}]` vs `on source '{$sourceId}'`); standardised | v1.4.0 |
| 6.5 | No `require-dev` or `.phpcs.xml`; `phpunit/phpunit` and `mediawiki-codesniffer` added; `composer test` script defined | v1.4.0 |
| 1.10 | `executeComposed()` accepted `having=` without `group by=`; guard added with new `odbc-error-having-without-groupby` i18n message | v1.5.0 |
| 2.5 | `validateIdentifier()` regex `/^[a-zA-Z_][a-zA-Z0-9_\.]*$/` accepted trailing dots and unlimited depth; tightened to enforce 1-3 dot-segments | v1.5.0 |
| 2.6 | `withOdbcWarnings()` captured all PHP `E_WARNING`; now checks `stripos($errstr, 'odbc')` origin before throwing | v1.5.0 |
| 2.7 | ED connector `from()` built `TABLE AS alias` SQL fragments without validating `$alias`; `validateIdentifier()` call added | v1.5.0 |
| 3.14 | `NULL` values silently cast to `''`; `null_value=` parameter added to `{{#odbc_query:}}`; `mergeResults()` updated | v1.5.0 |
| 5.8 | `mb_detect_encoding()` called once per cell (O(rows × columns)); changed to once per result set; `charset=` source key added | v1.5.0 |
| 5.9 | `forOdbcTable()` called `str_replace()` O(variables) times per row; replaced with single `strtr($template, $map)` per row | v1.5.0 |
| 4.23 | `CHANGELOG.md` v1.4.0 `[Unreleased]` tag; dated; CI check added | v1.5.0 |
| KI-071 | `wiki/Architecture.md` `ODBCHooks` description still referenced deprecated `callback` key; updated to `ExtensionRegistration` hook | v1.5.0 |
| KI-072 | `extension.json` `ODBCSources` config description cited non-existent `options` key; replaced with accurate exhaustive key list | v1.5.0 |
| KI-073 | `executeRawQuery()` slow-query timer `$queryStart` placed after `odbc_execute()` — measured fetch time only; moved before execute | v1.5.0 |
| KI-074 | ED connector standalone `fetch()` used `odbc_exec()` directly, bypassing per-statement timeout; replaced with `odbc_prepare()` + `odbc_setoption()` + `odbc_execute()` | v1.5.0 |
| KI-075 | `requiresTopSyntax()` lacked `wfDeprecated()` call; added `wfDeprecated( __METHOD__, '1.1.0', 'ODBC' )` | v1.5.0 |
| KI-076 | `UPGRADE.md` had no v1.5.0 section; full upgrade section added documenting all operator-visible changes | v1.5.0 |
| KI-077 | `SECURITY.md` v1.5.0 entry showed `(Unreleased)`; replaced with `(2026-03-03)` | v1.5.0 |
| KI-078 | `wiki/Architecture.md` Design Limitations table had 4 stale strikethrough rows for already-resolved issues; all removed | v1.5.0 |
| KI-079 | `wiki/Known-Issues.md` was frozen at v1.1.0 with KI-019 shown as open (fixed v1.2.0); full rewrite to v1.5.0 current state | v1.5.0 |
| KI-080 | `wiki/Security.md` Security Release History table ended at v1.1.0 and had a double-pipe formatting bug; fixed + v1.2.0–v1.5.0 rows added | v1.5.0 |
| KI-081 | `wiki/Parser-Functions.md` missing `null_value=` parameter row; added with default, example, and `{{#if:}}` note | v1.5.0 |
| KI-082 | `wiki/Configuration.md` Connection Options table missing `charset=` row; added with description and example | v1.5.0 |
| KI-083 | `wiki/Configuration.md` Connection Options table missing `host` and `db` (Progress OpenEdge) rows; added with cross-reference | v1.5.0 |
| KI-084 | `wiki/Parser-Functions.md` used `{{# ...}}` as an inline comment (invalid wiki syntax); replaced with `<!-- ... -->` | v1.5.0 |
| KI-085 | `wiki/Security.md` Known Limitations table contained resolved KI-033 row and lacked current limitations; restructured | v1.5.0 |

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

### 1.10 `executeComposed()` Accepts `having=` Without Requiring `group by=` ✦ NEW (v1.4.0 pass)

**File:** `includes/ODBCQueryRunner.php`, `executeComposed()`

The method accepts both `$having` and `$groupBy` parameters independently. When `having=` is specified without `group by=`, the resulting SQL contains a `HAVING` clause with no preceding `GROUP BY`:

```sql
SELECT col1 FROM table1 HAVING COUNT(*) > 1
```

SQL standard (ISO/IEC 9075) requires `HAVING` to accompany `GROUP BY`, or for the `HAVING` expression to reference aggregates applied over the entire result as a single implicit group. Real-world DBMS behaviour varies:

- **MySQL/MariaDB**: Accepts `HAVING` without `GROUP BY` (treats entire result as one group).
- **PostgreSQL**: Rejects — `ERROR: column "X" must appear in the GROUP BY clause or be used in an aggregate function`.
- **SQL Server**: Rejects — `Column "X" is invalid in the HAVING clause because it is not contained in either an aggregate function or the GROUP BY list`.
- **SQLite**: Accepts silently (loose SQL dialect).

The wiki editor receives a raw DBMS error message with no extension-level explanation that the `having=` / `group by=` mismatch is the cause.

**Impact:** Users targeting PostgreSQL or SQL Server will receive cryptic database-specific errors when using `having=` without `group by=`, with no guidance from the extension.

**Fix:** Add a pre-execution check: if `$having` is non-empty and `$groupBy` is empty, return an error via a new i18n message `odbc-error-having-without-groupby`.

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

### 2.2 Admin Interface Runs Arbitrary SELECT Without Checking `$wgODBCAllowArbitraryQueries` ✅ Fixed in v1.3.0 (P2-054)

**File:** `includes/specials/SpecialODBCAdmin.php`, `runTestQuery()`  
**Status:** ✅ Fixed in v1.3.0 — `runTestQuery()` now checks `ODBCAllowArbitraryQueries` (global) and `allow_queries` (per-source) before executing, consistent with `executeComposed()`.

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

### 2.5 `validateIdentifier()` Allows Trailing Dots and Arbitrarily Deep Qualified Names ✦ NEW (v1.4.0 pass)

**File:** `includes/ODBCQueryRunner.php`, `validateIdentifier()`

The identifier validation regex is:

```php
'/^[a-zA-Z_][a-zA-Z0-9_\.]*$/'
```

The `\.` in the character class allows dots **anywhere** after the first character, with no structural constraint. This accepts:

- Trailing dots: `tablename.` — syntactically invalid SQL in every database
- Arbitrary depth: `a.b.c.d.e.f` — four qualification levels beyond anything any database supports
- Multiple consecutive dots: `table..column` — invalid SQL in all databases

Only `table.column` (two levels) or `schema.table.column` (three levels) are legitimately useful qualified forms. There is no upper bound on segments, and trailing or doubled dots are not rejected.

**Impact:** A wiki editor who accidentally types `MyTable.` or `schema..table` will see a confusing DBMS-level error, not a clear extension validation error. Inadvertent trailing dots may be generated by template-driven `from=` values where the template appends a period.

**Fix:** Replace the current regex with one that explicitly validates the segment structure:
```php
'/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*){0,2}$/'
```
This allows one, two, or three dot-separated segments (e.g., `table`, `schema.table`, `catalog.schema.table`) and rejects trailing dots, consecutive dots, or more than three levels.

---

### 2.6 `withOdbcWarnings()` Captures All PHP `E_WARNING` — Not Exclusively ODBC Errors ✦ NEW (v1.4.0 pass)

**File:** `includes/ODBCConnectionManager.php`, `withOdbcWarnings()`

```php
set_error_handler(static function (int $errno, string $errstr): bool {
    throw new MWException($errstr);
}, E_WARNING);
```

The `E_WARNING` mask captures **all** PHP warnings emitted during the callback's execution — not only ODBC driver warnings. Any unrelated PHP warning (a deprecated function notice, a type coercion warning, or a third-party library warning triggered transitively) will be converted to an `MWException` whose message is the PHP warning text, not an ODBC error.

Two problems result:

1. **Incorrect exception message**: An `MWException("Deprecated: some PHP call")` is thrown instead of the expected ODBC connection error, producing misleading output displayed to the wiki user.
2. **Masking of real errors**: If an unrelated PHP warning fires before the ODBC failure, the ODBC error is never seen; the PHP warning message is reported instead.

**Impact:** Low probability in PHP 7.4 but increases on PHP 8.x where more internal calls produce `E_WARNING`. Difficult to debug because the exception message does not resemble an ODBC error.

**Fix:** Add an origin check inside the handler before throwing, to pass non-ODBC warnings through to the outer error handler:
```php
set_error_handler(static function (int $errno, string $errstr): bool {
    // Only intercept ODBC driver warnings; let other warnings propagate normally.
    if (stripos($errstr, 'odbc') === false && stripos($errstr, '[unixODBC]') === false) {
        return false; // pass to next handler
    }
    throw new MWException($errstr);
}, E_WARNING);
```

---

### 2.7 `EDConnectorOdbcGeneric::from()` Injects Table Aliases Without Sanitization ✦ NEW (v1.4.0 pass)

**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `from()`

```php
protected function from(): string {
    $parts = [];
    foreach ( $this->tables as $alias => $table ) {
        if ( is_numeric( $alias ) ) {
            $parts[] = $table;
        } else {
            $parts[] = "$table AS $alias";
        }
    }
    return implode( ', ', $parts );
}
```

`$table` values are passed through `checkComposedParams()` → `ODBCQueryRunner::sanitize()`. However, the `$alias` values (array keys for named aliases) are **never validated**. They flow directly into the `AS $alias` fragment of the FROM clause without any call to `validateIdentifier()` or `sanitize()`.

External Data source configuration is admin-controlled and resides in `LocalSettings.php`, so the immediate attack surface is limited. However, this is a defence-in-depth gap: it breaks the invariant that all SQL identifiers generated by the extension are validated, inconsistent with `executeComposed()` which validates all identifiers before use.

**Impact:** Misconfigured or (in a compromise scenario) maliciously-edited `$wgExternalDataSources` alias values can inject arbitrary SQL into FROM clauses.

**Fix:** Call `ODBCQueryRunner::validateIdentifier( $alias )` on each alias key inside `from()` before use. If validation fails, throw an `MWException` consistent with the identifier-validation errors in `executeComposed()`.

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

### 3.5 Connection Pool Eviction Is FIFO, Not LRU ✅ Fixed in v1.1.0 (P2-024)

**File:** `includes/ODBCConnectionManager.php`, `connect()`
**Status:** ✅ Fixed in v1.1.0 (P2-024) — `$lastUsed` array tracks last-access timestamp per source; `asort()` + `array_key_first()` now evicts the least-recently-used connection.

The original eviction used `array_key_first( self::$connections )` — FIFO order (oldest-opened first). After P2-024, a `private static array $lastUsed` property records the `microtime(true)` timestamp of the most-recent use for each source. On pool overflow, `asort($lastUsed)` puts the least-recently-used source first and `array_key_first()` selects it for eviction. Active, frequently-used connections are retained; idle connections are evicted.

~~**Fix:** Record the last-used timestamp for each connection alongside the connection handle. Evict the connection with the oldest last-use timestamp.~~ Applied in v1.1.0.

---

### 3.6 No PHP Namespaces; Legacy `AutoloadClasses` Format ✦ NEW

**Files:** All PHP files, `extension.json`

All PHP classes (`ODBCConnectionManager`, `ODBCQueryRunner`, `ODBCParserFunctions`, `ODBCHooks`, `SpecialODBCAdmin`, `EDConnectorOdbcGeneric`) are declared in the **global namespace**. Modern PHP (7.4+) and modern MediaWiki extensions use PHP namespaces (e.g., `MediaWiki\Extension\ODBC\`) to avoid naming collisions with other extensions and to enable PSR-4 autoloading.

`extension.json` uses the legacy `AutoloadClasses` format (explicit class-to-file mapping) rather than `AutoloadNamespaces` (PSR-4 autoloading by namespace prefix). This is technically functional but is the deprecated legacy approach.

**Impact:** Risk of class name collisions with other extensions using identical names (e.g., a different extension also registering a global `ODBCQueryRunner` class). No unit testing namespace isolation.

**Fix:** Introduce the `MediaWiki\Extension\ODBC\` namespace in all PHP files, switch `extension.json` to `AutoloadNamespaces`, and add `ServiceWiring.php`. This is a v2.0 breaking-change item but should be planned now.

---

### 3.7 `extension.json` `callback` Key Is Deprecated in Modern MediaWiki ✅ Fixed in v1.3.0 (P2-054)

**File:** `extension.json`  
**Status:** ✅ Fixed in v1.3.0 — `"callback"` removed; `"ExtensionRegistration": "ODBCHooks::onRegistration"` added to the `"Hooks"` section.

The `callback` key in `extension.json` was the early mechanism for running one-time setup code at extension load time. It is deprecated in modern MediaWiki releases in favor of using the `ExtensionRegistration` hook. The hook registration is functionally equivalent; `onRegistration()` is called at the same point in the extension loading lifecycle.

~~**Fix:** Register setup logic using the proper `ExtensionRegistration` hook.~~ Applied in v1.3.0.

---

### 3.8 `getMainConfig()` Called Repeatedly Inside Hot Methods ✅ Fixed in v1.3.0 (P2-055)

**Files:** `includes/ODBCQueryRunner.php`  
**Status:** ✅ Fixed in v1.3.0 — `private $mainConfig` property added; set once in the constructor via `MediaWikiServices::getInstance()->getMainConfig()`; all three method-level calls replaced with `$this->mainConfig`.

`MediaWikiServices::getInstance()->getMainConfig()` was called independently in `executeComposed()`, `executePrepared()`, and `executeRawQuery()`. Each call goes through the service locator. Caching the result in the constructor eliminates the redundant lookups.

---

### 3.9 `$where` and Query Clause Values Are Passed Raw to SQL

**File:** `includes/ODBCQueryRunner.php`, `executeComposed()`

After `sanitize()` passes, the WHERE/ORDER BY/GROUP BY/HAVING strings are directly interpolated into the SQL string with string concatenation. There is no quoting of values, no parameterization. A value like `1=1 OR 2=2` or `col > (SELECT MIN(id) FROM users)` passes all checks and executes as written. The blocklist sanitization is the only barrier. This is not meaningful parameterization.

---

### 3.10 `EDConnectorOdbcGeneric` Is Always Autoloaded Even Without External Data ✅ Fixed in v1.4.0 (P2-059)

**File:** `extension.json`, `includes/connectors/EDConnectorOdbcGeneric.php`  
**Status:** ✅ Fixed in v1.4.0 — `class_exists('EDConnectorComposed', false)` guard added at top of `EDConnectorOdbcGeneric.php`; file returns early if External Data is absent.

The class is still registered in `AutoloadClasses` (External Data needs to look it up), but the class definition itself is gated on `EDConnectorComposed` being available. If External Data is not installed, the file is loaded but no class is defined — no fatal error.

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

### 3.12 `$wgODBCMaxConnections` Is a Per-PHP-Process Limit, Not a System-Wide Cap ✦ NEW (v1.4.0 pass)

**File:** `includes/ODBCConnectionManager.php`

`ODBCConnectionManager::$connections` is a `private static array`. In PHP-FPM, each worker process has its own static memory space. With N active worker processes and `$wgODBCMaxConnections = 10`, the total number of open ODBC connections in the system can reach **N × 10** — potentially hundreds or thousands on a moderately loaded server.

The KI-053 fix correctly updated the documentation to say the limit applies "across all sources combined," which is accurate within one PHP process. However, **no documentation states that this is a per-worker-process limit**, not a global system cap. Operators who set `$wgODBCMaxConnections = 10` believing it caps total database connections on their infrastructure are mistaken.

**Impact:** Database servers with strict connection limits (PostgreSQL `max_connections`, SQL Server per-user connection limits, Access per-file lock limits) may unexpectedly hit those limits under moderate traffic. A deployment with 50 PHP-FPM workers and `$wgODBCMaxConnections = 10` opens up to **500 ODBC handles** simultaneously, and the configured limit provides no warning or throttling.

This is an inherent limitation of the static-pool architecture (fixing it properly requires moving to a pre-fork connection broker or external pool middleware). However, the limitation must be documented clearly.

**Short-term fix:** Update `extension.json` config description, README "Connection pooling" note, and a new SECURITY.md caveat: "`$wgODBCMaxConnections` is a **per-PHP-worker-process** limit. In PHP-FPM deployments with multiple workers, the total system-wide connection count can be up to `$wgODBCMaxConnections × [number-of-FPM-workers]`."

---

### 3.13 No Transaction Support or Snapshot Isolation ✦ NEW (v1.4.0 pass)

**File:** All query execution paths

The extension has no mechanism for:

- Starting or committing a database transaction
- Setting transaction isolation levels (`READ UNCOMMITTED`, `REPEATABLE READ`, `SNAPSHOT`, `SERIALIZABLE`)
- Grouping multiple `{{#odbc_query:}}` calls on the same page within a consistent read snapshot

A wiki page with two `{{#odbc_query:}}` calls to the same source may see two different database snapshots if rows are committed between the two executions. For reporting pages combining multiple interdependent queries (a common use case), this creates data integrity risks: totals may not match line items; foreign-key lookups constructed across separate queries may be inconsistent.

**Impact:** Low for simple pages with a single query; potentially significant for dashboard pages combining multiple queries that must see a consistent snapshot. Operators have no mechanism to enable snapshot reads even when their DBMS supports them.

**Fix:** This is a v2.0.0 scope item requiring coordination with the connection manager. In the near term, document the limitation explicitly in README and SECURITY.md.

---

### 3.14 Database `NULL` Values Are Silently Coerced to Empty String ✦ NEW (v1.4.0 pass)

**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`; `includes/ODBCParserFunctions.php`, `mergeResults()`

When `odbc_fetch_array()` returns a `NULL` column value, PHP receives `null`. The `mergeResults()` function casts all values via `(string)$value`, which converts PHP `null` to `''` (empty string). There is no way for a wiki template to distinguish between a row with a `NULL` database value and a row with an actual empty string value.

This matters in practice:

1. **Semantic accuracy**: `NULL` typically means "unknown" or "not applicable", not "empty". Displaying them identically misrepresents the data.
2. **Conditional template logic**: `{{#if:{{{myvar|}}}|...}}` cannot distinguish `NULL` from `''` — they behave identically. Templates cannot apply special rendering for absent values.
3. **No `null_value=` option**: There is no parameter allowing editors to specify what to display for `NULL` (e.g., `—`, `N/A`, `0`, `unknown`).

**Impact:** Any reporting page displaying data where NULL is semantically distinct from empty string shows blank cells with no indication the slot is absent rather than empty. Conditional template logic that should treat NULL specially cannot be written.

**Fix:** Add a `null_value=` parameter to `{{#odbc_query:}}` (defaulting to `''` for backward compatibility) whose value is stored in place of PHP `null` when `mergeResults()` processes row data.

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

### 4.23 `CHANGELOG.md` v1.4.0 Entry Again Marked `[Unreleased]` ✦ NEW (v1.4.0 pass)

**File:** `CHANGELOG.md`

The same pattern that was documented as KI-030 (v1.0.3), KI-041 (v1.1.0), and found again during the v1.2.0 review pass has recurred for a fourth consecutive release:

```markdown
## [Unreleased] — v1.4.0
```

`extension.json` declares `"version": "1.4.0"`. The shipped codebase IS v1.4.0. The CHANGELOG entry should carry the actual release date, not `[Unreleased]`. This pattern has now occurred across every minor release of the extension without exception, strongly suggesting a missing release-checklist step.

**Fix:** (1) Replace `[Unreleased]` with the actual release date. (2) Add a release-checklist item or a CI check (e.g., a `grep "\[Unreleased\]" CHANGELOG.md && exit 1` step in a release workflow) to prevent this from recurring in every future release.

---

### 4.24 `codebase_review.md` and `improvement_plan.md` Header Versions Were Stale After v1.3.0 and v1.4.0 ✦ NEW (v1.4.0 pass)

**Files:** `codebase_review.md`, `improvement_plan.md`

Both files accurately documented v1.3.0 and v1.4.0 fixes **inline** (sections annotated "✅ Fixed in v1.3.0"), but neither file's **header block or overall summary section** had been updated to reflect those review passes specifically:

- `codebase_review.md` header: stated `Extension Version: 1.1.0` — correct for the initial write, but inaccurate once v1.2.0+ inline fixes were added without bumping the header version.
- `codebase_review.md` §7 Summary Scorecard: footer said "v1.2.0 pass, 2026-03-06" without noting the subsequent v1.3.0 and v1.4.0 inline fixes.
- `improvement_plan.md` header: said "Last updated: 2026-03-06 (v1.2.0 pass)" — v1.3.0/v1.4.0 P2 items were marked done inline but the header and review-note block were not extended to cover those passes.

**Fix:** Update headers, summary sections, and review-note blocks upon each review pass (this v1.4.0 document update addresses this).

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

### 5.3 `$params['source'] ?? ( $params[0] ?? '' )` Positional Fallback Is Undocumented ✅ Fixed in v1.4.0 (P2-060)

**File:** `includes/ODBCParserFunctions.php`  
**Status:** ✅ Fixed in v1.4.0 — Inline comment in `odbcQuery()` and README `source=` parameter row updated to document the positional form.

The positional behaviour (`{{#odbc_query: mydb | ...}}`) is preserved; it is now documented.

---

### 5.4 Log Messages Use Inconsistent Separator Characters ✅ Fixed in v1.4.0 (P2-061)

**File:** `includes/ODBCQueryRunner.php`  
**Status:** ✅ Fixed in v1.4.0 — `Prepare failed [{$sourceId}]:` and `Execute failed [{$sourceId}]:` changed to `on source '{$sourceId}':` format, consistent with all other log messages.

---

### 5.5 `Html::textarea()` Uses Deprecated `cols` Attribute ✅ Fixed in v1.3.0 (P2-058)

**File:** `includes/specials/SpecialODBCAdmin.php`  
**Status:** ✅ Fixed in v1.3.0 — `'cols' => 80` removed; replaced with `'style' => 'width: 100%; max-width: 60em; box-sizing: border-box;'`.

```php
$html .= Html::textarea( 'sql', '', [
    'rows' => 6,
    'cols' => 80,
```

`cols` is a deprecated presentation attribute in HTML5. The width should be controlled via CSS.

---

### 5.6 Silent Truncation of `data=` Mappings Exceeding 256 Characters ✅ Fixed in v1.3.0 (P2-057)

**File:** `includes/ODBCParserFunctions.php`, `parseDataMappings()`  
**Status:** ✅ Fixed in v1.3.0 — `wfDebugLog('odbc', ...)` entry added when a pair is dropped; includes pair length and first 80 characters.

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

### 5.8 `mb_detect_encoding()` Called Per-Cell Per-Row — O(rows × columns) ✦ NEW (v1.4.0 pass)

**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`, per-row encoding conversion loop

```php
foreach ( $row as $key => $value ) {
    if ( $value !== null ) {
        $encoding = mb_detect_encoding( $value, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'ASCII'], true );
        // ...
    }
}
```

`mb_detect_encoding()` with a 5-element candidate list performs multiple internal passes over each string. This is called for **every non-null string value** of **every row** fetched — O(rows × columns) calls per query. For a result set of 1,000 rows × 10 columns, this is 10,000 encoding-detection calls per query, each O(string_length). For large text columns this adds measurable overhead.

**Better approaches:**

1. Detect encoding **once per result set** using the first row as a sample.
2. Expose a per-source `charset=` config option that operators set explicitly, eliminating runtime detection.
3. Rely on PHP's `odbc_field_type()` metadata for drivers that expose charset information.

**Impact:** Low for small result sets; measurable for queries returning 500+ rows or columns containing long text values.

---

### 5.9 `forOdbcTable` Does O(rows × variables) `str_replace()` Calls on the Full Template ✦ NEW (v1.4.0 pass)

**File:** `includes/ODBCParserFunctions.php`, `forOdbcTable()`

```php
foreach ( $rows as $row ) {
    $rowWikitext = $templateText;
    foreach ( $row as $varName => $value ) {
        $search = '{{{' . $varName . '}}}';
        $rowWikitext = str_replace( $search, $value, $rowWikitext );
    }
    $output .= $rowWikitext;
}
```

For each row the full template string is copied then `str_replace()` is called once per variable, scanning the entire template string each time. For a template with 20 variables and 1,000 rows, this is 20,000 string operations over a potentially multi-kilobyte string.

**Better approach:** Use `strtr()` with a pre-built replacement map, replacing all variables in a single linear scan of the template:

```php
foreach ( $rows as $row ) {
    $map = [];
    foreach ( $row as $varName => $value ) {
        $map['{{{' . $varName . '}}}'] = $value;
    }
    $output .= strtr( $templateText, $map );
}
```

`strtr()` with an array argument does one pass through the subject string, making all substitutions simultaneously. This is O(template_length) per row instead of O(template_length × num_variables).

**Impact:** Low for small datasets; measurable for large row counts or templates with many variable references.

---

## 6. Missing Features / Incomplete Implementations

### 6.1 No Rate Limiting on `{{#odbc_query:}}` ✅ Fixed in v1.1.0 (`$wgODBCMaxQueriesPerPage` / P2-032)

A page with many template inclusions could trigger dozens or hundreds of ODBC queries per page view. **Fixed in v1.1.0:** `$wgODBCMaxQueriesPerPage` (default `0` = unlimited) caps the number of `{{#odbc_query:}}` calls per page render. Returns an i18n error when exceeded.

---

### 6.2 No Mechanism to Access Specific Row Values from `{{#odbc_value:}}` ✅ Fixed in v1.2.0 (KI-019 / P2-019)

`{{#odbc_value:varName}}` always returns the first row. **Fixed in v1.2.0:** An optional third positional parameter (or `row=N` named form) selects a specific row. `row=last` returns the final row. Out-of-range indices silently fall back to the default value.

---

### 6.3 No Connection Test for External Data Sources

`Special:ODBCAdmin` tests sources from `$wgODBCSources` only. If using the ED connector path (sources in `$wgExternalDataSources`), there is no admin UI to test those connections.

---

### 6.4 `EDConnectorOdbcGeneric` Does Not Apply `$wgODBCCacheExpiry` or UTF-8 Conversion ✅ Fixed in v1.1.0 (P2-016)

Result caching and automatic UTF-8 encoding conversion are absent from the ED connector code path. **Fixed in v1.1.0 (P2-016):** `EDConnectorOdbcGeneric::fetch()` now delegates to `ODBCQueryRunner::executeRawQuery()` when in `odbc_source` mode, inheriting result caching and UTF-8 conversion. Standalone External Data mode still lacks caching/conversion (KI-020 partially open).

---

### 6.5 No `composer.json` `require-dev` for Testing Tools

**File:** `composer.json`

There is no `require-dev` section defining PHPUnit, MediaWiki's test utilities, or coding standards tools (PHP_CodeSniffer). This is standard practice for MediaWiki extensions — it enables `composer install --dev` to set up a test environment. Without it, there is no defined way to run tests or check coding standards.

---

### 6.6 No Slow Query Logging ✅ Fixed in v1.2.0 (`$wgODBCSlowQueryThreshold` / P2-053)

**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`  
**Status:** ✅ Fixed in v1.2.0 — Query elapsed time (execute + fetch) is now appended to every `odbc` debug log entry. When `$wgODBCSlowQueryThreshold > 0`, queries exceeding the threshold are additionally logged to the `odbc-slow` channel.

---

### 6.7 No Configurable `NULL` Value Representation ✦ NEW (v1.4.0 pass)

**File:** `includes/ODBCParserFunctions.php`, `mergeResults()`

See §3.14. Database `NULL` values are silently coerced to empty string `''`. Wiki templates cannot distinguish `NULL` from an empty-string value, and there is no `null_value=` parameter to specify an alternative representation (e.g., `N/A`, `—`, `0`, `unknown`). This is a functional gap for any reporting page where NULL carries semantic meaning.

---

### 6.8 No Transaction Isolation or Multi-Query Snapshot Consistency ✦ NEW (v1.4.0 pass)

**File:** All query execution paths

See §3.13. Pages combining multiple `{{#odbc_query:}}` calls see potentially inconsistent database snapshots. There is no mechanism to begin a transaction, set isolation level, or group queries into a consistent snapshot read. For dashboard pages merging totals across multiple queries this creates data integrity concerns. This is a v2.0.0 scope item.

---

## 7. Summary Scorecard

| Category | Count (v1.4.0 pass) | Notes |
|----------|---------------------|-------|
| Bugs | **1 open** (KI-008 — SELECT \* logged but still issued) + **1 new** (§1.10 HAVING without GROUP BY) | KI-019 fixed (row= param) |
| Security Issues | **6 total** (2 new: §2.5 validateIdentifier dots, §2.6 withOdbcWarnings overbroad, §2.7 ED alias unsanitized) | Blocklist-only defence remains structural; 3 new defence-in-depth gaps |
| Design Problems | **14** (3 new: §3.12 pool per-process, §3.13 no transactions, §3.14 no NULL handling) | 3 Major (no tests, no namespace, static design), 8 Moderate, 3 Minor |
| Documentation Errors | **~2 open** (§4.23 CHANGELOG [Unreleased] recurs again; §4.24 stale headers) | KI-020 standalone mode still partially open |
| Code Quality Issues | **8** (2 new: §5.8 mb_detect_encoding per-cell, §5.9 str_replace O(n\*m)) | Style/performance |
| Missing Features | **6** (2 new: §6.7 no NULL representation, §6.8 no transaction isolation) | Functional gaps |


- **KI-037** (README magic word version claim): P2-031 Done — updated to say "v1.0.3+"
- **KI-040** (`validateConfig()` Progress host key): confirmed fixed — `empty($config['host'])` present at `ODBCConnectionManager.php` line 364
- **KI-041–KI-048** (documentation errors from v1.1.0 re-review): all confirmed fixed per improvement_plan P2-036/037/038/039/040/041/042/043/044 Done markers and file content verification

**Fixes since v1.2.0 pass (v1.3.0 — 2026-03-xx):**
- **§2.2 fixed (P2-056):** Admin `runTestQuery()` now enforces `$wgODBCAllowArbitraryQueries`; per-source `allow_queries` checked consistently.
- **§3.7 fixed (P2-054):** `extension.json` `callback` key replaced with `ExtensionRegistration` hook.
- **§3.8 fixed (P2-055):** `$mainConfig` cached in `ODBCQueryRunner` constructor; three redundant `getMainConfig()` calls eliminated.
- **§5.5 fixed (P2-058):** Deprecated `cols` attribute removed from admin textarea; replaced with CSS `width`.
- **§5.6 fixed (P2-057):** Silent truncation of overlong `data=` mappings now logs a `wfDebugLog('odbc', ...)` diagnostic.

**Fixes since v1.2.0 pass (v1.4.0 — 2026-03-xx):**
- **§3.10 fixed (P2-059):** `EDConnectorOdbcGeneric` now guards against missing `EDConnectorComposed` with `class_exists` early-return.
- **§5.3 fixed (P2-060):** Positional `source=` argument in `{{#odbc_query:}}` documented in code and README.
- **§5.4 fixed (P2-061):** `wfDebugLog` prefix format standardised across all log messages in `ODBCQueryRunner`.
- **§6.5 fixed (P2-062):** `require-dev` added to `composer.json`; `.phpcs.xml` created; `composer test` and `composer phpcs` scripts defined.

**New findings this pass (v1.4.0 review, 2026-03-08):**
- **§1.10 (Bug):** `executeComposed()` accepts `having=` without `group by=`, generating invalid SQL on PostgreSQL and SQL Server.
- **§2.5 (Security):** `validateIdentifier()` regex allows trailing dots and unlimited dot-segment depth; `tablename.` and `a.b.c.d.e` both pass.
- **§2.6 (Security):** `withOdbcWarnings()` converts ALL PHP `E_WARNING` to `MWException`, not just ODBC driver warnings; unrelated PHP warnings produce misleading exceptions.
- **§2.7 (Security):** `EDConnectorOdbcGeneric::from()` builds `TABLE AS alias` SQL fragments with alias values that are never passed through `validateIdentifier()`.
- **§3.12 (Design):** `$wgODBCMaxConnections` is undocumented as a per-PHP-process limit; in PHP-FPM, system-wide connections = limit × worker count.
- **§3.13 (Design):** No transaction support or snapshot isolation; multi-query pages may see inconsistent database snapshots.
- **§3.14 (Design):** Database `NULL` is silently coerced to empty string `''`; templates cannot distinguish NULL vs empty; no `null_value=` parameter exists.
- **§4.23 (Documentation):** `CHANGELOG.md` v1.4.0 is again marked `[Unreleased]` — the pattern has repeated for the fourth consecutive release.
- **§4.24 (Documentation):** The `codebase_review.md` and `improvement_plan.md` headers were stale (said v1.1.0 / v1.2.0) despite v1.3.0/v1.4.0 inline fixes being present.
- **§5.8 (Performance):** `mb_detect_encoding()` is called O(rows × columns) times per query; should be sampled once per result set or driven by a per-source `charset=` config option.
- **§5.9 (Performance):** `forOdbcTable` calls `str_replace()` O(variables) times per row; replacing with `strtr($template, $map)` eliminates the inner loop entirely.
- **§6.7 (Missing Feature):** No `null_value=` parameter — see §3.14.
- **§6.8 (Missing Feature):** No transaction isolation — see §3.13.

**Cumulative fixed total (all versions through v1.4.0):** 54 issues resolved across v1.0.1 through v1.4.0. 13 issues newly identified in this v1.4.0 review pass.

**Overall Assessment (v1.4.0 pass, 2026-03-08):** The extension is in a solid, production-ready state for standard use cases. v1.3.0 and v1.4.0 addressed the remaining developer-experience gaps (config caching, deprecated API migration, HTML5 compliance, observability, developer tooling). The newly identified issues fall into two tiers: (1) low-probability but real security defence-in-depth gaps (§2.5, §2.6, §2.7) that should be addressed in a v1.4.x patch series; and (2) architectural limitations (NULL handling, transaction isolation, per-process pool documentation) properly scoped to v2.0.0. The recurring `[Unreleased]` CHANGELOG pattern (now four releases in a row) warrants a CI enforcement step. The continued absence of a unit test suite remains the single most impactful quality gap — every bug found in review should instead be caught by an automated regression test.

---

## 8. V1.5.0 Review Pass Findings (2026-03-03)

This section documents new issues found during the v1.5.0 review pass. All items in §1–§7 that were identified in v1.4.0 are confirmed fixed in v1.5.0 (P2-063 through P2-071). The issues below are new findings from this pass.

### 8.1 Slow-Query Timer Measures Row-Fetch Time Only (KI-073) ✦ NEW

**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`  
**Severity:** Bug  
**Impact:** `$wgODBCSlowQueryThreshold` is unreliable for detecting slow database-side execution

`$queryStart = microtime( true )` is placed *after* `odbc_execute()` has already returned — it is inside the `withOdbcWarnings()` closure but set after the execute call. The `$elapsed` time therefore measures only the PHP-side `odbc_fetch_array()` loop, not the ODBC driver's execution of the SQL on the database server. A query that takes 29 seconds to execute server-side but only 0.1 seconds to fetch will never appear in the `odbc-slow` log at a 10-second threshold.

**Fix:** Move `$queryStart = microtime( true )` to immediately before `$success = odbc_execute( $stmt, $params )`. Single-line change, no risk of regression.

---

### 8.2 ED Connector Standalone Mode Bypasses Per-Statement Timeout (KI-074) ✦ NEW

**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `fetch()` (standalone path)  
**Severity:** Functional Limitation

The ED connector's standalone fetch path uses `odbc_exec( $this->odbcConnection, $query )` directly. Unlike `ODBCQueryRunner::executeRawQuery()` which uses `odbc_prepare()` + `odbc_setoption()` + `odbc_execute()`, the standalone path never calls `odbc_setoption()`. Consequently `$wgODBCQueryTimeout` and per-source `timeout=` have no effect on External Data standalone queries.

**Fix:** Replace `odbc_exec()` with the prepare/setoption/execute pattern, mirroring `executeRawQuery()`.

---

### 8.3 `requiresTopSyntax()` Has No `wfDeprecated()` Call (KI-075) ✦ NEW

**File:** `includes/ODBCQueryRunner.php`, `requiresTopSyntax()`  
**Severity:** Code Quality

The method is annotated `@deprecated since 1.1.0` but emits no runtime deprecation notice. Third-party callers and future contributors receive no signal to migrate to `getRowLimitStyle()`. The method will never accumulate pressure to be removed without a `wfDeprecated()` call.

**Fix:** Add `wfDeprecated( __METHOD__, '1.1.0', 'ODBC' );` as the first statement.

---

### 8.4 `extension.json` `ODBCSources` Description Cites Non-Existent `options` Key (KI-072) ✦ NEW

**File:** `extension.json`, `ODBCSources` config description  
**Severity:** Documentation Error

The `ODBCSources` config description mentions `options (optional)` as a supported sub-key. No `options` key is referenced in `ODBCConnectionManager`, `validateConfig()`, `buildConnectionString()`, or any other code. An operator who adds an `options` key to their source silently gets no effect.

**Fix:** Remove `options (optional)` from the description string.

---

### 8.5 `UPGRADE.md` Has No v1.5.0 Section (KI-076) ✦ NEW

**File:** `UPGRADE.md`  
**Severity:** Documentation Error

Every version from v1.0.1 through v1.4.0 has its own upgrade section. v1.5.0 introduces multiple operator-visible changes (`null_value=`, `charset=`, tightened identifier validation, `having=` guard, per-process connection docs) that all require upgrade notes. No such section exists.

**Fix:** Add a "Upgrading to 1.5.0 from 1.4.0" section.

---

### 8.6 `wiki/Architecture.md` Still References Deprecated `callback` Key (KI-071) ✦ NEW

**File:** `wiki/Architecture.md`, `ODBCHooks` component description  
**Severity:** Documentation Error

The `ODBCHooks` description reads *"Called by MediaWiki at load time via the `callback` key in `extension.json`."* The `callback` key was removed in v1.3.0 (P2-054) and replaced with the `ExtensionRegistration` hook. The wiki doc was not updated at that time.

**Fix:** Change to reference the `ExtensionRegistration` hook.

---

### 8.7 `wiki/Architecture.md` Design Limitations Table Has Stale Resolved-Item Rows (KI-078) ✦ NEW

**File:** `wiki/Architecture.md`, Design Limitations table  
**Severity:** Documentation Quality

Three rows (FIFO eviction → fixed v1.1.0, MS Access ping failure → fixed v1.1.0, `validateConfig()` dead code → fixed v1.1.0) are displayed with strikethrough formatting rather than being removed. A limitations table should describe current limitations, not preserve resolved history in-place. Historical tracking belongs in KNOWN_ISSUES.md.

**Fix:** Remove the three resolved rows. Only retain currently-open limitations.

---

### 8.8 `wiki/Known-Issues.md` Is Severely Stale at v1.1.0 (KI-079) ✦ NEW

**File:** `wiki/Known-Issues.md`  
**Severity:** Documentation Error — HIGH

The wiki Known Issues page footer reads `Last updated: v1.1.0, 2026-03-03`. Critical inaccuracies:

- **KI-019** ("Cannot Access Non-First Rows") is shown as still open with `Planned fix: v2.0.0` — this was **fixed in v1.2.0** (P2-019, row= selector added to `{{#odbc_value:}}`). Editors following this page believe a fundamental feature gap still exists when it has been resolved for several releases.
- No mentions of *any* fixes in v1.2.0, v1.3.0, v1.4.0, or v1.5.0.
- The Resolved Issues summary says "10 were fixed in v1.1.0" with no subsequent additions.
- New features added in v1.5.0 (`null_value=`, `charset=`) are absent.

This is the public-facing issue reference for wiki editors. Showing open issues that are long-resolved, and failing to credit four releases worth of improvements, significantly undermines trust.

**Fix:** Full update to v1.5.0: mark KI-019 fixed (v1.2.0), add v1.2.0–v1.5.0 fix summaries to Resolved section, reduce Open Issues to KI-008 and KI-020 (partial), update footer.

---

### 8.9 `wiki/Security.md` Release History Only Through v1.1.0; Formatting Bug (KI-080) ✦ NEW

**File:** `wiki/Security.md`, "Security Release History" table  
**Severity:** Documentation Error

The release history table only covers v1.0.0–v1.1.0. Entries for v1.2.0 (noparse XSS fix), v1.3.0 (admin arbitrary query enforcement), v1.4.0 (ED alias validation), and v1.5.0 (withOdbcWarnings scoping, validateIdentifier tightening) are all absent.

Additional formatting bug: the last table row uses `||` (double pipe) which renders the v1.1.0 content as a continuation of the v1.0.3 cell, not as its own row.

**Fix:** Fix the double-pipe formatting. Add four new rows for v1.2.0–v1.5.0.

---

### 8.10 `wiki/Parser-Functions.md` Missing `null_value=` Parameter; Invalid Comment Syntax (KI-081, KI-084) ✦ NEW

**File:** `wiki/Parser-Functions.md`  
**Severity:** Documentation Error / Documentation Quality

**Issue A (KI-081):** The parameters table for `{{#odbc_query:}}` does not include the `null_value=` parameter added in v1.5.0. Editors cannot discover this parameter from the reference page.

**Issue B (KI-084):** The `{{#odbc_value:}}` examples section contains:
```
{{# Access a specific row of a multi-row result: }}
```
This uses `{{#` as an inline comment, which is not valid MediaWiki syntax — `{{#` begins a parser function call. On a real wiki page this would produce a parser error or unwanted output. The correct syntax for a wiki comment is `<!-- comment text -->`.

**Fixes:** (A) Add `null_value=` row to the parameters table. (B) Replace `{{# ...}}` with `<!-- ... -->`.

---

### 8.11 `wiki/Configuration.md` Missing `charset=`, `host`, and `db` Keys (KI-082, KI-083) ✦ NEW

**File:** `wiki/Configuration.md`, Connection Options Reference table  
**Severity:** Documentation Error

**Issue A (KI-082):** `charset=` (added v1.5.0, P2-069) is not in the Connection Options table. Operators who want to bypass encoding auto-detection cannot find the key.

**Issue B (KI-083):** `host=` and `db=` (the Progress OpenEdge alternatives to `server=` and `database=`) are not in the table. The code correctly supports them (fixed in v1.1.0, P2-034/P2-035), and `README.md` / `wiki/Installation.md` both show Progress examples using `host=` — but the authoritative Configuration reference table omits them. Operators consulting the reference will use `server=` and get a confusing validation error.

**Fixes:** Add rows for `charset=`, `host`, and `db` to the Connection Options table.

---

### 8.12 `wiki/Security.md` Known Limitations Table Incomplete; Contains Resolved Item (KI-085) ✦ NEW

**File:** `wiki/Security.md`, "Known Security Limitations" table  
**Severity:** Documentation Quality

The table has only two rows. One of them documents KI-033 (`@odbc_setoption` timeout failures) which was **fixed in v1.1.0** — a resolved issue in a current-limitations table is misleading. Current limitations not in the table include: `withOdbcWarnings()` broad capture (partially addressed in v1.5.0 but not fully), and the absence of per-user rate limiting.

**Fix:** Remove the KI-033 row. Add rows for the current known limitations.

---

### v1.5.0 Review Pass Summary Scorecard

| Category | Status |
|----------|--------|
| Code bugs | **1 new (KI-073):** Slow-query timer measures fetch-only; 1 previously open (KI-008 SELECT \*) |
| Functional limitations | **1 new (KI-074):** ED standalone mode no timeout |
| Code quality | **1 new (KI-075):** `requiresTopSyntax()` missing `wfDeprecated()` |
| Repository documentation errors | **2 new (KI-072, KI-076, KI-077):** extension.json phantom key; UPGRADE.md no v1.5.0 section; SECURITY.md Unreleased |
| Wiki documentation errors | **10 new (KI-071, KI-078–KI-085):** Architecture.md stale callback ref; stale table rows; Known-Issues.md 4 versions out of date; Security.md history incomplete + format bug; Parser-Functions.md missing null_value= + invalid comment; Configuration.md missing 3 keys; Security.md limitations table stale |
| **Total new issues this pass** | **15 (KI-071 through KI-085) — all resolved** |
| **Cumulative total** | **85 tracked; 83 fully resolved; 2 remain open (KI-008, KI-020 partial)** |

**Overall Assessment (v1.5.0 review pass, 2026-03-03, resolved 2026-03-03):** The PHP codebase is clean and well-hardened. All 15 issues found in this pass have been fixed in the same development cycle. The wiki pages (`Known-Issues.md`, `Security.md`, `Architecture.md`, `Parser-Functions.md`, `Configuration.md`) were brought fully up to date with the v1.5.0 code. The three PHP/JSON issues (KI-073 timer, KI-074 ED timeout, KI-075 wfDeprecated, KI-072 extension.json description) have been patched. A GitHub Actions CI pipeline (PHP lint on 7.4–8.3, `composer phpcs`, release tag check) was added. The continued absence of a comprehensive unit test suite (P3-003) and PHP namespace adoption (§3.6) remain the dominant architectural debts.

---

## 9. V1.5.x Post-Release Review Pass Findings

**Review Date:** 2026-03-09 (post-release comprehensive pass)  
**Scope:** Full re-read of all PHP source files, configuration files (composer.json, extension.json, ci.yml), and all wiki documentation pages. Focus on issues that may have been introduced or overlooked after the v1.5.0 packaging phase.

Eight new issues were identified. None are critical; two represent meaningful security defence-in-depth gaps, and the remainder are documentation inconsistencies, tooling hygiene, and minor design concerns.

---

### 9.1 PHP / Code Quality Findings

#### 9.1.1 `sanitize()` Blocklist Missing `CAST(` and `CONVERT(` (KI-088)

**File:** `includes/ODBCQueryRunner.php`, `sanitize()`  
**Severity:** Moderate (Security, defence-in-depth)

The `sanitize()` method's substring-match blocklist (`$charPatterns`) blocks `CHAR(` and `CONCAT(` — standard obfuscation stepping stones — but omits `CAST(` and `CONVERT(`. Both are used in common SQL injection obfuscation patterns:

- `CAST(0x44524F50 AS CHAR)` → the string `DROP` on SQL Server / MySQL
- `CONVERT(0x44454C455445 USING utf8)` → the string `DELETE` on MySQL
- `CAST(... AS xml)` → error-based exfiltration on SQL Server

This gap was noted in §2.1 of earlier review passes ("structural blocklist weaknesses — missing CAST and CONVERT") but was never assigned a KI identifier or a P2 remediation item.

The fix is to add both patterns to `$charPatterns`. Note that `CONVERT()` also has benign read-only uses (e.g., `CONVERT(price, UNSIGNED INTEGER)`) — the change should be noted in CHANGELOG and UPGRADE.md.

```php
$charPatterns = [
    ';', '--', '#', '/*', '*/', '<?',
    'CHAR(', 'CONCAT(', 'CAST(', 'CONVERT(',
];
```

**Status:** ✅ Fixed — v1.5.0 → assigned **KI-088**; remediation item **P2-089**

---

#### 9.1.2 `withOdbcWarnings()` Filter Incomplete: Missing Progress/Oracle/Other Driver Signatures (KI-089)

**File:** `includes/ODBCConnectionManager.php`, `withOdbcWarnings()`  
**Severity:** Minor (Driver compatibility)

The P2-066 fix (v1.5.0) added a filter to `withOdbcWarnings()` ensuring only ODBC-originated PHP warnings are promoted to `MWException`. The filter matches warning messages for: `odbc`, `[unixODBC]`, `[Microsoft]`, `[IBM]`. Driver vendors such as Progress/OpenEdge (`[Progress]`, `[OpenEdge]`) and Oracle (`[Oracle]`) are absent.

In practice, Progress drivers typically include `ODBC` in their message text (`[Progress][ODBC Open Client][...]`) so the gap is narrow. However, vendor-specific edge cases — particularly for Oracle ODBC drivers and Easysoft connectors — can produce warnings without any of the four matched strings. Those warnings would pass through to the PHP system error handler rather than being caught by `withOdbcWarnings()`.

Recommended fix: extend the filter with additional vendor signatures:

```php
$vendorPrefixes = ['odbc', '[unixODBC]', '[Microsoft]', '[IBM]',
                   '[Progress]', '[OpenEdge]', '[Oracle]', '[DataDirect]', '[Easysoft]'];
foreach ($vendorPrefixes as $prefix) {
    if (stripos($errstr, $prefix) !== false) { /* it's ours */ }
}
```

**Status:** ✅ Fixed — v1.5.0 → assigned **KI-089**; remediation item **P2-090**

---

#### 9.1.3 `displayOdbcTable()` Registration Inconsistent with Other Parser Functions (KI-090)

**File:** `includes/ODBCParserFunctions.php`; `includes/ODBCHooks.php`  
**Severity:** Minor (Design consistency)

`odbcQuery()` and `forOdbcTable()` are registered with `SFH_OBJECT_ARGS`, receiving arguments as `PPNode` objects (pre-parsing hook). `displayOdbcTable()` is registered with flag `0`, receiving pre-expanded string arguments via `...$params` (post-expansion hook). For `displayOdbcTable()`'s current functionality (one template name + one variable prefix) the practical impact is nil — but the inconsistency is undocumented and creates a subtle difference in argument expansion semantics that would matter if the function is ever extended.

**Fix (minimal):** Add an inline comment in `onParserFirstCallInit()` explicitly noting the intentional omission of `SFH_OBJECT_ARGS` for `displayOdbcTable`.

**Fix (full):** Promote `displayOdbcTable()` to `SFH_OBJECT_ARGS` for consistency. Schedule for v2.0 as a breaking change if `displayOdbcTable()` ever needs to handle nested templates or lazy argument expansion.

**Status:** ✅ Fixed — v1.5.0 → assigned **KI-090**; remediation item **P2-091**

---

### 9.2 Repository / Tooling Findings

#### 9.2.1 `composer.json` References EOL Package Versions (KI-091)

**File:** `composer.json`  
**Severity:** Minor (Developer tooling)

Two dependency constraints include EOL version ranges:

1. `"composer/installers": "^1.0 || ^2.0"` — composer/installers 1.x is EOL. The `^1.0` range can still resolve in some environments. The 1.x range is unnecessary; `^2.0` alone is correct.
2. `"phpunit/phpunit": "^9.0 || ^10.0"` — PHPUnit 9.x reached EOL in February 2024. Correct range for PHP 8.1+ is `^10.0 || ^11.0`.

**Fix:**
```json
"require": { "composer/installers": "^2.0" },
"require-dev": { "phpunit/phpunit": "^10.0 || ^11.0" }
```

**Status:** ✅ Fixed — v1.5.0 → assigned **KI-091**; remediation item **P2-092**

---

#### 9.2.2 CI Composer Cache Key Hashes `composer.json`, Not `composer.lock`; No Lockfile in Repository (KI-092)

**File:** `.github/workflows/ci.yml`  
**Severity:** Minor (CI reproducibility)

The Composer cache key in the GitHub Actions workflow uses `hashFiles('composer.json')`. Without a `composer.lock`, `composer install` resolves the latest version within each constraint range fresh from Packagist on every CI run. Two runs on different days can produce different dependency trees. The cache key itself becomes meaningless as a reproducibility signal — a cache hit could restore `vendor/` built with a different dependency set from the current constraint resolution.

**Fix (two-part):**
1. Commit `composer.lock` (run `composer install` locally and commit the lockfile).
2. Change the CI cache key to `hashFiles('composer.lock')`.

**Status:** ✅ Partially Fixed — v1.5.0 → assigned **KI-092**; remediation item **P2-093** — cache key updated; composer.lock deferred

---

### 9.3 Documentation Findings

#### 9.3.1 `wiki/Installation.md` Step 4 Cites "version 1.0.3" for Special:Version Check (KI-086)

**File:** `wiki/Installation.md`, verification step 4  
**Severity:** Minor (Documentation — stale version)

The Special:Version verification step instructs the operator to confirm the extension shows "version 1.0.3" in the Parser hooks section. Current version is 1.5.0. The fix is either to update the literal version number, or (better) to replace the hard-coded string with a general instruction to match the version in `extension.json`.

**Status:** ✅ Fixed — v1.5.0 → assigned **KI-086**; remediation item **P2-087**

---

#### 9.3.2 `wiki/Troubleshooting.md` UNION Section Presents KI-024 as an Active Open Issue (KI-087)

**File:** `wiki/Troubleshooting.md`, "Illegal SQL pattern 'UNION'" section  
**Severity:** Minor (Documentation — stale bug reference)

The troubleshooting page presents KI-024 ("UNION blocked by sanitiser substring match on identifiers like `LABOUR_UNION`") as a current limitation requiring a workaround ("rename the column"). This bug was **fixed in v1.1.0** (P2-018) — `UNION` was moved to the word-boundary regex list, making `LABOUR_UNION` safe. Editors following this page are misled into unnecessary schema changes.

Secondary inaccuracy in the same file: the Admin Interface section states "In the README (v1.0.3 and earlier) there was a reference to a `MAX_CONNECTIONS` constant." — It should read "v1.0.2 and earlier" (v1.0.3 was the version that *replaced* `MAX_CONNECTIONS` with `$wgODBCMaxConnections`).

**Status:** ✅ Fixed — v1.5.0 → assigned **KI-087**; remediation item **P2-088**

---

#### 9.3.3 `SECURITY.md` v1.0.2 Release History Entry Uses Non-Standard Date Format (KI-093)

**File:** `SECURITY.md`, Security Release History section  
**Severity:** Minor (Documentation consistency)

The v1.0.2 history section header reads `### Version 1.0.2 (March 2026)`. Every other entry uses `(YYYY-MM-DD)` format. The approximate `Month YYYY` format reduces the precision of the security audit trail.

**Fix:** Change to `### Version 1.0.2 (2026-03-02)` consistent with all other entries.

**Status:** ✅ Fixed — v1.5.0 → assigned **KI-093**; remediation item **P2-094**

---

### v1.5.x Post-Release Review Pass Summary Scorecard

| Category | New Issues | Notes |
|----------|------------|-------|
| Security (defence-in-depth) | 1 (KI-088) | `sanitize()` missing `CAST(` / `CONVERT(` blocklist entries |
| Driver compatibility | 1 (KI-089) | `withOdbcWarnings()` filter incomplete vendor coverage |
| Design consistency | 1 (KI-090) | `displayOdbcTable()` registration flag inconsistency |
| Developer tooling | 2 (KI-091, KI-092) | EOL package versions; no composer.lock / non-deterministic CI |
| Documentation (stale content) | 2 (KI-086, KI-087) | Installation.md version ref; Troubleshooting.md UNION stale |
| Documentation (consistency) | 1 (KI-093) | SECURITY.md date format |
| **Total new issues this pass** | **8 (KI-086 through KI-093)** | All resolved in v1.5.0 (KI-092 partially — composer.lock deferred) |
| **Cumulative total** | **93 tracked; 91 fully resolved; 2 remain open by design** | KI-008 (SELECT\* default), KI-020 (ED caching, partial); KI-092 technically partial |

**Overall Assessment (v1.5.0 post-review implementation, 2026-03-03):** All 8 issues identified in the post-release review pass were addressed in the same development cycle before the v1.5.0 release tag. The two PHP code fixes (KI-088 `CAST`/`CONVERT` blocklist; KI-089 ODBC warning filter) are low-risk, defence-in-depth improvements. The DevOps findings (KI-091 EOL packages; KI-092 CI lockfile) have been resolved at the code level with one deferred manual step (`composer.lock` commit). All documentation issues (KI-086, KI-087, KI-093) are corrected. The codebase is ready for the v1.5.0 release tag once the `composer.lock` is committed.
