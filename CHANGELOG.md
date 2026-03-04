# Changelog

All notable changes to the MediaWiki ODBC Extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0] - 2026-03-03

### Fixed

- **P2-113 — `forOdbcTable()` now applies full wikitext escaping to database values** — Previously, only `{{{` sequences were escaped, while `|` (pipe) and `}}` (template-close) in database values were passed through raw. When `#for_odbc_table` output was nested inside template calls, these characters could corrupt wikitext. Now uses the same `escapeTemplateParam()` helper as `displayOdbcTable()`, bringing both display functions to the same level of escaping safety. (KI-112)

### Improved

- **P3-003 — Unit test suite dramatically expanded (133 → 242 tests, +82%)** — Added 109 new tests covering previously untested sanitizer keywords (MERGE, REPLACE, CALL, REVOKE, BACKUP, RESTORE, PG\_SLEEP, OPENROWSET, OPENDATASOURCE, OPENQUERY, DBCC, SYS., UTL\_FILE, UTL\_HTTP, LOAD\_FILE), case-variation evasion, newline evasion, additional `validateIdentifier()` boundary cases, `withOdbcWarnings()` return-value and handler-restoration semantics, `sanitizeErrorMessage()` credential redaction, `mergeResults()` case-normalization and null-vs-missing-column distinction, `parseDataMappings()` length-boundary validation, `parseSimpleArgs()` edge cases, `escapeTemplateParam()` single-brace and wiki-markup preservation, and `formatError()` HTML-entity escaping. (P3-003)
- **P2-095 — `escapeTemplateParam()` pipe character garbling fixed** — Sequential `str_replace()` caused `|` → `{{!}}` → `{{!&#125;&#125;` because the `}}` inside `{{!}}` was caught by the second replacement. Replaced with `strtr()` for simultaneous replacement. Pipe characters in database values now render correctly via `{{#display_odbc_table:}}`. (KI-094)
- **P2-105 — Test assertion for pipe escaping corrected** — `testEscapeTemplateParamPipe()` previously asserted the garbled output `A{{!&#125;&#125;B` as expected. Updated to assert the correct output `A{{!}}B`. (KI-104)
- **P2-106 — `MWException` inheritance in PHPStan stubs corrected** — `stubs/MediaWikiStubs.php` declared `MWException extends RuntimeException` but MediaWiki core uses `extends Exception`. Fixed to match core. Stubs file also restructured with a proper `namespace {}` block to fix a PHP syntax error with mixed global/namespaced code. (KI-105)
- **P2-065 — `validateIdentifier()` regex now rejects trailing dots and over-deep segments** — The previous regex `/^[a-zA-Z_][a-zA-Z0-9_\.]*$/` accepted `table.`, `table..column`, and arbitrarily deep chains like `a.b.c.d.e`. The new regex `/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*){0,2}$/` permits only 1–3 properly-formed dot-separated segments (`table`, `schema.table`, `catalog.schema.table`) and rejects all malformed forms. (KI-065)
- **P2-064 — `executeComposed()` now rejects `having=` without `group by=`** — A HAVING clause without a GROUP BY clause is invalid on PostgreSQL and SQL Server and produces a cryptic driver-level error. The extension now validates this combination before building the SQL and returns a clear `odbc-error-having-without-groupby` i18n message. (KI-064)
- **P2-066 — `withOdbcWarnings()` now filters to ODBC-originated warnings only** — The previous handler converted *all* PHP `E_WARNING` errors to `MWException`, including warnings from filesystem, network, or other PHP code running inside the callback. The handler now checks the message string for ODBC driver signatures (`odbc`, `[unixODBC]`, `[Microsoft]`, `[IBM]`, `[Oracle]`) and passes non-ODBC warnings to the next registered handler instead. (KI-066)
- **P2-067 — `EDConnectorOdbcGeneric::from()` now validates table alias keys** — Alias keys were interpolated directly into the SQL `AS alias` fragment without calling `validateIdentifier()`. All non-numeric alias keys are now validated before use, consistent with the identifier-validation invariant applied throughout `executeComposed()`. `validateIdentifier()` promoted from `private static` to `public static` to support this. (KI-067)
- **P2-073 — `extension.json` `ODBCSources` description removed phantom `options` key** — The config description listed `options (optional)` as a valid per-source key. This key does not exist anywhere in the codebase and had no effect when set. Description replaced with an accurate, complete list of all recognised keys. (KI-072)
- **P2-074 — Slow-query timer now measures full execute + fetch time** — `$queryStart = microtime(true)` was placed *after* `odbc_execute()` in `executeRawQuery()`, measuring only row-fetch time. The timer is now started immediately before `odbc_execute()`, so `$wgODBCSlowQueryThreshold` correctly identifies slow DB-side execution — not just slow fetch loops. (KI-073)
- **P2-075 — External Data standalone fetch() now applies query timeout** — The standalone path in `EDConnectorOdbcGeneric::fetch()` used `odbc_exec()` directly, bypassing `$wgODBCQueryTimeout` and per-source `timeout=` entirely. Replaced with `odbc_prepare()` / `odbc_setoption()` / `odbc_execute()`, matching the pattern used by `ODBCQueryRunner::executeRawQuery()`. (KI-074)
- **P2-076 — `requiresTopSyntax()` now emits `wfDeprecated()`** — The method was annotated `@deprecated since 1.1.0` but never called `wfDeprecated()`, so callers received no runtime warning. Added `wfDeprecated( __METHOD__, '1.1.0', 'ODBC' )` as the first statement. Use `ODBCQueryRunner::getRowLimitStyle()` instead. (KI-075)
- **P2-089 — `sanitize()` blocklist extended with `CAST(` and `CONVERT(`** — These functions are commonly used to encode blocked SQL keywords as hex literals (e.g. `CAST(0x44524F50 AS CHAR)` → `DROP`), bypassing simple substring filters. Adding them to `$charPatterns` closes a defence-in-depth gap noted in prior reviews. Operators who use `CONVERT()` in legitimate read-only composed queries should switch to the prepared-statement path. (KI-088)
- **P2-090 — `withOdbcWarnings()` vendor filter extended to cover Progress, OpenEdge, DataDirect, and Easysoft drivers** — The ODBC-origin filter added in P2-066 recognised `odbc`, `[unixODBC]`, `[Microsoft]`, `[IBM]`, and `[Oracle]`. Driver warning messages from Progress OpenEdge (`[Progress]`, `[OpenEdge]`), Progress DataDirect (`[DataDirect]`), and Easysoft ODBC Bridge (`[Easysoft]`) are now also recognised. The multi-value check was refactored from chained `||` expressions to a loop over a `$odbcVendorPrefixes` array for readability and easier extension. (KI-089)
- **Duplicate docblock removed from `odbcValue()`** — A stale pre-KI-019 docblock was left above the current comprehensive docblock when the `row=` parameter was added in v1.2.0. The orphaned block is now removed; only the correct, current docblock remains.

### Improved

- **P2-069 — Encoding detection reduced from O(rows × columns) to O(1) per query** — `executeRawQuery()` previously called `mb_detect_encoding()` for every string cell in every result row. Encoding is now sampled once from the first non-empty string value in the first row, then applied uniformly to all subsequent rows. A per-source `charset` key in `$wgODBCSources` (e.g. `'charset' => 'ISO-8859-1'`) bypasses detection entirely for sources with a known fixed encoding. The same optimization is applied to the standalone External Data `fetch()` path in `EDConnectorOdbcGeneric`. (KI-069)
- **P2-071 — `forOdbcTable()` template substitution uses `strtr()` instead of `str_replace()` loop** — The previous implementation called `str_replace()` once per variable per row, resulting in O(rows × variables) full-string scans. The new implementation builds a replacement map per row and calls `strtr()` once, performing a single linear pass regardless of variable count. (§5.9)
- **P2-068 — `null_value=` parameter added to `{{#odbc_query:}}`** — Database NULL values were previously coerced to empty string `''` with no way to distinguish them from actual empty values. A new `null_value=` parameter (default `''` for full backward compatibility) is stored instead of `''` when a column contains NULL. Example: `null_value=N/A` gives NULL cells a visible, distinct representation in templates. (KI-068)
- **P2-091 — `display_odbc_table` registration intent documented** — An inline comment was added to `ODBCHooks::onParserFirstCallInit()` explaining why `display_odbc_table` is registered without `SFH_OBJECT_ARGS`. The omission is intentional (pre-expanded string arguments are sufficient for its current template-name + variable-prefix contract); the comment prevents future contributors from treating it as an oversight. (KI-090)
- **GitHub Actions CI pipeline added** — Three jobs run automatically on every push and pull request targeting `main`: PHP syntax lint on all supported PHP versions (7.4, 8.0, 8.1, 8.2, 8.3); PHP_CodeSniffer with the MediaWiki coding standard (`composer phpcs`); and a release-readiness check (only fires on version-tag pushes, blocking a release tag if `CHANGELOG.md` still contains `[Unreleased]`). See `.github/workflows/ci.yml`.
- **PHP 8.4 added to CI lint matrix** — The PHP syntax lint job now covers PHP 8.4 in addition to 7.4–8.3, ensuring deprecations and syntax changes introduced in PHP 8.4 are caught immediately on every push.
- **PHPStan level 3 static analysis added** — `includes/` and `ODBCMagic.php` are now analysed by PHPStan on every push and pull request via a new `phpstan` CI job (`composer phpstan`). A comprehensive MediaWiki type-stub file (`stubs/MediaWikiStubs.php`) provides PHPStan with all required class/function signatures without needing a full MediaWiki installation. Configured in `phpstan.neon`. Target: raise to level 5 in v2.0.0 alongside the PHPUnit test suite (P3-003).
- **P2-093 — CI Composer cache key now hashes `composer.lock`** — The previous cache key hashed `composer.json`, meaning the cached `vendor/` directory could become stale when transitive dependency versions changed without a change to `composer.json`. The cache key now uses `hashFiles('composer.lock', 'composer.json')`, prioritising the lockfile for stable, reproducible cache keys. `.gitignore` updated to stop excluding `composer.lock` so the lockfile can be committed once generated. **Action required before first release:** run `composer install` locally on PHP 8.1+ and commit the generated `composer.lock`. (KI-092)

### Documentation

- **P2-070 — `$wgODBCMaxConnections` clarified as per-PHP-worker-process** — The `extension.json` config description now explicitly states that the limit applies per worker process, and that in PHP-FPM deployments the total system-wide connection count equals the configured value multiplied by the number of active worker processes. (KI-070)
- **P2-063 — CHANGELOG v1.4.0 dated** — The v1.4.0 entry was still marked `[Unreleased]` for the fourth consecutive release. Dated `2026-03-03`. (KI-063)
- **P2-077 — `UPGRADE.md` v1.5.0 section added** — The upgrade guide had no entry for v1.5.0 (introducing `null_value=`, `charset=`, tightened identifier validation, `HAVING` guard, and the new code fixes). Full upgrade section now present. (KI-076)
- **P2-078 — `SECURITY.md` v1.5.0 release entry dated** — Entry was still marked `(Unreleased)`. Dated `2026-03-03`. (KI-077)
- **P2-079 — `wiki/Architecture.md` ODBCHooks description corrected** — The component description still referenced the deprecated `callback` key in `extension.json`. Updated to `ExtensionRegistration` hook. Stale strikethrough resolved-issue rows removed from the Design Limitations table. (KI-071, KI-078)
- **P2-080 — `wiki/Known-Issues.md` fully updated to v1.5.0** — Page was frozen at v1.1.0: KI-019 was shown as open when it was fixed in v1.2.0; no v1.2.0–v1.5.0 fixes were reflected; KI-020 was not updated for the v1.5.0 timeout fix. Completely rewritten to reflect current open issues (KI-008, KI-020 partial) and a full resolved-by-version summary table. (KI-079)
- **P2-081 — `wiki/Security.md` release history completed to v1.5.0** — History table stopped at v1.1.0; v1.2.0–v1.5.0 entries added. Fixed double-pipe `||` formatting bug that merged the v1.0.3 and v1.1.0 table rows. Removed resolved KI-033 from the Known Limitations table; updated limitations to reflect current state. (KI-080, KI-085)
- **P2-082 — `wiki/Parser-Functions.md` `null_value=` parameter added** — The new v1.5.0 parameter was absent from the `#odbc_query` parameters table. Added with description, default, and example. Also replaced invalid `{{#...}}` inline comment syntax with `<!--...-->` in the `#odbc_value` examples. (KI-081, KI-084)
- **P2-083 — `wiki/Configuration.md` missing keys added** — `charset=` (v1.5.0), `host=` (Progress OpenEdge, v1.1.0), and `db=` (Progress OpenEdge, v1.1.0) were absent from the Connection Options Reference table despite being fully supported. All three rows added. (KI-082, KI-083)
- **README Per-Source Options table updated** — The `host` (Progress OpenEdge alternative to `server`) and `db` (Progress OpenEdge alternative to `database`) keys were absent from the Per-Source Options table in `README.md` even though they were documented in `wiki/Configuration.md`. Both rows added with description and usage note.
- **P2-087 — `wiki/Installation.md` Step 4 verification instruction updated** — The verification step instructed operators to confirm "version 1.0.3" in Special:Version — stale since v1.0.3. Replaced with an instruction to match the version shown against `extension.json` in the installation directory; eliminates the need to update this step on every release. (KI-086)
- **P2-088 — `wiki/Troubleshooting.md` UNION section and MAX_CONNECTIONS reference corrected** — The "Illegal SQL pattern 'UNION'" troubleshooting section presented KI-024 as a current open bug requiring a workaround (renaming database columns), when KI-024 was fixed in v1.1.0. Section rewritten to clearly mark the fix and explain when the error is legitimate. The error message table entry for `UNION` also updated. The Admin Interface `MAX_CONNECTIONS` note incorrectly cited "v1.0.3 and earlier" when the constant was replaced in v1.0.3 — corrected to "v1.0.2 and earlier". (KI-087)
- **P2-092 — `composer.json` EOL package version ranges dropped** — `"composer/installers": "^1.0 || ^2.0"` narrowed to `"^2.0"` (1.x EOL). `"phpunit/phpunit": "^9.0 || ^10.0"` updated to `"^10.0 || ^11.0"` (PHPUnit 9.x EOL February 2024, PHP 8.1+ only needs 10/11). (KI-091)
- **P2-094 — `SECURITY.md` v1.0.2 and v1.0.1 release history dates corrected** — Both entries used the imprecise "Month YYYY" format ("March 2026") inconsistent with all other entries' `YYYY-MM-DD` format. Corrected to `2026-03-02` (v1.0.2) and `2026-03-01` (v1.0.1). (KI-093)
- **`i18n/qqq.json` — `odbc-error-config-invalid` translator note added** — The `odbc-error-config-invalid` message was the only message key in `en.json` without a corresponding translator documentation entry in `qqq.json`. Entry added with full parameter descriptions for `$1` (source ID) and `$2` (comma-separated list of missing configuration keys).
- **P2-104 — PHPUnit added to CI workflow** — A new `phpunit` CI job runs `composer test` on every push and pull request, catching regressions in tested methods. Also added a non-blocking `changelog-check` job that warns when CHANGELOG contains `[Unreleased]` for the current `extension.json` version on pushes to `main`. (KI-103, KI-095)
- **P2-096 — CHANGELOG v1.5.0 dated** — The v1.5.0 entry was still marked `[Unreleased]` (fifth consecutive occurrence). Dated `2026-03-03`. (KI-095)
- **CI pipeline fully green** — Resolved all four CI failure categories: PHPCS exit-code-on-warnings (KI-106), PHPStan type/visibility errors (KI-107), PHP 8.1 incompatibility in test matrix (KI-108), and duplicate `phpunit` job (KI-109). Added `extensions: mbstring` to PHPCS and PHPStan CI jobs. First successful CI run: commit `888c8fa`.
- **P2-097 — `wiki/Special-ODBCAdmin.md` and `wiki/Security.md` bypass claims fixed** — Three locations claimed the admin test query bypasses `$wgODBCAllowArbitraryQueries`. This was fixed in v1.3.0; documentation updated to reflect current enforcement. (KI-096)
- **P2-098 — `wiki/Home.md` and `wiki/_Footer.md` version updated to 1.5.0** — Both displayed 1.0.3 since the initial release. (KI-097)
- **P2-099 — `wiki/Contributing.md` stale claims corrected** — Removed false "no require-dev" and "no automated tests" claims. Added test-running instructions. Updated "Areas Needing Contribution" to reflect currently-open items. (KI-098)
- **P2-100 — `wiki/Architecture.md` Design Limitations table updated** — Corrected stale "all-static classes" row (only `ODBCConnectionManager` is static since v1.3.0) and "no unit tests" row (3 test files exist since v1.5.0). (KI-099)
- **P2-101 — `wiki/External-Data-Integration.md` 3 stale warnings corrected** — KI-027 workaround removed (fixed in v1.1.0); feature parity table updated for caching and UTF-8 via `odbc_source`; KI-028 warning corrected (any falsy value now works since v1.1.0). (KI-100)
- **P2-102 — `wiki/Security.md` blocklist table updated** — Added `CAST(` and `CONVERT(` patterns (present in code since P2-089 but missing from documentation). (KI-101)
- **P2-103 — `wiki/Parser-Functions.md` worked example variable fixed** — Replaced `first_count` (non-existent variable) with `FirstName` from the query's mapped columns. (KI-102)
- **P2-111 — `wiki/Parser-Functions.md` worked example case mismatch fixed** — The worked example used mixed-case `{{{FirstName}}}` / `{{{LastName}}}` in templates and `#for_odbc_table`, but `mergeResults()` normalizes all variable names to lowercase. Copying the example verbatim produced broken output. All variable references changed to lowercase (`{{{firstname}}}`, `{{{lastname}}}`, etc.) and a note added explaining that template parameters must be lowercase. (KI-110)
- **P2-112 — `wiki/Configuration.md` stale KI-028 warning removed** — A warning block stated "only the exact boolean `false` disables integration" — fixed in code since v1.1.0 (P2-022). The equivalent warning was corrected in `wiki/External-Data-Integration.md` (P2-101) but this instance was missed. Replaced with a note confirming any falsy value works. (KI-111)
- **P2-114 — `wiki/Special-ODBCAdmin.md` metadata timeout limitation documented** — Added a note to the Browse Tables section warning that ODBC metadata operations do not support per-statement timeouts and may hang if the source is unresponsive. Recommends using Test Connection first. (KI-113)
- **P2-115 — `wiki/Parser-Functions.md` `data=` case normalization documented** — The docs stated that DB column names are case-insensitive but did not mention that local variable names are also lowercased. Added explicit note: "Both the local variable name and the DB column name are normalized to lowercase internally." (KI-114)

---

## [1.4.0] - 2026-03-03

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
