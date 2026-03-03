# Improvement Plan: MediaWiki ODBC Extension

**Version targeting:** 1.0.3 (hotfixes — ALL COMPLETE), 1.1.0 (features/architecture), 1.2.0 (quality/DRY/features — ALL COMPLETE), 2.0.0 (breaking refactor)  
**Last updated:** 2026-03-06 (v1.2.0 pass — P2-047/048/049/050/051 complete; KI-008 partially addressed; KI-019 fixed)  
**Based on:** codebase_review.md and KNOWN_ISSUES.md findings

> **Review Note (2026-03-05, v1.1.0 final pass):** A fifth review pass verified all documentation files against the shipped code. Confirmed fixes for KI-035/036/037 (Architecture.md errors, wiki KI-008 description, README magic word version), KI-040 (validateConfig Progress host key), KI-041–048 (documentation/presentation errors), and P2-027 (README MAX_CONNECTIONS). Four new documentation/code issues were found: KI-050 — `odbc-error-too-many-queries` message incorrectly recommends `{{#odbc_clear:}}` which has no effect on the query counter; KI-051 — `wiki/Architecture.md` contains four stale references after P2-024 (LRU eviction) was implemented; KI-052 — `wiki/Known-Issues.md` KI-020 not updated for partial v1.1.0 fix; KI-053 — `$wgODBCMaxConnections` described as "per source" in six locations when it is a global pool limit. One code quality finding: `withOdbcWarnings()` is `private static`, so five raw `set_error_handler` closures in ODBCQueryRunner and EDConnectorOdbcGeneric remain unrefactored (P2-051). New plan items P2-047 through P2-051 added.

> **Review Note (2026-03-03, v1.1.0 post-release):** A fourth review pass was conducted against all PHP source files. Three new issues were found and immediately fixed: KI-049 — `sanitize()` did not block `XP_cmdshell`/`SP_executesql` (trailing `\b` after `_` never fires) nor `SLEEP()`/`BENCHMARK()` with non-integer args, and multi-space whitespace evasion was possible for `INTO  OUTFILE`; KI-045-admin — `SpecialODBCAdmin::showSourceList()` displays 'N/A' for Progress sources using `host`/`db` keys; `pingConnection()` used its own `RuntimeException` handler instead of the shared `withOdbcWarnings()` helper. New plan items P2-044/P2-045/P2-046 added and marked Done.

> **Review Note (2026-03-02):** A full re-review of the shipped v1.0.3 code was conducted. All Phase 1 items (P1-001 through P1-009) are confirmed completed. Many Phase 2 items are also complete. New issues KI-023 through KI-031 were identified and corresponding new plan items P2-017 through P2-027 have been appended. Items that are confirmed complete in the shipped code are marked **✅ DONE (v1.0.3)**.

> **Review Note (2026-03-03, v1.1.0 re-review):** A third review pass was conducted against the shipped v1.1.0 code. v1.1.0 resolved 7 issues (KI-023 through KI-028, KI-032) plus KI-039. One new code regression was found: KI-040 — `validateConfig()` does not accept `host` as an alternative to `server` for Progress OpenEdge driver configs (introduced alongside the v1.1.0 Progress support in `buildConnectionString()`). Seven new documentation errors were identified: KI-041 (CHANGELOG v1.1.0 "Unreleased"), KI-042 (Architecture.md buildConnectionString description wrong), KI-043 (wiki/Security.md stale KI-024 note), KI-044 (SECURITY.md obsolete row-limit description), KI-045 (UPGRADE.md false magic word v1.0.1 claim), KI-046 (Parser-Functions.md data= marked Required), KI-047 (KNOWN_ISSUES.md footer count wrong). One presentation issue was identified: KI-048 (KNOWN_ISSUES.md mojibake). P2-027 was discovered to have been incorrectly marked Done — the README MAX_CONNECTIONS fix was never applied to the file. New plan items P2-035 through P2-043 have been added.

---

## Overview

The improvement plan is organised into three release phases:

- **v1.0.3 — Immediate Hotfixes:** Critical correctness and documentation bugs that must be fixed before any further distribution.
- **v1.1.0 — Quality & Feature Release:** Non-breaking improvements to security, performance, code quality, and admin UX.
- **v2.0.0 — Architectural Refactor:** Breaking changes that align the extension with modern MediaWiki and PHP standards.

---

## Phase 1: v1.0.3 — Immediate Hotfixes ✅ ALL COMPLETED

> All nine Phase 1 items are confirmed complete in the shipped v1.0.3 code. This section is retained for historical reference.

These items were either actively harmful to production users or factual errors that mislead users.

---

### P1-001 — Fix Magic Word Case Sensitivity Flag (KI-001) ✅ DONE (v1.0.3)

**Priority:** CRITICAL  
**Effort:** Trivial (1 line per magic word)  
**File:** `ODBCMagic.php`

Change all magic word case flags from `1` (case-sensitive) to `0` (case-insensitive):

```php
// BEFORE (broken — case-sensitive, only lowercase works):
'odbc_query' => [ 1, 'odbc_query' ],

// AFTER (correct — case-insensitive, any case works):
'odbc_query' => [ 0, 'odbc_query' ],
```

Apply to all five magic words. Update CHANGELOG with the correction, explicitly acknowledging that the v1.0.1 CHANGELOG entry was wrong.

---

### P1-002 — Fix Cache Key Collision for Parameterised Queries (KI-002) ✅ DONE (v1.0.3)

**Priority:** CRITICAL  
**Effort:** Trivial (1 line)  
**File:** `includes/ODBCQueryRunner.php`, `executeRawQuery()`

Replace the flawed parameter serialisation in the cache key:

```php
// BEFORE (broken — ['a,b','c'] and ['a','b,c'] produce same key):
md5( $sql . '|' . implode( ',', $params ) . '|' . $maxRows )

// AFTER (correct — JSON serialisation preserves distinctions):
md5( $sql . '|' . json_encode( $params ) . '|' . $maxRows )
```

---

### P1-003 — Fix Stray Email Address in README (KI-011) ✅ DONE (v1.0.3)

**Priority:** HIGH  
**Effort:** Trivial  
**File:** `README.md`

Remove the email address `slickdexic@gmail.com` from the "Important Security Note" section. The sentence ending `...in connection strings.slickdexic@gmail.com` should simply end at `...in connection strings.`

---

### P1-004 — Fix Wrong Maintenance Script in UPGRADE.md (KI-012) ✅ DONE (v1.0.3)

**Priority:** HIGH  
**Effort:** Trivial  
**File:** `UPGRADE.md`

Replace:
```
php maintenance/rebuildrecentchanges.php
```
With:
```
php maintenance/purgeParserCache.php
```
Add a note that in MW 1.40+ the preferred approach is a null edit on affected pages, or the `refreshLinks.php` maintenance script for bulk operations.

---

### P1-005 — Correct SECURITY.md CSRF Documentation (KI-013) ✅ DONE (v1.0.3)

**Priority:** HIGH  
**Effort:** Small  
**File:** `SECURITY.md`

Update the CSRF Protection section to accurately reflect that:
- Only POST `runquery` actions require a CSRF token (`wpEditToken`).
- Read-only GET actions (`test`, `tables`, `columns`, `query`) do not require tokens, which is consistent with standard MediaWiki practice for read-only admin views.

Remove the incorrect statement about GET `token` parameter validation.

---

### P1-006 — Correct Magic Word Documentation in README and CHANGELOG (KI-014) ✅ DONE (v1.0.3)

**Priority:** HIGH  
**Effort:** Small  
**Files:** `README.md`, `CHANGELOG.md`

Remove the claims that uppercase magic words (`{{#ODBC_QUERY:}}`) work. Replace with:

> Parser function names are case-insensitive from v1.0.3 onwards (e.g., `{{#ODBC_QUERY:}}`, `{{#Odbc_Query:}}`, and `{{#odbc_query:}}` all work). Versions 1.0.0–1.0.2 incorrectly claimed this but only lowercase worked.

Correct the CHANGELOG entry for v1.0.1 that states the opposite of what was actually merged.

---

### P1-007 — Add v1.0.2 → v1.0.3 Section to UPGRADE.md (KI-017) ✅ DONE (v1.0.3)

**Priority:** MEDIUM  
**Effort:** Small  
**File:** `UPGRADE.md`

Add a `Upgrading to 1.0.3 from 1.0.2` section documenting:
- Magic words now case-insensitive (change is non-breaking for existing lowercase usage)
- Cache key fix (only relevant if `$wgODBCCacheExpiry > 0`)
- No configuration changes required

---

### P1-008 — Update SECURITY.md Known Limitations Section (KI-015) ✅ DONE (v1.0.3)

**Priority:** LOW  
**Effort:** Trivial  
**File:** `SECURITY.md`

Update the Known Limitations section to reflect that `TOP` vs `LIMIT` detection was fixed in v1.0.2 (but note it remains unfixed in the External Data connector path — see KI-003).

---

### P1-009 — Add a LICENSE File ✅ DONE (v1.0.3)

**Priority:** MEDIUM  
**Effort:** Trivial  
**File:** New `LICENSE` file at repository root

Create a `LICENSE` file containing the full text of the GNU General Public License version 2 (with "or later" language), matching the declared `GPL-2.0-or-later` SPDX identifier in `extension.json` and `composer.json`.

---

## Phase 2: v1.1.0 — Quality & Feature Release

These improvements are non-breaking. They add correct behaviour, close security gaps, and improve the developer and admin experience without changing the public API.

> **v1.0.3 Completion Status:** The following Phase 2 items were completed in the shipped v1.0.3 code: **P2-001** (connection liveness — partial: SELECT 1 added, but MS Access is broken — see new KI-023/P2-017), **P2-002** (driver-aware LIMIT/TOP — partial: fixed for direct ED sources only, `odbc_source` mode still broken — see KI-027/P2-021), **P2-003** (ED connector max rows — complete), **P2-004** (timeout at statement level — complete), **P2-005** (hash comment in blocklist — complete), **P2-006** (configurable max connections `$wgODBCMaxConnections` — complete), **P2-009** (column loop merged — complete), **P2-010** (mergeResults O(n²) fixed — complete), **P2-011** (getTableColumns case fix — complete), **P2-012** (admin column browser — complete), **P2-013** (DSN logic deduplicated — complete), **P2-015** (ADMIN_QUERY_MAX_ROWS constant — complete). Items not yet done: **P2-007**, and all Phase 3 items (P3-001 onward). All Phase 2 items (**P2-008**, **P2-014**, **P2-016** partial, **P2-024**) and documentation items (**P2-043**) completed in v1.1.0.

---

### P2-001 — Implement Real Connection Liveness Detection (KI-005) ✅ DONE (v1.0.3) — but see P2-017 (MS Access broken)

**Priority:** HIGH  
**Effort:** Moderate  
**File:** `includes/ODBCConnectionManager.php`

Replace the `odbc_error()` liveness check with a genuine ping:

```php
private static function isConnectionAlive( $conn ): bool {
    try {
        $result = @odbc_exec( $conn, 'SELECT 1' );
        if ( $result !== false ) {
            odbc_free_result( $result );
            return true;
        }
    } catch ( \Throwable $e ) {
        // Swallow; connection is dead.
    }
    return false;
}
```

Use this in `connect()` before returning a cached connection. If the ping fails, discard the cached connection and open a new one.

Note: `SELECT 1` is valid on all major RDBMS. A more robust approach is to use `odbc_tables()` which doesn't require query permissions, but `SELECT 1` is simpler and more universally supported.

---

### P2-002 — Fix Driver-Aware Limit Syntax in External Data Connector (KI-003) ✅ DONE (v1.0.3) — but see P2-021 (odbc_source mode still broken)

**Priority:** HIGH  
**Effort:** Small-Moderate  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`

Move `ODBCQueryRunner::requiresTopSyntax()` to a shared utility class (or make it accessible as a public static method — it already is), and use it in the ED connector's `limit()` and `getQuery()` methods:

```php
protected function getQuery(): string {
    $usesTop = ODBCQueryRunner::requiresTopSyntax( $this->getOdbcConfig() );
    $limitClause = $usesTop ? '' : static::limit( $this->sqlOptions['LIMIT'] ?? 0 );
    $topClause = $usesTop ? static::topLimit( $this->sqlOptions['LIMIT'] ?? 0 ) : '';

    return strtr( static::TEMPLATE, [
        '$top'     => $topClause,
        '$columns' => static::listColumns( $this->columns ),
        '$from'    => static::from( $this->tables, $this->joins ),
        // ...
        '$limit'   => $limitClause,
    ] );
}
```

The TEMPLATE constant would become:
```php
protected const TEMPLATE = 'SELECT $top $columns $from $where $group $having $order $limit';
```

---

### P2-003 — Enforce `$wgODBCMaxRows` in External Data Connector (KI-004) ✅ DONE (v1.0.3)

**Priority:** HIGH  
**Effort:** Small  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `fetch()`

After fetching rows, apply the global row limit:

```php
$maxRows = MediaWikiServices::getInstance()->getMainConfig()->get( 'ODBCMaxRows' );
$count = 0;
while ( $row = odbc_fetch_object( $rowset ) ) {
    if ( ++$count > $maxRows ) {
        break;
    }
    $result[] = $row;
}
```

---

### P2-004 — Fix Timeout: Apply at Statement Level ✅ DONE (v1.0.3)

**Priority:** HIGH  
**Effort:** Moderate  
**File:** `includes/ODBCQueryRunner.php`

Remove the connection-level timeout setting from `ODBCConnectionManager::connect()`. Instead, apply a per-statement timeout in `executeRawQuery()` after preparing a statement or before `odbc_exec()`:

```php
// Apply statement-level query timeout.
if ( $timeout > 0 && $stmt ) {
    @odbc_setoption( $stmt, 1 /* SQL_HANDLE_STMT */, 0 /* SQL_QUERY_TIMEOUT */, $timeout );
}
```

This aligns with the ODBC standard. Update the constant names accordingly.

---

### P2-005 — Add `#` (MySQL Hash Comment) to SQL Sanitizer Blocklist ✅ DONE (v1.0.3)

**Priority:** HIGH  
**Effort:** Trivial  
**File:** `includes/ODBCQueryRunner.php`, `sanitize()`

Add `#` to the `$charPatterns` blocklist (MySQL uses `#` as a single-line comment character, functionally equivalent to `--`):

```php
$charPatterns = [ ';', '--', '#', '/*', '*/', '<?', 'CHAR(', 'CONCAT(', 'UNION' ];
```

Also consider adding:
- `DECLARE` — SQL Server variable declarations
- `WAITFOR` — SQL Server time-delay
- `SLEEP(` — MySQL time-delay
- `BENCHMARK(` — MySQL timing attacks
- `PG_SLEEP(` — PostgreSQL time-delay
- `UTL_FILE` — Oracle file I/O
- `UTL_HTTP` — Oracle network requests

---

### P2-006 — Add `$wgODBCMaxConnections` Configuration Variable ✅ DONE (v1.0.3)

**Priority:** MEDIUM  
**Effort:** Small  
**Files:** `extension.json`, `includes/ODBCConnectionManager.php`

Replace the hardcoded `MAX_CONNECTIONS = 10` constant with a real configuration variable:

In `extension.json` config section:
```json
"ODBCMaxConnections": {
    "value": 10,
    "description": "Maximum number of ODBC connections to cache per request."
}
```

In `ODBCConnectionManager::connect()`, read:
```php
$maxConns = MediaWikiServices::getInstance()->getMainConfig()->get( 'ODBCMaxConnections' );
```

---

### P2-007 — Add Per-Page Query Count Limit (KI-018)

**Priority:** MEDIUM  
**Effort:** Moderate  
**Files:** `includes/ODBCParserFunctions.php`, `extension.json`

Add a new config variable `$wgODBCMaxQueriesPerPage` (default: 20 or similar). Track the count in `ParserOutput` extension data alongside `ODBCData`, and refuse to execute more than the limit:

```php
$queryCount = $storedData['__query_count'] ?? 0;
if ( $queryCount >= $config->get( 'ODBCMaxQueriesPerPage' ) ) {
    return [ self::formatError( wfMessage( 'odbc-error-too-many-queries' )->text() ), 'noparse' => false ];
}
```

---

### P2-008 — Extract Error Handler Installation to a Helper Method

**Priority:** MEDIUM  
**Effort:** Small  
**Files:** `ODBCConnectionManager.php`, `ODBCQueryRunner.php`, `EDConnectorOdbcGeneric.php`

The repeated pattern:
```php
set_error_handler( static function ( $errno, $errstr ) {
    throw new MWException( $errstr );
}, E_WARNING );
```
appears at least four times. Extract to a shared utility:

```php
class ODBCErrorHandler {
    public static function install(): void {
        set_error_handler( static function ( int $errno, string $errstr ): bool {
            throw new MWException( $errstr );
        }, E_WARNING );
    }

    public static function restore(): void {
        restore_error_handler();
    }
}
```

---

### P2-009 — Merge Double Column-Iteration Loop in `executeComposed()` ✅ DONE (v1.0.3)

**Priority:** MEDIUM  
**Effort:** Small  
**File:** `includes/ODBCQueryRunner.php`, `executeComposed()`

Combine the two `foreach ( $columns as ... )` loops into one pass that validates and builds the SELECT list simultaneously.

---

### P2-010 — Fix `mergeResults()` Triple-Nested Loop Complexity ✅ DONE (v1.0.3)

**Priority:** MEDIUM  
**Effort:** Small  
**File:** `includes/ODBCParserFunctions.php`, `mergeResults()`

Build a lowercase-keyed lookup array once per row, then look up mapping values in O(1):

```php
// Build case-insensitive lookup for this row once.
$rowLower = [];
foreach ( $row as $key => $val ) {
    $rowLower[ strtolower( $key ) ] = $val;
}

foreach ( $mappings as $localVar => $dbCol ) {
    $value = $rowLower[ strtolower( $dbCol ) ] ?? '';
    $storedData[ strtolower( $localVar ) ][] = (string)$value;
}
```

---

### P2-011 — Fix `getTableColumns()` Case-Insensitive Key Lookup (KI-007) ✅ DONE (v1.0.3)

**Priority:** MEDIUM  
**Effort:** Trivial  
**File:** `includes/ODBCQueryRunner.php`, `getTableColumns()`

Replace the two-variant lookup with a case-insensitive scan over all row keys:

```php
$colName = '';
foreach ( $row as $key => $val ) {
    if ( strcasecmp( $key, 'COLUMN_NAME' ) === 0 ) {
        $colName = (string)$val;
        break;
    }
}
$result[] = $colName;
```

---

### P2-012 — Improve Admin Column Browser with Type Information (KI-022) ✅ DONE (v1.0.3)

**Priority:** MEDIUM  
**Effort:** Small-Moderate  
**File:** `includes/specials/SpecialODBCAdmin.php`, `showColumns()`

Augment `getTableColumns()` to return type metadata alongside column names, and display a richer table in the admin UI:

| Column Name | Data Type | Nullable | Max Length |
|-------------|-----------|----------|------------|
| `id`        | INTEGER   | NO       | 10         |
| `name`      | VARCHAR   | YES      | 255        |

`odbc_columns()` already returns this metadata — it just isn't being surfaced.

---

### P2-013 — Deduplicate DSN Building Logic Between Connection Manager and ED Connector ✅ DONE (v1.0.3)

**Priority:** MEDIUM  
**Effort:** Moderate  
**Files:** `includes/ODBCConnectionManager.php`, `includes/connectors/EDConnectorOdbcGeneric.php`

Make the ED connector's `setCredentials()` call `ODBCConnectionManager::buildConnectionString()` when possible, or extract DSN building into a standalone `ODBCDsnBuilder` utility accessible from both. This ensures a single point of maintenance for connection string logic.

---

### P2-014 — Add README Warning to the "Complete Example"

**Priority:** LOW  
**Effort:** Trivial  
**File:** `README.md`

Add a clear warning callout to the Complete Example section noting that:
- `$wgODBCAllowArbitraryQueries = true` is used only for demonstration
- `odbc-query` granted to all `user` accounts should not be done in production
- The recommended approach is prepared statements with permission restricted to trusted groups

---

### P2-015 — Replace Magic Numbers with Constants in `SpecialODBCAdmin` ✅ DONE (v1.0.3)

**Priority:** LOW  
**Effort:** Trivial  
**File:** `includes/specials/SpecialODBCAdmin.php`

Define `private const ADMIN_QUERY_MAX_ROWS = 100;` and use it in `runTestQuery()` instead of the bare `100`.

---

### P2-016 — Apply Result Caching and UTF-8 Conversion in ED Connector

**Priority:** LOW  
**Effort:** Moderate  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`

Route the ED connector's fetching through `ODBCQueryRunner::executeRawQuery()` (or a shared fetch utility) to gain:
- `$wgODBCCacheExpiry` query caching
- Automatic UTF-8 encoding conversion
- Consistent query audit logging

---

---

### P2-017 — Fix `pingConnection()` for MS Access (KI-023) ✅ DONE (v1.1.0)

**Priority:** HIGH  
**Effort:** Small  
**File:** `includes/ODBCConnectionManager.php`, `pingConnection()`

Replace the generic `SELECT 1` ping with a driver-aware liveness probe. MS Access (Jet/ACE) requires a `FROM` clause; use `SELECT 1 FROM MSysObjects` or better, the portable driver-agnostic `odbc_tables()` approach:

```php
private static function pingConnection( $conn, array $config ): bool {
    // odbc_tables() is a metadata call that doesn't require query permissions
    // and works across all ODBC-compliant drivers including MS Access.
    $result = @odbc_tables( $conn );
    if ( $result !== false ) {
        odbc_free_result( $result );
        return true;
    }
    return false;
}
```

If `odbc_tables()` is unsuitable for a given driver, provide a fallback with driver-specific test SQL in the `$wgODBCSources` config (e.g., `'ping_query' => 'SELECT 1 FROM dual'` for Oracle).

---

### P2-018 — Move `UNION` to Word-Boundary Pattern Match (KI-024) ✅ DONE (v1.1.0)

**Priority:** HIGH  
**Effort:** Trivial  
**File:** `includes/ODBCQueryRunner.php`, `sanitize()`

Remove `UNION` from the `$charPatterns` substring list and add it to a word-boundary keyword list that uses `preg_match`:

```php
$charPatterns = [ ';', '--', '#', '/*', '*/', '<?', 'CHAR(', 'CONCAT(' ];

$wordPatterns = [ 'UNION', 'DECLARE', 'EXEC', 'EXECUTE', 'WAITFOR', 'SLEEP(' ];

foreach ( $wordPatterns as $keyword ) {
    // Match keyword as a whole word (not a substring of an identifier).
    if ( preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $sql ) ) {
        throw new MWException( "Illegal SQL keyword '$keyword' detected." );
    }
}
```

`\b` word boundaries prevent matching `LABOUR_UNION` while still blocking `SELECT ... UNION SELECT ...`.

---

### P2-019 — Escape Special Characters in `buildConnectionString()` Values (KI-025) ✅ DONE (v1.1.0)

**Priority:** MODERATE  
**Effort:** Small  
**File:** `includes/ODBCConnectionManager.php`, `buildConnectionString()`

Wrap any value that contains `{`, `}`, or `;` in `{...}` per the ODBC connection string escaping spec. Values containing `}` must have each `}` doubled:

```php
private static function escapeConnValue( string $value ): string {
    // If the value contains special chars, wrap in braces and escape inner braces.
    if ( strpbrk( $value, ';{}' ) !== false ) {
        return '{' . str_replace( '}', '}}', $value ) . '}';
    }
    return $value;
}
```

Apply this to `Server`, `Database`, `Uid`, `Pwd`, and `Driver` values in `buildConnectionString()`.

---

### P2-020 — Call `validateConfig()` from `connect()` (KI-026) ✅ DONE (v1.1.0)

**Priority:** MINOR  
**Effort:** Trivial  
**File:** `includes/ODBCConnectionManager.php`

`validateConfig()` is dead code — it exists but is never called. Add a call to it near the top of `connect()` before attempting any ODBC operation, so that configuration errors produce a clear human-readable message rather than a raw ODBC driver error:

```php
public static function connect( string $sourceId ) {
    $config = self::getSourceConfig( $sourceId );
    self::validateConfig( $config, $sourceId ); // ← add this call
    // ... rest of connect logic
}
```

---

### P2-021 — Fix ED Connector `odbc_source` Mode to Read Driver from `$wgODBCSources` (KI-027) ✅ DONE (v1.1.0)

**Priority:** HIGH  
**Effort:** Small  
**File:** `includes/connectors/EDConnectorOdbcGeneric.php`, `setCredentials()`

When `odbc_source` is present in the ED credentials, look up the referenced `$wgODBCSources` entry and inherit its `driver` setting if the ED source doesn't specify one directly:

```php
if ( !empty( $credentials['odbc_source'] ) && empty( $credentials['driver'] ) ) {
    $odbcSources = MediaWikiServices::getInstance()->getMainConfig()->get( 'ODBCSources' );
    $ref = $credentials['odbc_source'];
    if ( isset( $odbcSources[$ref]['driver'] ) ) {
        $credentials['driver'] = $odbcSources[$ref]['driver'];
    }
}
$this->credentials = $credentials;
```

This ensures `requiresTopSyntax()` receives the correct driver string in `odbc_source` mode.

---

### P2-022 — Fix `$wgODBCExternalDataIntegration` Falsy Check (KI-028) ✅ DONE (v1.1.0)

**Priority:** MINOR  
**Effort:** Trivial  
**File:** `includes/ODBCHooks.php`, `registerExternalDataConnector()`

Replace the strict-identity comparison with a proper falsy check:

```php
// BEFORE: only `false` disables; 0, null, '' all still enable:
if ( $wgODBCExternalDataIntegration === false ) {
    return;
}

// AFTER: any falsy value disables integration:
if ( !$wgODBCExternalDataIntegration ) {
    return;
}
```

---

### P2-023 — Add Debug Logging When `odbc_setoption()` Fails (KI-033)

**Priority:** MINOR  
**Effort:** Trivial  
**File:** `includes/ODBCQueryRunner.php`

The `@odbc_setoption()` call silently swallows failures. Add a Psr\Log-style log entry via the MediaWiki `LoggerFactory` when the suppress operator masks an error:

```php
$prevHandler = set_error_handler( static function ( $errno, $errstr ) use ( &$timeoutFailed, &$timeoutError ): bool {
    $timeoutFailed = true;
    $timeoutError = $errstr;
    return true;
} );
odbc_setoption( $stmt, 1, 0, $timeout );
restore_error_handler();

if ( $timeoutFailed ) {
    $logger = LoggerFactory::getInstance( 'ODBC' );
    $logger->warning( 'odbc_setoption() failed to set query timeout', [
        'error' => $timeoutError,
        'source' => $sourceId,
    ] );
}
```

This gives operators visibility into drivers (e.g., MS Access) that do not support query timeout.

---

### P2-024 — Implement LRU Eviction for Connection Pool (KI-034)

**Priority:** MINOR  
**Effort:** Moderate  
**File:** `includes/ODBCConnectionManager.php`

The current FIFO eviction (oldest connection evicted when the pool is full) means a rarely-used connection opened early in the request survives while a recently-used connection gets dropped. Replace with LRU (Least Recently Used) eviction by tracking last-access time alongside each connection handle:

```php
private static array $connections = [];    // [sourceId => handle]
private static array $lastUsed    = [];    // [sourceId => microtime(true)]

// On each connection retrieval:
self::$lastUsed[$sourceId] = microtime( true );

// On pool-full eviction — find least recently used:
asort( self::$lastUsed );
$lruSourceId = array_key_first( self::$lastUsed );
odbc_close( self::$connections[$lruSourceId] );
unset( self::$connections[$lruSourceId], self::$lastUsed[$lruSourceId] );
```

---

### P2-025 — Add v1.0.2 and v1.0.3 to SECURITY.md Release History (KI-029)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `SECURITY.md`

Add entries to the Security Release History for:

**v1.0.2:** XSS via unescaped column names in `#display_odbc_table`, wikitext injection via `escapeTemplateParam()`, UNION keyword not blocked, password exposure via `odbc_error()` messages, missing CSRF on `SpecialODBCAdmin` POST actions.

**v1.0.3:** `pingConnection()` real liveness check added (eliminates cache poisoning risk), cache key collision fix (eliminates cross-user data leakage when caching enabled), `#` MySQL comment blocked, column browser added (no impact), `$wgODBCMaxRows` now enforced in ED connector.

---

### P2-026 — Update CHANGELOG v1.0.3 Release Date (KI-030)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `CHANGELOG.md`

Change `## [1.0.3] - Unreleased` to carry the actual release date. If the exact date is unknown, use the date the extension was published or the last commit date. "Unreleased" is misleading when `extension.json` already declares this as the shipped version.

---

### P2-027 — Fix README Troubleshooting: `MAX_CONNECTIONS` → `$wgODBCMaxConnections` (KI-031)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `README.md`, Performance Issues troubleshooting section

Replace:
> Connection pool is limited to 10; increase if needed by modifying `MAX_CONNECTIONS` constant.

With:
> Connection pool defaults to 10 simultaneous connections. Increase by setting `$wgODBCMaxConnections` in `LocalSettings.php`:
> ```php
> $wgODBCMaxConnections = 20;
> ```

---

### P2-028 — Fix `sanitize()` Keyword Regex Missing Trailing `\b` (KI-032) ✅ DONE (v1.1.0)

**Priority:** HIGH  
**Effort:** Trivial (1 line change per regex pattern)  
**File:** `includes/ODBCQueryRunner.php`, `sanitize()`

The regex built for each blocked SQL keyword has a leading `\b` word boundary but no trailing one:

```php
// BEFORE — false positives (e.g. DECLARED_AT blocked by DECLARE):
$pattern = '/\b' . preg_quote( $keyword, '/' ) . '/i';

// AFTER — keyword only matched as a complete word:
$pattern = '/\b' . preg_quote( $keyword, '/' ) . '\b/i';
```

Apply to every entry in `$keywordPatterns`. The `$charPatterns` group (including `UNION`) uses `strpos()` and is a separate issue (KI-024, P2-018).

This is a correctness bug affecting any site whose database schema contains column or table names that begin with any blocked keyword (e.g., `DECLARED_AT`, `DELETED_AT`, `GRANTED_BY`, `INSERTING_TIMESTAMP`, `EXECUTIVE`). Editors receive a false "Illegal SQL pattern" error with no workaround. Fix should be released as a v1.0.4 patch or included in v1.1.0.

---

### P2-029 — Correct 5 Factual Errors in `wiki/Architecture.md` (KI-035) ✦ NEW (2026-03-03)

**Priority:** HIGH (documentation — contributor risk)  
**Effort:** Small (5 targeted corrections)  
**File:** `wiki/Architecture.md`

The Architecture.md page contains five errors that would mislead contributors. Corrections required:

1. **"All methods are static"** → Change to: "Instance methods require a constructed `ODBCQueryRunner` object. Only `sanitize()`, `validateIdentifier()`, and `requiresTopSyntax()` are static."
2. **Method signatures with fake `$sourceId` parameter** → Remove `$sourceId` from `executeComposed()`, `executePrepared()`, and `executeRawQuery()` signatures. The `$sourceId` is set on construction via `__construct( string $sourceId, ... )`, not on every call.
3. **"`displayOdbcTable()` calls `expandTemplate()`"** → Change to: "`displayOdbcTable()` assembles a wikitext template call string (e.g., `{{TemplateName|col1=val1|...}}`) and returns it. MediaWiki processes this string through normal page parsing — no explicit `expandTemplate()` call is made."
4. **"LRU eviction"** → Change to "FIFO eviction" and remove the contradictory paragraph.
5. **`getTableList()`** → Change to `getTables()`.

---

### P2-030 — Fix `wiki/Known-Issues.md` KI-008 Description (KI-036) ✦ NEW (2026-03-03)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `wiki/Known-Issues.md`

The wiki description of KI-008 says the issue occurs "when `data=` specifies mappings but omits some columns." Change to: "`SELECT *` is issued when the `data=` parameter is omitted entirely from `{{#odbc_query:}}`."

---

### P2-031 — Fix `README.md` Magic Word Version Claim (KI-037) ✦ NEW (2026-03-03)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `README.md`, Troubleshooting section

Change "After updating to **version 1.0.1+**, uppercase variants also work correctly" to "After updating to **version 1.0.3+**, uppercase variants also work correctly." v1.0.1 made case sensitivity worse; the fix was in v1.0.3.

---

### P2-032 — Remove `KNOWN_ISSUES.md` Duplicate Footer (KI-038) ✅ DONE (2026-03-03)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `KNOWN_ISSUES.md`

The orphaned second footer fragment was removed as part of the 2026-03-03 re-review update to `KNOWN_ISSUES.md`. Complete.

---

### P2-033 — Fix `UPGRADE.md` Non-Standard `$GLOBALS` Notation (KI-039) ✅ DONE (v1.1.0)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `UPGRADE.md`, v1.0.3 section

Change:
```php
$GLOBALS['wgODBCMaxConnections'] = 10;
```
To:
```php
$wgODBCMaxConnections = 10;
```

---

### P2-035 — Fix `validateConfig()` Progress OpenEdge `host` Key Rejection (KI-040) ✦ NEW (v1.1.0 re-review)

**Priority:** HIGH  
**Effort:** Trivial (1 condition, ~2 lines)  
**File:** `includes/ODBCConnectionManager.php`, `validateConfig()`

This is a one-line regression fix. The validation check must be extended to accept `host` as an alternative to `server` for Progress OpenEdge driver configs:

```php
// BEFORE (v1.1.0 — rejects valid Progress configs):
if ( $hasDriver && empty( $config['server'] ) && empty( $config['dsn'] ) ) {
    $errors[] = 'server (required when using driver mode)';
}

// AFTER:
if ( $hasDriver && empty( $config['server'] ) && empty( $config['host'] ) && empty( $config['dsn'] ) ) {
    $errors[] = 'server or host (required when using driver mode)';
}
```

This is a regression introduced alongside the Progress OpenEdge `buildConnectionString()` support in v1.1.0 — both changes should have been made together.

---

### P2-036 — Date `CHANGELOG.md` v1.1.0 Entry (KI-041) ✦ NEW (v1.1.0 re-review)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `CHANGELOG.md`

Replace `## [1.1.0] - Unreleased` with the actual release date. Add a release-checklist step to prevent this recurring (see also KI-030 / P2-020 — same issue for v1.0.3, now fixed).

---

### P2-037 — Fix `wiki/Architecture.md` `buildConnectionString()` Description (KI-042) ✦ NEW (v1.1.0 re-review)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `wiki/Architecture.md`, `ODBCConnectionManager` section

Update the description from "Does not handle Mode 1 (DSN) or Mode 3 (full string)" to accurately state all three modes are handled: (1) full `connection_string` returned as-is, (2) DSN name without `driver` returned as-is, (3) driver/server/database string constructed.

---

### P2-038 — Remove Stale KI-024 Note from `wiki/Security.md` (KI-043) ✦ NEW (v1.1.0 re-review)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `wiki/Security.md`, SQL injection section

Remove or update the KI-024 callout that warns about `UNION` substring matching. KI-024 was fixed in v1.1.0 and the warning now misleads editors into avoiding valid identifiers.

---

### P2-039 — Correct `SECURITY.md` Known Limitations Row-Limit Description (KI-044) ✦ NEW (v1.1.0 re-review)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `SECURITY.md`, Known Limitations section

Replace the outdated "tries both TOP and LIMIT" description with the current driver-aware selection logic: `TOP n` (SQL Server/Access/Sybase), `FIRST n` (Progress OpenEdge), `LIMIT n` (default). Also add Progress OpenEdge to the description since it was introduced in v1.1.0.

---

### P2-040 — Correct `UPGRADE.md` v1.0.1 Magic Word Claim (KI-045) ✦ NEW (v1.1.0 re-review)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `UPGRADE.md`, "Upgrading to 1.0.1" section

The section claims that uppercase magic word variants were fixed in v1.0.1. They were not — v1.0.1 actually broke them further. The fix was in v1.0.3. Remove the false entry or add a correction note: "Note: the v1.0.1 change inadvertently made case sensitivity stricter. Uppercase magic word variants only work correctly from **v1.0.3** onwards."

---

### P2-041 — Correct `wiki/Parser-Functions.md` `data=` Required Field (KI-046) ✦ NEW (v1.1.0 re-review)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `wiki/Parser-Functions.md`

Change the `data=` row in the `{{#odbc_query:}}` parameter table from `Required: Yes` to `Required: No`. Add a warning that omitting `data=` causes `SELECT *` to be issued (KI-008), potentially returning sensitive columns.

---

### P2-042 — Correct `KNOWN_ISSUES.md` Open Issue Count and List in Footer (KI-047) ✦ NEW (v1.1.0 re-review)

**Priority:** DOCUMENTATION  
**Effort:** Trivial  
**File:** `KNOWN_ISSUES.md`, footer line

Update the footer to reflect the correct open issue count after removing KI-030 (fixed), KI-038 (fixed in document), and KI-039 (fixed in v1.1.0); and adding KI-040 through KI-048. **Partially addressed in v1.1.0 re-review update to KNOWN_ISSUES.md.**

---

### P2-043 — Fix Mojibake Encoding in `KNOWN_ISSUES.md` Resolved-Issues Section (KI-048) ✦ NEW (v1.1.0 re-review)

**Priority:** DOCUMENTATION (Presentation)  
**Effort:** Small  
**File:** `KNOWN_ISSUES.md`, resolved-issues entries

Multibyte Unicode characters (`—`, `✅`, `’`) render as mojibake (`â€”`, `âœ…`, `â€™`) in earlier entries. Re-save the file as UTF-8 without BOM. Ensure the editor/git config enforces UTF-8. Do a one-time find-and-replace pass to correct the known sequences.

---

### P2-027 — RESOLVED

> P2-027 (Fix README Troubleshooting: `MAX_CONNECTIONS` → `$wgODBCMaxConnections`, KI-031) was incorrectly marked Done in a prior tracking pass. It has now been correctly applied: README.md updated in the v1.1.0 implementation pass.

---

### P2-047 — Fix `odbc-error-too-many-queries` i18n Message Workaround (KI-050) ✦ NEW (2026-03-05)

**Priority:** LOW  
**Effort:** Trivial (1 sentence edit in `i18n/en.json`)  
**File:** `i18n/en.json`  
**Status:** ✅ Done (v1.2.0) — `{{#odbc_clear:}}` recommendation removed from `i18n/en.json`.

The error message included: "Use `{{#odbc_clear:}}` to separate logical sections." This is incorrect — `odbcClear()` resets only `ODBCData`; the per-page query counter (`ODBCQueryCount`) is a separate key that `odbcClear()` never touches. Following the advice has zero effect on the error.

Remove the `{{#odbc_clear:}}` recommendation and replace with accurate guidance, e.g.: "Reduce the number of `{{#odbc_query:}}` calls on this page, or raise `$wgODBCMaxQueriesPerPage` in `LocalSettings.php`."

---

### P2-048 — Fix `wiki/Architecture.md` FIFO/LRU and WANObjectCache Errors (KI-051) ✦ NEW (2026-03-05)

**Priority:** MEDIUM (contributor-facing accuracy)  
**Effort:** Small (4 targeted text edits)  
**File:** `wiki/Architecture.md`  
**Status:** ✅ Done (v1.2.0) — all four locations corrected.

Four locations in `wiki/Architecture.md` were not updated when P2-024 (LRU eviction) was implemented:

1. `connect()` description says "FIFO" — change to "LRU".
2. Connection pool subsection says "FIFO eviction (`array_key_first()`)" — change to "LRU eviction (`asort($lastUsed)` + `array_key_first()`)".
3. Design Limitations table row: "FIFO connection eviction | LRU planned | P2-024" — update to show P2-024 Done.
4. Caching section: "**WANObjectCache** (from `MediaWikiServices::getInstance()->getMainWANObjectCache()`)" — change to "`ObjectCache::getLocalClusterInstance()` (node-local cache; not shared across app servers)".

---

### P2-049 — Update `wiki/Known-Issues.md` KI-020 Partial Fix Status (KI-052) ✦ NEW (2026-03-05)

**Priority:** LOW  
**Effort:** Trivial (1 entry update)  
**File:** `wiki/Known-Issues.md`  
**Status:** ✅ Done (v1.2.0) — KI-020 entry updated with partial-fix status and mode-by-mode breakdown.

Update KI-020 from "fully open / Planned fix: v1.1.0" to "Partially fixed in v1.1.0 (P2-016)" with clear distinction:
- `odbc_source` mode: now fixed — queries route through `executeRawQuery()` gaining caching and UTF-8 encoding.
- Standalone External Data mode: still open — no caching, no encoding conversion.

---

### P2-050 — Correct `$wgODBCMaxConnections` "Per Source" in Six Locations (KI-053) ✦ NEW (2026-03-05)

**Priority:** LOW  
**Effort:** Small (6 text replacements across 5 files)  
**Files:** `extension.json`, `README.md` (x2), `CHANGELOG.md`, `UPGRADE.md`, `SECURITY.md`  
**Status:** ✅ Done (v1.2.0) — all six instances corrected.

`$wgODBCMaxConnections` is a global pool limit across all sources combined. All six instances say "per source" or "per data source". Replace with "across all sources combined" (or equivalent phrasing) in each location.

---

### P2-051 — Complete P2-008: Make `withOdbcWarnings()` Accessible to ODBCQueryRunner (KI-053 follow-on) ✦ NEW (2026-03-05)

**Priority:** LOW (code quality — DRY completion)  
**Effort:** Small  
**Files:** `includes/ODBCConnectionManager.php`, `includes/ODBCQueryRunner.php`, `includes/connectors/EDConnectorOdbcGeneric.php`  
**Status:** ✅ Done (v1.2.0) — `withOdbcWarnings()` made `public static`; all five raw closures replaced.

P2-008 added `withOdbcWarnings()` to `ODBCConnectionManager` to extract the repeated raw `set_error_handler` closure pattern. However, the method was declared `private static`, making it inaccessible to `ODBCQueryRunner` and `EDConnectorOdbcGeneric`. Five raw closures remain:

- `ODBCQueryRunner.php`: lines 221, 507, 548
- `EDConnectorOdbcGeneric.php`: lines 204, 332

**Fix:** Change `withOdbcWarnings()` to `public static` (or `protected static` if ODBCQueryRunner becomes a subclass, but public is simpler) and replace all five raw closures with `ODBCConnectionManager::withOdbcWarnings(...)` calls.

---

---

### P2-052 — Fix `noparse`/`isHTML` on All `odbcQuery()` Error Returns (§5.2) ✦ NEW (2026-03-06)

**Priority:** MEDIUM (correctness)  
**Effort:** Trivial  
**Files:** `includes/ODBCParserFunctions.php`  
**Status:** ✅ Done (v1.2.0) — All five error returns corrected to `'noparse' => true, 'isHTML' => true`.

`formatError()` returns raw HTML (`<span class="error odbc-error">…</span>`). Returning it with `'noparse' => false` allows the MediaWiki parser to treat the HTML as wikitext, which can corrupt the error span's attributes (e.g. `class=` quoted with `"` may be mangled). All five error-path returns in `odbcQuery()` (permission denied, query limit, no source, no from, MWException catch) have been corrected.

---

### P2-053 — Add Query Execution Timing and Slow-Query Log Channel ✦ NEW (2026-03-06)

**Priority:** MEDIUM (observability)  
**Effort:** Small  
**Files:** `includes/ODBCQueryRunner.php`, `extension.json`, `README.md`  
**Status:** ✅ Done (v1.2.0) — `$queryStart`/`$elapsed` added; `odbc-slow` channel added; `ODBCSlowQueryThreshold` config key added.

Before this change, query execution time was invisible to operators. Slow queries produced no log evidence and there was no way to distinguish a fast cache-hit trace from a 30-second ODBC round-trip in the `odbc` log. This fix:
- Records `microtime(true)` immediately after `odbc_execute()` succeeds.
- Computes `$elapsed` (rounded to 3 decimal places) after the final `odbc_free_result()` call.
- Appends `— Returned N rows in X.XXXs` to every `wfDebugLog('odbc', ...)` query entry.
- Routes an additional `wfDebugLog('odbc-slow', ...)` entry when `$elapsed > $wgODBCSlowQueryThreshold > 0`.

---

### P2-054 — Replace `extension.json` `callback` with `ExtensionRegistration` Hook (§3.7) ✦ NEW (2026-03-03)

**Priority:** LOW (forward-compatibility)  
**Effort:** Trivial  
**Files:** `extension.json`, `includes/ODBCHooks.php`  
**Status:** ✅ Done (v1.3.0) — `"callback"` removed; `"ExtensionRegistration": "ODBCHooks::onRegistration"` added under `"Hooks"`. Docblock in `ODBCHooks.php` updated.

The `callback` key in `extension.json` is the pre-MW1.25 mechanism for one-time setup. The modern equivalent is to register the same method under the `ExtensionRegistration` hook in the `"Hooks"` section, which is called at the same point in the extension loading lifecycle.

---

### P2-055 — Cache `$mainConfig` in `ODBCQueryRunner` Constructor (§3.8) ✦ NEW (2026-03-03)

**Priority:** LOW (performance / DRY)  
**Effort:** Trivial  
**Files:** `includes/ODBCQueryRunner.php`  
**Status:** ✅ Done (v1.3.0) — `private $mainConfig` property added; set once in constructor; used in `executeComposed()`, `executePrepared()`, and `executeRawQuery()` instead of three independent service-locator calls.

Each of the three execute methods called `MediaWikiServices::getInstance()->getMainConfig()` independently. While cheap, these are redundant calls on hot paths. Caching in the constructor eliminates the three repeated lookups.

---

### P2-056 — Enforce `$wgODBCAllowArbitraryQueries` in `runTestQuery()` (§2.2) ✦ NEW (2026-03-03)

**Priority:** MEDIUM (security / consistency)  
**Effort:** Trivial  
**Files:** `includes/specials/SpecialODBCAdmin.php`  
**Status:** ✅ Done (v1.3.0) — Check added before `executeRawQuery()` call; consistent with `executeComposed()` policy.

`runTestQuery()` previously called `executeRawQuery()` directly, bypassing the arbitrary-query gate in `executeComposed()`. Operators who set `$wgODBCAllowArbitraryQueries = false` could still run ad-hoc SQL via Special:ODBCAdmin. The fix adds the same global + per-source `allow_queries` check, returning an error box if both are disabled.

---

### P2-057 — Log Dropped `data=` Mapping Pairs in `parseDataMappings()` (§5.6) ✦ NEW (2026-03-03)

**Priority:** LOW (diagnostics)  
**Effort:** Trivial  
**Files:** `includes/ODBCParserFunctions.php`  
**Status:** ✅ Done (v1.3.0) — `wfDebugLog('odbc', ...)` entry added for each oversized pair that is dropped.

Mapping pairs longer than 256 characters were silently skipped. Template authors had no way to know their `data=` parameter was partially ignored. The log entry includes pair length and the first 80 characters of the pair for easy identification.

---

### P2-058 — Remove Deprecated `cols` Attribute from Admin SQL Textarea (§5.5) ✦ NEW (2026-03-03)

**Priority:** LOW (HTML5 compliance)  
**Effort:** Trivial  
**Files:** `includes/specials/SpecialODBCAdmin.php`  
**Status:** ✅ Done (v1.3.0) — `'cols' => 80` removed; `'style' => 'width: 100%; max-width: 60em; box-sizing: border-box;'` added.

`cols` is a deprecated presentation attribute in HTML5. Width should be controlled via CSS.

 (not necessarily at the wiki-user level) and require careful planning. They align the extension with MediaWiki 1.42+ and PHP 8.x best practices.

---

### P3-001 — Convert `ODBCConnectionManager` to a Real MediaWiki Service

**Priority:** HIGH (architectural)  
**Effort:** Large  
**Files:** All files + new `ServiceWiring.php`

Eliminate the all-static `ODBCConnectionManager` class. Introduce a `ServiceWiring.php` that registers `ODBCConnectionManager` as a MediaWiki service via `MediaWikiServices`:

```php
// ServiceWiring.php
'ODBCConnectionManager' => static function ( MediaWikiServices $services ): ODBCConnectionManager {
    return new ODBCConnectionManager( $services->getMainConfig() );
},
```

Register in `extension.json`:
```json
"ServiceWiringFiles": [ "ServiceWiring.php" ]
```

Update all callers to obtain the service via `MediaWikiServices::getInstance()->get( 'ODBCConnectionManager' )` or (preferably) through constructor injection.

Benefits:
- Full testability via mock injection
- No global state
- Proper lifecycle management
- Aligns with MediaWiki core architecture

---

### P3-002 — Introduce Proper Interfaces

**Priority:** MEDIUM (architectural)  
**Effort:** Moderate  

Define interfaces for the main service contracts:

```php
interface IODBCConnectionManager {
    public function connect( string $sourceId );
    public function disconnect( string $sourceId ): void;
    public function testConnection( string $sourceId ): array;
    public function buildConnectionString( array $config ): string;
}

interface IODBCQueryExecutor {
    public function executeComposed( string $from, array $columns, ... ): array;
    public function executePrepared( string $queryName, array $parameters ): array;
}
```

Implement these interfaces in the real classes and use them as type hints throughout. This enables mocking in tests and allows future alternative implementations (e.g., a mock implementation for testing wiki pages without a live database).

---

### P3-003 — Create a Comprehensive Unit Test Suite

**Priority:** HIGH (architectural)  
**Effort:** Large  
**Files:** New `tests/` directory tree

Implement tests covering:

- `ODBCConnectionManager::buildConnectionString()` — all DSN modes
- `ODBCQueryRunner::sanitize()` — all patterns (both allowed and blocked)
- `ODBCQueryRunner::validateIdentifier()` — valid and invalid identifiers
- `ODBCQueryRunner::requiresTopSyntax()` — all driver name variants
- `ODBCQueryRunner::executeRawQuery()` — with mock ODBC connection
- `ODBCParserFunctions::parseDataMappings()` — all edge cases
- `ODBCParserFunctions::mergeResults()` — multi-row, multi-mapping
- `ODBCParserFunctions::escapeTemplateParam()` — injection prevention
- `SpecialODBCAdmin` — action routing, permission checks, SELECT enforcement
- Magic word registration — verify all five words resolve correctly

Add `phpunit.xml`, `composer.json` `require-dev` section (PHPUnit 9/10, mediawiki/mediawiki-codesniffer), and a GitHub Actions workflow.

---

### P3-004 — Add `.phpcs.xml` and Enforce Coding Standards in CI

**Priority:** MEDIUM  
**Effort:** Moderate  
**Files:** New `.phpcs.xml`, `.github/workflows/ci.yml`

Add PHP_CodeSniffer with `MediaWiki` coding standard rules. All existing code should pass. Add to the CI workflow alongside PHPUnit.

---

### P3-005 — Replace `MWException` Usage with Typed Domain Exceptions

**Priority:** LOW (architectural)  
**Effort:** Moderate  

`MWException` is a generic base exception. The extension throws it throughout. Replace with a hierarchy of typed exceptions:

```
ODBCException (extends RuntimeException)
├── ODBCConnectionException
├── ODBCQueryException
├── ODBCSecurityException  (illegal input, permission denial)
└── ODBCConfigException    (unknown source, invalid config)
```

This makes it possible for callers and test code to catch specific exception types rather than catching all `MWException`. It is also necessary if the extension ever needs to distinguish between connection errors and security violations in its error handling.

---

### P3-006 — Parameterize WHERE Clause Even In Composed Queries

**Priority:** HIGH (security/architectural)  
**Effort:** Large  

The single biggest long-term security improvement would be to accept the `where=` clause as a pattern with named bound parameters rather than a raw string. For example:

```wiki
{{#odbc_query: source=mydb | from=Users
 | where=Status=:status AND Region=:region
 | bind:status=active | bind:region=EMEA
 | data=name=FullName
}}
```

This would allow the extension to construct a true parameterized query via `odbc_prepare()`/`odbc_execute()` even for composed queries, entirely eliminating SQL injection risk in the WHERE clause. This is a significant parser function API change and requires careful design to handle the mapping between wiki template parameters and SQL bind parameters — hence v2.0.0 placement.

---

## Documentation Improvements (Ongoing)

The following documentation improvements should be addressed in the next release regardless of version:

1. **Add an API reference section to README** covering all parser function parameters in a single consolidated table, with types, defaults, and validation rules.
2. **Add a "Security Model" section** that accurately describes what the extension does and does not protect against, including the limitations of the keyword blocklist.
3. **Document the `odbc_source` vs standalone ED configuration modes** more clearly, including the feature parity gap (no caching, no max row enforcement in `odbc_source` mode).
4. **Document all `$wgODBCSources` configuration keys** in a comprehensive table including `timeout`, `allow_queries`, `dsn_params`, `trust_certificate`, `prepared`, `connection_string`, `driver`, `server`, `database`, `port`, `dsn`, `user`, `password`.
5. **Add a "How It Works" architectural overview** explaining the data flow from wiki editor input through parser function → query runner → ODBC → result storage → display functions.
6. **Document MediaWiki version support matrix** — which MW versions have been tested, what is supported, and what is the deprecation timeline.

---

## Priority Matrix Summary

| Item | Phase | Status | Priority | Effort | Impact |
|------|-------|--------|----------|--------|--------|
| P1-001 Fix magic word flag | v1.0.3 | ✅ Done | CRITICAL | Trivial | Breaks all uppercase users |
| P1-002 Fix cache key collision | v1.0.3 | ✅ Done | CRITICAL | Trivial | Data integrity when caching on |
| P1-003 Remove email from README | v1.0.3 | ✅ Done | HIGH | Trivial | Professionalism |
| P1-004 Fix UPGRADE.md script | v1.0.3 | ✅ Done | HIGH | Trivial | User confusion |
| P1-005 Fix SECURITY.md CSRF docs | v1.0.3 | ✅ Done | HIGH | Small | Inaccurate security docs |
| P1-006 Correct uppercase MW claim | v1.0.3 | ✅ Done | HIGH | Small | Trust / accuracy |
| P1-007 Add v1.0.3 UPGRADE section | v1.0.3 | ✅ Done | MEDIUM | Small | Upgrade guidance |
| P1-008 SECURITY.md limitations | v1.0.3 | ✅ Done | LOW | Trivial | Documentation accuracy |
| P1-009 Add LICENSE file | v1.0.3 | ✅ Done | MEDIUM | Trivial | Legal compliance |
| P2-001 Real connection liveness (partial) | v1.1.0 | ⚠️ Partial | HIGH | Moderate | Reliability |
| P2-002 ED connector LIMIT/TOP (partial) | v1.1.0 | ⚠️ Partial | HIGH | Small | SQL Server/Access users |
| P2-003 ED connector max rows | v1.1.0 | ✅ Done | HIGH | Small | Safety |
| P2-004 Fix timeout at stmt level | v1.1.0 | ✅ Done | HIGH | Moderate | Timeout actually works |
| P2-005 Add `#` to sanitizer | v1.1.0 | ✅ Done | HIGH | Trivial | Security |
| P2-006 Configurable max connections | v1.1.0 | ✅ Done | MEDIUM | Small | Operator flexibility |
| P2-007 Per-page query limit | v1.1.0 | ✅ Done | MEDIUM | Moderate | DoS mitigation |
| P2-008 Extract error handler helper | v1.1.0 | ✅ Done | MEDIUM | Small | DRY |
| P2-009 Merge column loops | v1.1.0 | ✅ Done | MEDIUM | Small | Code quality |
| P2-010 Fix mergeResults complexity | v1.1.0 | ✅ Done | MEDIUM | Small | Performance |
| P2-011 Fix getTableColumns case | v1.1.0 | ✅ Done | MEDIUM | Trivial | Correctness |
| P2-012 Richer column browser | v1.1.0 | ✅ Done | MEDIUM | Moderate | Admin UX |
| P2-013 Deduplicate DSN logic | v1.1.0 | ✅ Done | MEDIUM | Moderate | DRY / maintenance |
| P2-014 README Complete Example warning | v1.1.0 | ✅ Done | LOW | Trivial | Security guidance |
| P2-015 ADMIN_QUERY_MAX_ROWS constant | v1.1.0 | ✅ Done | LOW | Trivial | Code quality |
| P2-016 Caching + UTF-8 in ED connector | v1.1.0 | ✅ Partial | LOW | Moderate | Feature parity |
| P2-017 Fix pingConnection for MS Access | v1.1.0 | ✅ Done | HIGH | Small | MS Access users broken |
| P2-018 UNION word-boundary match | v1.1.0 | ✅ Done | HIGH | Trivial | False-positive query blocks |
| P2-019 Escape buildConnectionString values | v1.1.0 | ✅ Done | MODERATE | Small | Connection string injection |
| P2-020 Call validateConfig() from connect() | v1.1.0 | ✅ Done | MINOR | Trivial | Clear config error messages |
| P2-021 Fix ED odbc_source driver lookup | v1.1.0 | ✅ Done | HIGH | Small | SQL Server via odbc_source broken |
| P2-022 Fix ExternalDataIntegration falsy check | v1.1.0 | ✅ Done | MINOR | Trivial | Config usability |
| P2-023 Log odbc_setoption() failure | v1.1.0 | ✅ Done | MINOR | Trivial | Operator diagnostics |
| P2-024 LRU eviction for connection pool | v1.1.0 | ✅ Done | MINOR | Moderate | Pool efficiency |
| P2-025 SECURITY.md v1.0.2/v1.0.3 history | Docs | ✅ Done | DOCS | Trivial | Operator risk awareness |
| P2-026 CHANGELOG v1.0.3 date | Docs | ✅ Done | DOCS | Trivial | Documentation accuracy |
| P2-027 README MAX_CONNECTIONS note | Docs | ✅ Done (v1.1.0) | DOCS | Trivial | Operator confusion |
| P2-028 Fix sanitize() trailing `\b` | v1.1.0 | ✅ Done | HIGH | Trivial | False-positive query blocks |
| P2-029 Correct Architecture.md errors | Docs | ✅ Done | HIGH | Small | Contributor accuracy / fatal errors if followed |
| P2-030 Fix wiki KI-008 description | Docs | ✅ Done | DOCS | Trivial | Editor diagnosis accuracy |
| P2-031 Fix README magic word version | Docs | ✅ Done | DOCS | Trivial | Documentation accuracy |
| P2-032 Remove KNOWN_ISSUES dup footer | Docs | ✅ Done | DOCS | Trivial | Presentation |
| P2-033 Fix UPGRADE.md $GLOBALS | Docs | ✅ Done (v1.1.0) | DOCS | Trivial | Documentation quality |
| P2-034 Progress OpenEdge support | v1.1.0 | ✅ Done | HIGH | Small | New database support |
| P2-035 Fix validateConfig() host key (KI-040) | v1.1.x | ✅ Done (v1.1.0) | HIGH | Trivial | Progress OpenEdge connection failure |
| P2-036 Date CHANGELOG v1.1.0 (KI-041) | Docs | ✅ Done | DOCS | Trivial | Documentation accuracy |
| P2-037 Fix Architecture.md buildConnectionString (KI-042) | Docs | ✅ Done | DOCS | Trivial | Factually wrong developer docs |
| P2-038 Remove stale KI-024 note from Security.md wiki (KI-043) | Docs | ✅ Done | DOCS | Trivial | Misleads editors |
| P2-039 Correct SECURITY.md row-limit description (KI-044) | Docs | ✅ Done | DOCS | Trivial | Factually wrong documentation |
| P2-040 Correct UPGRADE.md v1.0.1 magic word claim (KI-045) | Docs | ✅ Done | DOCS | Trivial | False assurance to operators on old versions |
| P2-041 Fix Parser-Functions.md data= required (KI-046) | Docs | ✅ Done | DOCS | Trivial | Documentation accuracy |
| P2-042 Correct KNOWN_ISSUES.md footer (KI-047) | Docs | ✅ Done | DOCS | Trivial | Tracking accuracy |
| P2-043 Fix KNOWN_ISSUES.md mojibake (KI-048) | Docs | ✅ Done | DOCS | Small | Presentation / readability |
| P2-044 Fix sanitize() keyword boundary + whitespace (KI-049) | v1.1.0 | ✅ Done | HIGH | Small | SQL injection blocklist evasion |
| P2-045 SpecialODBCAdmin Progress host/db display | v1.1.0 | ✅ Done | LOW | Trivial | Admin usability for Progress sources |
| P2-046 pingConnection() use withOdbcWarnings() | v1.1.0 | ✅ Done | LOW | Trivial | Code consistency / DRY |
| P2-047 Fix odbc-error-too-many-queries i18n message (KI-050) | Docs | ✅ Done (v1.2.0) | LOW | Trivial | Incorrect editor guidance |
| P2-048 Fix Architecture.md FIFO/LRU + WANObjectCache (KI-051) | Docs | ✅ Done (v1.2.0) | MEDIUM | Small | Contributor accuracy |
| P2-049 Update wiki/Known-Issues.md KI-020 (KI-052) | Docs | ✅ Done (v1.2.0) | LOW | Trivial | Documentation accuracy |
| P2-050 Fix $wgODBCMaxConnections "per source" x6 (KI-053) | Docs | ✅ Done (v1.2.0) | LOW | Small | Admin configuration clarity |
| P2-051 Make withOdbcWarnings() public; replace 5 raw closures | v1.2.0 | ✅ Done (v1.2.0) | LOW | Small | DRY / P2-008 completion |
| P2-052 Fix noparse/isHTML on odbcQuery() error returns (§5.2) | v1.2.0 | ✅ Done (v1.2.0) | MEDIUM | Trivial | Parser correctness |
| P2-053 Query timing + ODBCSlowQueryThreshold slow-query log | v1.2.0 | ✅ Done (v1.2.0) | MEDIUM | Small | Observability |
| P2-054 Replace callback with ExtensionRegistration hook (§3.7) | v1.3.0 | ✅ Done (v1.3.0) | LOW | Trivial | Forward-compat / deprecation removal |
| P2-055 Cache $mainConfig in ODBCQueryRunner constructor (§3.8) | v1.3.0 | ✅ Done (v1.3.0) | LOW | Trivial | Performance / DRY |
| P2-056 Enforce AllowArbitraryQueries in admin runTestQuery (§2.2) | v1.3.0 | ✅ Done (v1.3.0) | MEDIUM | Trivial | Security / consistency |
| P2-057 Log dropped data= mapping pairs (§5.6) | v1.3.0 | ✅ Done (v1.3.0) | LOW | Trivial | Diagnostics |
| P2-058 Remove deprecated cols attr from admin textarea (§5.5) | v1.3.0 | ✅ Done (v1.3.0) | LOW | Trivial | HTML5 compliance |
| P3-001 Service container | v2.0.0 | Open | HIGH | Large | Architecture |
| P3-002 Interfaces | v2.0.0 | Open | MEDIUM | Moderate | Testability |
| P3-003 Unit test suite | v2.0.0 | Open | HIGH | Large | Quality assurance |
| P3-004 CI + code standards | v2.0.0 | Open | MEDIUM | Moderate | Quality assurance |
| P3-006 Parameterized WHERE | v2.0.0 | Open | HIGH | Large | Security |

