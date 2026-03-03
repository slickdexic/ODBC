# Changelog

All notable changes to the MediaWiki ODBC Extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — v1.4.0

### Fixed

- **§3.10 / P2-059 — `EDConnectorOdbcGeneric` guarded against missing `EDConnectorComposed`** — If the External Data extension is not installed, PHP would throw a fatal `Class 'EDConnectorComposed' not found` error at autoload time if any code accidentally referenced `EDConnectorOdbcGeneric`. A `class_exists('EDConnectorComposed', false)` guard at the top of the file now causes the file to return early, preventing the fatal error. The class is still registered in `AutoloadClasses`, but will simply not be defined if External Data is absent.
- **§5.4 / P2-061 — Log message prefix format standardised** — Two log messages in `executeRawQuery()` used a `[{$sourceId}]:` bracket prefix (`Prepare failed [sourceId]:`, `Execute failed [sourceId]:`). All other log messages use a `on source '{$sourceId}'` format. Both corrected to match the majority format: `Prepare failed on source '...': ...`, `Execute failed on source '...': ...`.

### Improved

- **§5.3 / P2-060 — Positional source argument documented** — `{{#odbc_query: mydb | from=...}}` has always been equivalent to `{{#odbc_query: source=mydb | from=...}}`, but this was undocumented. The inline comment in `odbcQuery()` and the `source=` row in the README parameter table now explicitly describe the positional form.
- **§6.5 / P2-062 — `composer.json` `require-dev` and `.phpcs.xml` added** — `phpunit/phpunit` and `mediawiki/mediawiki-codesniffer` added as dev dependencies. `composer test` and `composer phpcs` convenience scripts defined. `.phpcs.xml` added with `MediaWiki` ruleset and a 160-char line-length override for SQL/log messages.

---

## [1.3.0] - 2026-03-03

### Fixed

- **§2.2 — `Special:ODBCAdmin` run-query now respects `$wgODBCAllowArbitraryQueries`** — `runTestQuery()` previously called `executeRawQuery()` directly, bypassing the arbitrary-query policy enforced by `executeComposed()`. Operators who set `$wgODBCAllowArbitraryQueries = false` expecting all ad-hoc SQL to be blocked found that admins could still run test queries. The admin page now checks the same global + per-source `allow_queries` flags as the parser function path and shows an error if both are disabled (P2-054).
- **§5.6 — Silent `data=` mapping truncation now logs a diagnostic** — Individual `data=` mapping pairs longer than 256 characters were silently dropped in `parseDataMappings()` with no indication to the template author. The variables simply would not be populated, producing confusing empty output. A `wfDebugLog('odbc', ...)` entry is now written for each skipped pair so operators can identify malformed templates (P2-057).
- **§5.5 — Deprecated `cols` attribute removed from admin textarea** — `SpecialODBCAdmin::showQueryForm()` set `cols="80"` on the SQL textarea, a deprecated HTML5 presentation attribute. Replaced with an inline CSS `width: 100%; max-width: 60em;` rule (P2-058).

### Improved

- **§3.7 — `extension.json` `callback` key replaced with `ExtensionRegistration` hook** — The legacy `callback` key was the pre-MW1.25 mechanism for one-time setup. `extension.json` now registers `ODBCHooks::onRegistration` under the `ExtensionRegistration` hook instead. Functionally equivalent; removes the deprecation (P2-054).
- **§3.8 — `getMainConfig()` cached in `ODBCQueryRunner` constructor** — `MediaWikiServices::getInstance()->getMainConfig()` was called independently in `executeComposed()`, `executePrepared()`, and `executeRawQuery()` on every invocation. A single `$this->mainConfig` private property is now set once in the constructor and reused across all three methods, reducing repeated service-locator calls on hot paths (P2-055).

---

## [1.2.0] - 2026-03-03

### Added

- **`$wgODBCSlowQueryThreshold` — slow-query logging** — New optional configuration key (float, default `0` = disabled). When set to a positive number (e.g. `2.0`), any query whose combined `odbc_execute` + row-fetch time exceeds the threshold is written to the `odbc-slow` log channel. See README Global Settings for setup. Query timing is now always included in the standard `odbc` debug channel (e.g. `— Returned 42 rows in 0.083s`).
- **`row=` parameter for `{{#odbc_value:}}`** — `{{#odbc_value:varName|default|2}}` now returns the value at a specific row position (1-indexed). Pass `row=last` (or the plain value `last`) to retrieve the final row. Out-of-range indices silently fall back to the default value. Backward-compatible: omitting the parameter still returns the first row (KI-019).

### Fixed

- **§5.2 — Parser function error returns now correctly marked as HTML** — All five error-path returns in `ODBCParserFunctions::odbcQuery()` (permission denied, query limit, no source, no from, MWException) were using `'noparse' => false`, which caused the `<span class="error odbc-error">…</span>` HTML to be re-processed by the wikitext parser. All error returns now use `[ formatError(...), 'noparse' => true, 'isHTML' => true ]`. No visible change for end users in normal cases; prevents potential output corruption when the error span contains characters that the parser would reinterpret as markup (P2-052).
- **KI-050 — `odbc-error-too-many-queries` message corrected** — The error previously advised "Use `{{#odbc_clear:}}` to separate logical sections," which has no effect on the query counter (`ODBCQueryCount`). The advice has been removed; the message now reads: "Reduce the number of `{{#odbc_query:}}` calls on this page, or raise `$wgODBCMaxQueriesPerPage` in `LocalSettings.php`." (P2-047)
- **KI-053 — `$wgODBCMaxConnections` described as "per source" in six locations** — The config key is a global cap across all ODBC sources combined, not a per-source limit. All six instances in `extension.json`, `README.md`, `CHANGELOG.md`, `UPGRADE.md`, and `SECURITY.md` corrected to "across all sources combined." (P2-050)

### Improved

- **KI-051 — `wiki/Architecture.md` corrected post-P2-024** — Four stale references updated after the v1.1.0 LRU eviction implementation: FIFO → LRU with `asort($lastUsed)` + `array_key_first()` description in two places; Design Limitations table updated to show P2-024 Done; cache backend corrected from `WANObjectCache` to `ObjectCache::getLocalClusterInstance()` (node-local, not shared across app servers). (P2-048)
- **KI-052 — `wiki/Known-Issues.md` KI-020 updated** — KI-020 now correctly shows "Partially fixed in v1.1.0 (P2-016)" with a mode-by-mode breakdown: `odbc_source` mode is fixed (caching + UTF-8 conversion); standalone External Data mode is still open. (P2-049)
- **P2-051 — `withOdbcWarnings()` DRY refactor completed** — `ODBCConnectionManager::withOdbcWarnings()` promoted from `private static` to `public static`. Five remaining raw `set_error_handler` / `restore_error_handler` closures replaced: three in `ODBCQueryRunner` (`executeRawQuery`, `getTableColumns`, `getTables`) and two in `EDConnectorOdbcGeneric` (`connect()`, `fetch()`). All now route through the shared handler.
- **KI-008 — `SELECT *` now logged when `data=` is omitted** — `ODBCParserFunctions::odbcQuery()` emits a `wfDebugLog('odbc', ...)` warning when no `data=` column mappings are specified and a `SELECT *` is about to be issued. Operators can use the `odbc` log channel to audit unintentional sensitive-column exposure.

---

## [1.1.0] - 2026-03-03

### Added

- **Progress OpenEdge support** — `ODBCQueryRunner::getRowLimitStyle()` (new public static method) returns `'top'` | `'first'` | `'limit'` based on driver name; `executeComposed()` and `EDConnectorOdbcGeneric::getQuery()` now use `SELECT FIRST n` for Progress drivers
- **Progress connection-string keys** — `buildConnectionString()` now maps `host` → `Host=` and `db` → `DB=` for Progress-style driver configs
- **`odbc-error-config-invalid` i18n message** — new localised message for early config validation errors (two parameters: source name, missing field list)
- **Per-page query limit (`$wgODBCMaxQueriesPerPage`)** — new configuration key (default `0` = no limit) caps the number of `{{#odbc_query:}}` calls per page render; prevents runaway templates from exhausting database connections (KI-018). Set to a positive integer to enable; earlier calls on the same page are unaffected when the limit is reached.

### Fixed

- **KI-023 — MS Access connection pooling** — `pingConnection()` now detects Access drivers and uses `SELECT 1 FROM MSysObjects WHERE 1=0` instead of bare `SELECT 1`
- **KI-024 — UNION blocks valid identifiers** — `UNION` moved from `$charPatterns` (substring match) to `$keywords` list (word-boundary regex); identifiers like `TRADE_UNION_ID` are no longer blocked
- **KI-025 — Connection string escaping** — all `buildConnectionString()` values are now passed through `escapeConnectionStringValue()`, which wraps values containing `;`, `{`, or `}` in `{...}` braces with internal `}` doubled, per the ODBC specification
- **KI-026 — `validateConfig()` now called** — `connect()` now retrieves config first and calls `validateConfig()` before any pool or connection operations; invalid configs surface as clear localised errors
- **KI-027 — ED connector driver inheritance** — `EDConnectorOdbcGeneric::__construct()` now copies `driver` from the referenced `$wgODBCSources` entry when `odbc_source` mode is used
- **KI-028 — Strict false check** — `ODBCHooks::registerExternalDataConnector()` guard changed from `=== false` to `!...` so any falsy value disables ED integration
- **KI-032 — Sanitizer word boundaries** — all keyword patterns in `sanitize()` changed from `/\bKEYWORD/i` to `/\bKEYWORD\b/i`; previously a block-listed keyword that happened to be a prefix of a longer token was incorrectly blocked
- **KI-033 — `odbc_setoption()` failures now logged** — a failed timeout-set call (not all ODBC drivers support per-statement timeouts) previously discarded the error silently; it now logs a `wfDebugLog('odbc', ...)` warning so operators can diagnose missing timeout behaviour
- **KI-040 — `validateConfig()` now accepts `host` for Progress OpenEdge** — driver-mode configurations using `host` instead of `server` were previously rejected by the config validator before reaching `buildConnectionString()`; both keys are now recognised
- **KI-034 — Connection pool now uses LRU eviction** — the pool previously evicted the oldest-opened connection (FIFO via `array_key_first()`); it now tracks the last-used timestamp for every source and evicts the least-recently-used connection on overflow, retaining the most-active sources in the pool (P2-024)
- **KI-049 — `sanitize()` keyword-boundary and whitespace evasion** — three evasion paths closed (P2-044):
  1. `XP_cmdshell` / `SP_executesql` were not blocked because the trailing `\b` after `_` (a PCRE word character) never fires between `_` and the following letter; `XP_` and `SP_` now use leading-only word boundaries so the entire `XP_*` / `SP_*` stored-procedure namespace is correctly blocked.
  2. `SLEEP()` (empty args) and `SLEEP(0.5)` (decimal delay) were not blocked because the trailing `\b` after `(` required the next character to be a word character; all keywords ending with `(` now omit the trailing boundary.
  3. Multi-space / tab evasion (`INTO  OUTFILE`, `LOAD\tDATA`) was possible because whitespace was not normalised before matching; a `preg_replace('/\s+/', ' ', ...)` step is now applied before all checks.

### Improved

- **Error handler DRY refactor** — repeated `set_error_handler` / `restore_error_handler` boilerplate in `ODBCConnectionManager` consolidated into a single private `withOdbcWarnings()` helper; all `odbc_connect()` calls now route through this helper (P2-008)
- **External Data connector gains caching and UTF-8 conversion** — when querying via an `odbc_source` reference, `EDConnectorOdbcGeneric::fetch()` now delegates to `ODBCQueryRunner::executeRawQuery()`, inheriting `$wgODBCCacheExpiry` result caching, UTF-8 encoding detection/conversion, and audit logging; standalone External Data connections also now apply UTF-8 conversion (P2-016)
- **`pingConnection()` now uses `withOdbcWarnings()` helper** — the connection liveness probe previously installed its own `set_error_handler` using `RuntimeException`; it now delegates to the shared `withOdbcWarnings()` / `MWException` pipeline for consistency (P2-046)
- **Special:ODBCAdmin source list shows Progress OpenEdge fields** — `showSourceList()` previously checked only `server` and `database` keys; Progress sources using `host` and `db` would show "N/A" in both columns; the display now falls back through `host` and `db` / `name` (P2-045)

### Deprecated

- `ODBCQueryRunner::requiresTopSyntax()` — deprecated since v1.1.0; use `getRowLimitStyle()` instead

### Documentation

- **README Complete Example warning** — added a prominent warning advising operators not to deploy `$wgODBCAllowArbitraryQueries = true` or grant `odbc-query` to all logged-in users in production (P2-014)
- **KNOWN_ISSUES.md encoding corrected** — all garbled mojibake sequences (`â€"` → `—`, `â†'` → `→`, `âœ…` → `✅`, etc.) in the resolved-issues section replaced with correct Unicode characters (P2-043)

---

## [1.0.3] - 2026-03-02

### Security

- Expanded SQL injection blocklist: added `#` (MySQL comment), `WAITFOR`, `SLEEP(`, `PG_SLEEP(`, `BENCHMARK(`, `DECLARE`, `UTL_FILE`, and `UTL_HTTP` to `ODBCQueryRunner::sanitize()` to close timing-attack and Oracle I/O injection vectors

### Fixed

- **Magic words are now case-insensitive** — all five magic word flags were set to `1` (case-sensitive) instead of `0` (case-insensitive); `{{#ODBC_QUERY:}}` now works correctly across all MediaWiki versions (KI-001)
- **Cache key collision fixed** — `implode(',', $params)` produced identical keys for `['a,b','c']` and `['a','b,c']`; replaced with `json_encode($params)` for collision-proof keys (KI-002)
- **Connection liveness check fixed** — `odbc_error() === ''` only tests error history, not actual connection state; replaced with a real `SELECT 1` probe via new `ODBCConnectionManager::pingConnection()` (KI-005)
- **Query timeout now applied at statement level** — the previous `odbc_setoption()` call was on the connection handle at connect-time, which most ODBC drivers ignore; timeout is now set on the statement handle immediately after `odbc_prepare()` (KI-006)
- **SQL Server / Access queries via External Data now use `TOP N` syntax** — `EDConnectorOdbcGeneric::getQuery()` was always emitting `LIMIT N`, which is invalid T-SQL; now calls `ODBCQueryRunner::requiresTopSyntax()` to select the correct syntax (KI-003)
- **`$wgODBCMaxRows` now enforced in the External Data connector** — `EDConnectorOdbcGeneric::fetch()` previously fetched an unlimited number of rows regardless of the global limit (KI-004)
- **Removed DSN-building duplication in ED connector** — `EDConnectorOdbcGeneric::setCredentials()` now delegates to `ODBCConnectionManager::buildConnectionString()` instead of maintaining its own copy of the logic
- **Removed stale connection-level liveness check from ED connector** — the `@odbc_error()` check after `ODBCConnectionManager::connect()` was redundant (the manager already pings); removed
- **`mergeResults()` O(n×m×p) → O(n×m)** — builds a lowercase-keyed lookup map per row once, eliminating the inner per-mapping column scan (KI-010)
- **`getTableColumns()` and `getTables()` now use `array_change_key_case()`** — eliminates driver-dependent `COLUMN_NAME` vs `column_name` fragility (KI-009)
- **Double column-loop in `executeComposed()` merged into single pass** (KI-010)
- Fixed indentation bug in `ODBCQueryRunner::executeRawQuery()` `wfDebugLog` call

### Added

- Added `$wgODBCMaxConnections` config key (default: `10`) — maximum simultaneous connections across all sources combined; replaces the previously hard-coded constant
- Added `ODBCConnectionManager::pingConnection()` — private static helper that validates a connection with a real `SELECT 1` query
- Added `SQL_HANDLE_STMT = 1` and `SQL_QUERY_TIMEOUT = 0` constants to `ODBCQueryRunner` for ODBC statement-level timeout
- `ODBCQueryRunner::requiresTopSyntax()` made `public` (was `private`) to enable use from the ED connector
- **Column browser enriched** — Special:ODBCAdmin now shows SQL type, size/precision, and nullability alongside column name; `getTableColumns()` now returns structured arrays instead of plain name strings

### Documentation

- **README.md**: Removed stray email address that was accidentally appended to a paragraph
- **UPGRADE.md**: Fixed incorrect maintenance script (`rebuildrecentchanges.php` → `rebuildall.php`)
- **UPGRADE.md**: Added v1.0.3 upgrade section
- **SECURITY.md**: Corrected false claim that GET requests require CSRF tokens; only the POST `runquery` action validates a token

---

## [1.0.2] - 2026-03-02

### Security

- **CRITICAL**: Fixed `UNION`/`UNION SELECT` not blocked by SQL sanitizer — added `UNION` to the `$charPatterns` blocklist in `ODBCQueryRunner::sanitize()`, preventing classic union-based SQL injection in composed queries
- **CRITICAL**: Fixed XSS vulnerability in Special:ODBCAdmin query results — database cell values were written via `Html::rawElement()` without escaping; now always use `Html::element()` which auto-escapes output
- Fixed wikitext injection in `{{#display_odbc_table:}}` — database values containing `|` or `}}` could inject extra template parameters or close the template call; values are now escaped via `escapeTemplateParam()` using `{{!}}` and HTML entities
- Fixed fake `{{{variable}}}` injection in `{{#for_odbc_table:}}` — database values containing `{{{` are now HTML-entity-escaped before substitution
- Fixed password exposure in `ODBCConnectionManager::testConnection()` — `odbc_errormsg()` was passed directly to the error message without first calling `sanitizeErrorMessage()`; credentials in the DSN could appear in browser output
- Removed CSRF tokens from admin GET URL parameters — tokens were embedded in `action=test` and `action=tables` links, causing them to appear in server logs, browser history, and HTTP Referer headers; read-only GET actions now require no token (standard MediaWiki practice)
- Added `INFORMATION_SCHEMA` and `SYS.` to the SQL keyword blocklist to prevent metadata enumeration via composed queries

### Fixed

- **CRITICAL**: Fixed `SpecialODBCAdmin::showColumns()` method missing entirely — its body was accidentally merged into the catch block of `showTables()`, causing a PHP parse error that prevented the entire extension from loading
- **CRITICAL**: Fixed `executeComposed()` always emitting both `TOP n` (SQL Server) and `LIMIT n` (MySQL/PostgreSQL) in the same query — every database rejected one of the two syntaxes; now uses driver-aware `requiresTopSyntax()` to emit only the correct syntax
- Fixed misleadingly named constants in `ODBCConnectionManager` — `SQL_ATTR_CONNECTION_TIMEOUT` (value 2) was actually the `SQL_HANDLE_DBC` handle type for `odbc_setoption()`; renamed to `SQL_HANDLE_DBC` to accurately document purpose
- Fixed `EDConnectorOdbcGeneric::disconnect()` connection leak — standalone External Data connections (not via `odbc_source`) were opened but never closed; `disconnect()` now explicitly calls `odbc_close()` for non-managed connections
- Fixed incorrect comment in `EDConnectorOdbcGeneric::disconnect()` claiming the code used `odbc_pconnect` (persistent connections); the code has always used `odbc_connect`
- Replaced deprecated `wfLogWarning()` (removed in MW 1.43) with `wfDebugLog( 'odbc', ... )` throughout `ODBCQueryRunner` and `ODBCConnectionManager`

### Added

- Added `ODBCQueryRunner::requiresTopSyntax()` — detects SQL Server, MS Access, and Sybase drivers from config to select `TOP n` vs `LIMIT n` row-limit syntax
- Added `MAX_CLAUSE_LENGTH = 1000` constant and per-clause length enforcement in `executeComposed()` to prevent resource exhaustion via excessively long WHERE/FROM/ORDER BY/etc. inputs
- Added `ODBCParserFunctions::escapeTemplateParam()` helper for safe wikitext template parameter value escaping
- Added `separator=` parameter to `{{#odbc_query:}}` — allows specifying an alternate delimiter for `parameters=` when parameter values themselves contain commas (e.g., `separator=|` for names like "Smith, John")

### Documentation

- **README**: Fixed incorrect maintenance script citation — `rebuildrecentchanges.php` has nothing to do with parser cache; corrected to `purgeParserCache.php` / null edit
- **README**: Removed non-existent `LICENSE` file from the File Structure listing
- **README**: Updated `parameters=` table entry to document the comma limitation and `separator=` workaround
- **README**: Updated File Structure section to accurately reflect all files present in the repository

## [1.0.1] - 2026-03-02

### Security

- **CRITICAL**: Fixed SQL injection vulnerability in column alias building - now validates all identifiers
- **CRITICAL**: Added strict identifier validation for column and table names (alphanumeric, underscore, dot only, max 128 chars)
- Added password sanitization in ODBC error messages to prevent credential exposure
- Enhanced control character stripping in SQL sanitizer (now removes all C0 control characters)
- Improved CSRF token validation consistency across GET and POST requests in admin interface
- Added query logging for security audit trails (logged to debug log)
- Enforced connection pool size limit (max 10 connections) to prevent resource exhaustion

### Fixed

- **CRITICAL**: Fixed incorrect LIMIT enforcement - now applies LIMIT in SQL (using TOP/LIMIT syntax) instead of only post-fetch
- Fixed race condition in connection pooling - now checks connection health before reusing cached connections
- Fixed resource leaks in error paths - added proper try-finally blocks for result resource cleanup
- Fixed encoding detection false positives - added more character sets and improved detection logic
- Fixed missing resource cleanup when exceptions occur during query execution
- Fixed case sensitivity in magic words (changed from 0 to 1) - now `{{#ODBC_QUERY:}}` works correctly
- Fixed inconsistent parameter naming - standardized on `source=` (still accepts legacy parameter for compatibility)
- Fixed data mapping length validation - now enforces limits to prevent memory exhaustion attacks

### Changed

- Improved error handling throughout - consistent use of MWException and proper cleanup
- Replaced hardcoded magic numbers with constants (SQL_ATTR_CONNECTION_TIMEOUT, SQL_ATTR_QUERY_TIMEOUT)
- Migrated Special:ODBCAdmin to use Html helper methods instead of raw HTML strings
- Enhanced connection manager to log timeout setting failures instead of silently suppressing
- Improved getTables() and getTableColumns() to log errors instead of silently returning empty arrays
- Updated encoding detection to include ISO-8859-15, Windows-1252, and ASCII

### Added

- Added `validateIdentifier()` method for strict SQL identifier validation
- Added `sanitizeErrorMessage()` to strip passwords from connection error messages
- Added connection health checks before returning cached connections
- Added connection pool size enforcement with automatic cleanup of oldest connection
- Added comprehensive query logging with source ID, SQL snippet, and row count
- Added query result caching with proper cleanup on errors
- Added new i18n messages: `odbc-error-identifier-too-long`, `odbc-error-invalid-identifier`, `odbc-error-invalid-token`, `odbc-admin-query-sql`
- Created SECURITY.md with comprehensive security documentation and best practices
- Added Html namespace import for improved HTML generation in Special:ODBCAdmin

### Documentation

- **README**: Added critical security warning about plain-text credentials in LocalSettings.php
- **README**: Documented connection string escaping requirements for special characters
- **README**: Clarified prepared statement array format options
- **README**: Expanded security considerations section with specific protection mechanisms
- **README**: Improved permission model documentation (odbc-query vs odbc-admin)
- **README**: Enhanced cache behavior explanation (per-query cache keys, no manual invalidation)
- **README**: Added warning to `allow_queries` documentation
- **extension.json**: Improved configuration descriptions with more detail
- **composer.json**: Enhanced External Data suggestion description

### Removed

- Removed unnecessary `getGroupName()` override in SpecialODBCAdmin (default 'other' is correct)

## [1.0.0] - Initial Release

### Added

- Initial release with ODBC database connectivity for MediaWiki
- Standalone parser functions: `{{#odbc_query:}}`, `{{#odbc_value:}}`, `{{#for_odbc_table:}}`, `{{#display_odbc_table:}}`, `{{#odbc_clear:}}`
- External Data extension integration (odbc_generic connector)
- Prepared statement support for secure parameterized queries
- SQL injection protection via keyword blocklist and pattern detection
- Special:ODBCAdmin interface for testing connections, browsing tables, and running test queries
- Permission system with `odbc-query` and `odbc-admin` rights
- Query result caching via MediaWiki object cache
- Support for System DSN, driver-based, and full connection string configurations
- Configurable query timeout (global and per-source)
- Per-source `allow_queries` override for composed queries
- Connection pooling for performance
- Automatic UTF-8 encoding conversion
- Comprehensive i18n support (English messages and documentation)

### Security Features (Initial)

- Dangerous SQL keyword blocking (DROP, DELETE, INSERT, UPDATE, EXEC, etc.)
- CSRF protection for admin interface
- SELECT-only enforcement in admin test queries
- Permission-based access control
- Error messages don't expose full SQL queries to users
- Query result row limits (default 1000, configurable)

---

## Version Numbering

- **Major version**: Incompatible API changes
- **Minor version**: New features, backward compatible
- **Patch version**: Bug fixes, backward compatible

## Links

- [Extension Page](https://www.mediawiki.org/wiki/Extension:ODBC)
- [Repository](https://github.com/nordstern-group/mediawiki-odbc)
- [Issue Tracker](https://github.com/nordstern-group/mediawiki-odbc/issues)
