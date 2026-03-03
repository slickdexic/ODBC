# Known Issues

This page summarises the currently open issues in the ODBC extension. For the complete historical issue log including resolved items, see [KNOWN_ISSUES.md](https://github.com/slickdexic/ODBC/blob/main/KNOWN_ISSUES.md) in the repository.

Workarounds are provided where available.

---

## Open Bugs

### KI-008 — `SELECT *` Used as Default When `data=` Omits Columns

**Severity:** Minor  
**File:** `includes/ODBCQueryRunner.php`

When `data=` is omitted entirely from `{{#odbc_query:}}`, the extension falls back to `SELECT *` rather than restricting the query to only the columns needed. This can fetch significantly more data than necessary from the database and may unintentionally expose sensitive columns in templates.

A debug log warning is emitted to the `odbc` channel whenever this occurs (added v1.2.0), so operators can audit unintentional `SELECT *` usage.

**Workaround:** Explicitly map all required columns using `data=`, e.g. `data=name=FullName,dept=Department`.

---

### KI-020 — External Data Standalone Mode: No Result Caching

**Severity:** Minor  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`  
**Status:** ⚠ Partially fixed — see details below

When queries are made through External Data's parser functions (`#get_db_data`) using **standalone mode** (direct ODBC credentials in `$wgExternalDataSources` with no `odbc_source` key), query results are not cached regardless of `$wgODBCCacheExpiry`.

**What has been fixed over successive releases:**

- **v1.1.0 (P2-016):** Sources referenced via `odbc_source` now route through `ODBCQueryRunner::executeRawQuery()` and gain full caching, UTF-8 conversion, and audit logging — on par with native parser functions.
- **v1.1.0:** UTF-8 encoding conversion is now applied row-by-row in standalone mode.
- **v1.5.0 (KI-074):** Standalone mode now applies `$wgODBCQueryTimeout` (or per-source `timeout=`) via `odbc_prepare()` / `odbc_setoption()` / `odbc_execute()`. Previously `odbc_exec()` was used with no timeout.

**What remains open:** Standalone External Data connections (those with direct ODBC credentials in `$wgExternalDataSources`, no `odbc_source` key) still do not apply `$wgODBCCacheExpiry` caching.

**Workaround:** Configure sources via `$wgODBCSources` and reference them with the `odbc_source` key in `$wgExternalDataSources`. This mode gains caching, UTF-8 conversion, timeout enforcement, and audit logging.

```php
// Instead of standalone credentials in $wgExternalDataSources:
$wgODBCSources['my-source'] = [ 'driver' => '...', 'server' => '...', /* ... */ ];
$wgExternalDataSources['my-source'] = [
    'type'        => 'odbc_generic',
    'odbc_source' => 'my-source',   // references $wgODBCSources entry — gains caching
];
```

---

## Open Documentation Issues

None.

---

## Resolved Issues (Summary by Version)

| Version | Issues resolved |
|---------|----------------|
| v1.0.1 | SQL identifier validation; CSRF improvements; credential sanitization in error messages |
| v1.0.2 | XSS in `#display_odbc_table`; wikitext injection in `escapeTemplateParam` |
| v1.0.3 | Magic words case-insensitive; `TOP N` for SQL Server via External Data; `$wgODBCMaxRows` enforced in ED connector; connection liveness probe; cache key collision fix; blocklist expansion |
| v1.1.0 | KI-018 (per-page query limit `$wgODBCMaxQueriesPerPage`); KI-023 (MS Access ping uses `MSysObjects`); KI-024 (UNION word-boundary false positive); KI-025 (connection string special-char escaping); KI-026 (`validateConfig()` dead code); KI-027 (ED driver inheritance); KI-028 (ED integration falsy disable); KI-032 (sanitizer word boundaries); KI-033 (timeout failures logged); KI-040 (Progress `host` key in `validateConfig`) |
| v1.2.0 | KI-019 (`row=` parameter for `#odbc_value` — non-first-row access); KI-050 (too-many-queries error message); KI-053 (`$wgODBCMaxConnections` described as per-worker-process) |
| v1.3.0 | Admin test-query now respects `$wgODBCAllowArbitraryQueries` (KI-054); silent `data=` truncation now logged (KI-055); deprecated `cols` attribute removed from admin textarea (KI-056) |
| v1.4.0 | `EDConnectorOdbcGeneric` fatal-error guard when External Data absent (KI-059); log prefix format standardised (KI-061) |
| v1.5.0 | `executeComposed()` rejects `HAVING` without `GROUP BY` (KI-064); `validateIdentifier()` regex tightened (KI-065); `withOdbcWarnings()` filters ODBC-only warnings (KI-066); ED alias validation (KI-067); `null_value=` parameter (KI-068); encoding detection O(1) per query (KI-069); slow-query timer now measures full execute+fetch time (KI-073); ED standalone timeout enforcement (KI-074); `requiresTopSyntax()` emits `wfDeprecated()` (KI-075) |

For full details on every issue — including root-cause analysis and fix descriptions — see [KNOWN_ISSUES.md](https://github.com/slickdexic/ODBC/blob/main/KNOWN_ISSUES.md).

---

*Last updated: v1.5.0, 2026-03-03*

---



