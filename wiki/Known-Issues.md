# Known Issues

This page summarises the currently open issues in the ODBC extension. For the complete historical issue log including resolved items, see [KNOWN_ISSUES.md](https://github.com/slickdexic/ODBC/blob/main/KNOWN_ISSUES.md) in the repository.

Workarounds are provided where available. Issues marked with a fix version have a planned resolution in the improvement plan.

---

## Open Bugs

### KI-008 — `SELECT *` Used as Default When `data=` Omits Columns

**Severity:** Minor  
**File:** `includes/ODBCQueryRunner.php`  
**Planned fix:** v1.1.0 (P2-009 related)

When `data=` is omitted entirely, the extension falls back to `SELECT *` rather than restricting the query to only the columns needed. This can fetch significantly more data than necessary from the database.

**Workaround:** Explicitly map all columns you need in `data=`.

---

### KI-018 — No Per-Page Query Count Limit

**Severity:** Medium  
**File:** `includes/ODBCParserFunctions.php`  
**Status: Fixed in v1.1.0** (P2-007)

~~A single wiki page can call `{{#odbc_query:}}` an unlimited number of times. There is no `$wgODBCMaxQueriesPerPage` setting. A page with `{{#odbc_query:}}` in a widely-used template can generate an unexpectedly large number of database queries.~~

A new `$wgODBCMaxQueriesPerPage` configuration key (default: `0` = no limit) is now available. Set it in `LocalSettings.php` to cap the number of `{{#odbc_query:}}` calls per page render. When the limit is reached, subsequent calls return a localised error message.

---

### KI-019 — `#odbc_value` Cannot Access Non-First Rows

**Severity:** Minor  
**File:** `includes/ODBCParserFunctions.php`  
**Planned fix:** v2.0.0

`{{#odbc_value: varname }}` always returns the value from the first result row. There is no syntax to access `varname[1]`, `varname[2]`, etc. Use `#for_odbc_table` to iterate all rows.

---

### KI-020 — External Data Connector Has No Caching or UTF-8 Conversion

**Severity:** Minor  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`  
**Status:** ⚠ Partially fixed in v1.1.0 (P2-016)

When queries are made through External Data's parser functions (`#get_db_data`), results are not cached regardless of `$wgODBCCacheExpiry`, and non-UTF-8 encoded data from the database is not automatically converted. The native ODBC parser functions (`#odbc_query`) do not have this limitation.

**What was fixed in v1.1.0:** When a `$wgExternalDataSources` entry references a `$wgODBCSources` source via the `odbc_source` key, queries are now routed through `ODBCQueryRunner::executeRawQuery()`. This means `odbc_source`-mode connections gain `$wgODBCCacheExpiry` result caching, UTF-8 encoding conversion, and audit logging — on par with native parser functions.

**What remains open:** Standalone External Data connections (those with direct ODBC credentials in `$wgExternalDataSources`, no `odbc_source` key) still do not apply `$wgODBCCacheExpiry` caching. UTF-8 conversion is applied row-by-row in standalone mode (added v1.1.0).

**Workaround:** Use the native ODBC parser functions where possible, or configure sources via `$wgODBCSources` and reference them with `odbc_source` in `$wgExternalDataSources`.

---

### KI-023 — `pingConnection()` Fails on MS Access

**Severity:** High  
**File:** `includes/ODBCConnectionManager.php`  
**Status: Fixed in v1.1.0** (P2-017)

~~The connection pool liveness probe executes `SELECT 1`, which MS Access (Jet/ACE) does not support without a `FROM` clause. Every cached connection to an MS Access source fails the ping, is discarded, and a new connection is opened on the next query. This defeats connection pooling for Access.~~

The extension now detects Access drivers and uses `SELECT 1 FROM MSysObjects WHERE 1=0` as its liveness probe. Connection pooling works correctly for MS Access.

---

### KI-024 — `UNION` Sanitizer Blocks Valid Identifiers

**Severity:** Moderate  
**File:** `includes/ODBCQueryRunner.php`, `sanitize()`  
**Status: Fixed in v1.1.0** (P2-018)

~~The word `UNION` is matched as a substring in the SQL sanitizer. Any table name, column name, or value containing "union" (e.g., `TRADE_UNION`, `LABOUR_UNION_ID`) is incorrectly blocked.~~

`UNION` is now matched with strict word boundaries (`\bUNION\b`), so identifiers merely containing "union" are no longer blocked.

---

### KI-025 — `buildConnectionString()` Does Not Escape Special Characters in Values

**Severity:** Moderate  
**File:** `includes/ODBCConnectionManager.php`  
**Status: Fixed in v1.1.0** (P2-019)

~~If a `$wgODBCSources` configuration value contains a semicolon (`;`), brace (`{`, `}`), these characters are not escaped in the constructed connection string.~~

All connection-string values are now wrapped in `{...}` braces with internal `}` doubled when they contain `;`, `{`, or `}`, per the ODBC connection string specification.

---

### KI-026 — `validateConfig()` Is Dead Code

**Severity:** Minor  
**File:** `includes/ODBCConnectionManager.php`  
**Status: Fixed in v1.1.0** (P2-020)

~~`ODBCConnectionManager::validateConfig()` exists and is documented, but is never called.~~

`validateConfig()` is now called from `connect()` before any connection attempt. Invalid configurations produce a clear localised error message (`odbc-error-config-invalid`) rather than an opaque ODBC driver error.

---

### KI-027 — ED Connector `odbc_source` Mode Always Uses LIMIT for SQL Server

**Severity:** High  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`  
**Status: Fixed in v1.1.0** (P2-021)

~~When using External Data's `odbc_source` reference mode, the driver name is not inherited from the referenced source. The SQL engine therefore cannot detect SQL Server and uses `LIMIT` syntax, causing a T-SQL syntax error.~~

The ED connector now automatically inherits the `driver` value from the referenced `$wgODBCSources` entry. Correct `TOP`/`FIRST`/`LIMIT` syntax is applied regardless of which mode is used.

---

### KI-028 — `$wgODBCExternalDataIntegration = 0` Does Not Disable Integration

**Severity:** Minor  
**File:** `includes/ODBCHooks.php`  
**Status: Fixed in v1.1.0** (P2-022)

~~The disable check uses `=== false` (strict identity). PHP integer `0`, `null`, and empty string `''` are falsy but not `=== false`. Setting `$wgODBCExternalDataIntegration = 0` will not disable integration.~~

The check now uses `!$wgODBCExternalDataIntegration`, so any falsy value (`false`, `0`, `null`, `''`) correctly disables the External Data integration.

---

## Open Documentation Issues

No open documentation issues.

---

## Resolved Issues

18 issues were resolved across v1.0.1, v1.0.2, and v1.0.3, and a further 10 were fixed in v1.1.0 (KI-018, KI-023, KI-024, KI-025, KI-026, KI-027, KI-028, KI-029, KI-031, KI-040) — see the sections above for code-bug details.

---

*Last updated: v1.1.0, 2026-03-03 — KI-018, KI-023, KI-024, KI-025, KI-026, KI-027, KI-028, KI-029, KI-031, KI-040 marked fixed; documentation issues KI-030 through KI-046 resolved*
