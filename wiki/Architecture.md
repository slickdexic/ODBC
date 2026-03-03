# Architecture

This page describes the internal structure of the ODBC extension for developers and contributors.

---

## Code Structure

```
ODBC/
├── extension.json                     # Extension manifest, config declarations, hook registrations
├── ODBCMagic.php                      # Magic word definitions (parser function names)
├── composer.json                      # Composer metadata
└── includes/
    ├── ODBCHooks.php                  # Extension registration, hook handlers
    ├── ODBCParserFunctions.php        # Parser function logic (#odbc_query, etc.)
    ├── ODBCConnectionManager.php      # ODBC connection pooling and DSN building
    ├── ODBCQueryRunner.php            # Query execution, SQL sanitization, caching
    ├── connectors/
    │   └── EDConnectorOdbcGeneric.php # External Data extension bridge
    └── specials/
        └── SpecialODBCAdmin.php       # Special:ODBCAdmin admin page
```

---

## Component Responsibilities

### `ODBCHooks`

**File:** `includes/ODBCHooks.php`

The entry point for the extension. Called by MediaWiki at load time via the `callback` key in `extension.json`.

- **`onRegistration()`** — Called when the extension registers. Conditionally registers the External Data `odbc_generic` connector if the ED extension is present and integration is enabled.
- **`onParserFirstCallInit()`** — Registers all five parser functions with the Parser object.

### `ODBCParserFunctions`

**File:** `includes/ODBCParserFunctions.php`

Implements all five parser functions. This class is the main integration point between wiki editor input and the database layer.

**Key methods:**
- `odbcQuery()` — Handles `{{#odbc_query:}}`. Parses parameters, dispatches to either `ODBCQueryRunner::executeComposed()` or `ODBCQueryRunner::executePrepared()`, stores results in `ParserOutput` extension data.
- `odbcValue()` — Handles `{{#odbc_value:}}`. Reads from `ParserOutput` and returns the first value.
- `forOdbcTable()` — Handles `{{#for_odbc_table:}}`. Iterates over stored rows, substitutes `{{{vars}}}` in the template string.
- `displayOdbcTable()` — Handles `{{#display_odbc_table:}}`. Assembles a wikitext template call string (e.g., `{{TemplateName|col=val|...}}`) and returns it to the parser for normal page-level template expansion.
- `odbcClear()` — Handles `{{#odbc_clear:}}`. Clears some or all entries from `ParserOutput` extension data.

**Data storage:**
Results are stored per-page-render in `ParserOutput::setExtensionData( 'ODBCData', [...] )`. The stored structure is:
```php
[
    'variableName' => [ 'row0value', 'row1value', 'row2value', ... ],
    ...
]
```

### `ODBCConnectionManager`

**File:** `includes/ODBCConnectionManager.php`

Manages the ODBC connection pool. All methods are static — this is a limitation of the current design (see P3-001 in the improvement plan).

**Key methods:**
- `connect( $sourceId )` — Validates config, returns an ODBC connection handle. Either returns a cached handle (after a liveness ping) or opens a new connection. When the pool is full, evicts the least-recently-used connection (LRU via `asort($lastUsed)` + `array_key_first()`).
- `disconnect( $sourceId )` — Closes and removes a specific connection from the pool.
- `buildConnectionString( $config )` — Builds an ODBC connection string from the supplied config. Handles all three modes: a `connection_string` key is returned as-is (Mode 3 / full string passthrough); a `dsn` key without a `driver` key is returned as-is (Mode 1 / plain DSN name); otherwise, a `DRIVER=…;SERVER=…;DATABASE=…` string is constructed with all values escaped per the ODBC specification (Mode 2 / driver mode). In Mode 2, Progress OpenEdge-style `host`/`db` keys are mapped to `Host=` and `DB=` respectively.
- `validateConfig( $config )` — Validates that a source config has required keys. Called from `connect()` before any connection attempt.
- `testConnection( $sourceId )` — Opens a connection and immediately closes it, returning success/error info.
- `pingConnection( $conn, $config )` — Quick liveness probe. Uses `SELECT 1 FROM MSysObjects WHERE 1=0` for MS Access drivers; `SELECT 1` for all others.

**Connection pool:**
Connections are keyed by source ID in a static array. The pool uses LRU eviction (`asort($lastUsed)` + `array_key_first()`) when `$wgODBCMaxConnections` is exceeded — the least-recently-used handle is evicted first.

### `ODBCQueryRunner`

**File:** `includes/ODBCQueryRunner.php`

Handles SQL execution, result fetching, sanitization, and caching. `ODBCQueryRunner` is instance-based (constructed with a source ID and config); only the helper methods `sanitize()`, `validateIdentifier()`, `getRowLimitStyle()`, and `requiresTopSyntax()` are static.

**Key methods:**
- `executeComposed( $from, $columns, $where, ... )` — Builds and runs a composed SELECT statement.
- `executePrepared( $queryName, $params )` — Looks up a named prepared statement and calls `odbc_prepare()` / `odbc_execute()`.
- `executeRawQuery( $sql, $params, $maxRows )` — Core execution method. Applies caching logic (`$wgODBCCacheExpiry`), calls connection manager, executes the query, fetches results.
- `sanitize( $sql )` — Applies the keyword blocklist with word-boundary matching and throws `MWException` on a match. Called only for composed queries, not prepared statements.
- `validateIdentifier( $name )` — Validates that a table or column name matches `[A-Za-z0-9_.]` only.
- `getRowLimitStyle( $config )` — Returns `'top'`, `'first'`, or `'limit'` based on the driver name. SQL Server → `top`; Progress OpenEdge → `first`; all others → `limit`.
- `requiresTopSyntax( $config )` — **Deprecated since v1.1.0.** Thin wrapper around `getRowLimitStyle()`.
- `getTableColumns( $tableName )` — Returns column metadata for a table via `odbc_columns()`.
- `getTables()` — Returns the list of tables via `odbc_tables()`.

**SQL sanitization flow (composed queries):**
```
user input (where= / from= / etc.)
    ↓
validateIdentifier() — table/column names
    ↓
sanitize() — keyword blocklist
    ↓
executeRawQuery() — with caching
    ↓
ODBCConnectionManager::connect()
    ↓
odbc_exec() / odbc_execute()
```

### `SpecialODBCAdmin`

**File:** `includes/specials/SpecialODBCAdmin.php`

Implements the `Special:ODBCAdmin` page. Extends `SpecialPage`. 

Actions are dispatched based on the `action` GET/POST parameter:
- `(none)` — shows source list
- `test` — tests a connection
- `tables` — lists tables in a source
- `columns` — lists columns in a table
- `query` — shows/runs a test query form (POST required for execution; CSRF token validated)

All HTML output uses MediaWiki's `Html` helper and is properly escaped. Table and test query actions use `ODBCQueryRunner::getTables()`, `getTableColumns()`, and `executeRawQuery()` respectively.

### `EDConnectorOdbcGeneric`

**File:** `includes/connectors/EDConnectorOdbcGeneric.php`

Bridges the External Data extension by extending `EDConnectorComposed`. Translates ED's source configuration and query parameters into ODBC calls.

Key behaviour:
- `setCredentials()` — reads connection config from `$wgExternalDataSources` entry (or from referenced `$wgODBCSources` entry via `odbc_source` key). Driver name is automatically inherited from the referenced `$wgODBCSources` entry.
- `getQuery()` — builds the SELECT SQL, choosing `TOP`, `FIRST`, or `LIMIT` syntax via `ODBCQueryRunner::getRowLimitStyle()`.
- `fetch()` — opens the ODBC connection, executes the SQL via `odbc_exec()`, fetches rows. Enforces `$wgODBCMaxRows`.

---

## Data Flow: Wiki Page to Database and Back

```
1. Wiki editor writes:
   {{#odbc_query: source=hr | query=all_employees | data=name=FullName }}

2. MediaWiki parser calls ODBCParserFunctions::odbcQuery()

3. ODBCParserFunctions parses parameters:
   - source = 'hr'
   - query name = 'all_employees' (prepared mode)
   - mappings = ['name' => 'FullName']

4. Calls ODBCQueryRunner::executePrepared('hr', 'all_employees', [], 1000)

5. ODBCQueryRunner checks cache (if $wgODBCCacheExpiry > 0)
   - Cache hit: return cached rows
   - Cache miss: continue

6. ODBCQueryRunner calls ODBCConnectionManager::connect('hr')

7. ODBCConnectionManager checks pool for 'hr'
   - Pool hit: ping existing connection; return handle if alive
   - Pool miss / dead connection: open new ODBC connection to SQL Server

8. ODBCQueryRunner calls odbc_prepare() with the stored SQL,
   then odbc_execute() with the parameters

9. ODBCQueryRunner fetches rows up to $wgODBCMaxRows

10. Results stored in cache (if caching enabled)

11. Results returned to ODBCParserFunctions

12. ODBCParserFunctions maps column values to local variable names:
    row['FullName'] → stored as parser output variable 'name'

13. Results stored in ParserOutput::setExtensionData('ODBCData', ...)

14. Later on the page, {{#for_odbc_table: {{{name}}} calls back into
    ODBCParserFunctions::forOdbcTable(), which reads 'ODBCData'
    and substitutes values into the template string row by row

15. The expanded wikitext is returned to the parser as the
    substitution for {{#for_odbc_table:...}}
```

---

## Design Limitations

The current architecture has several known design limitations that are being addressed in the improvement plan:

| Limitation | Impact | Planned fix |
|------------|--------|-------------|
| All-static classes | Not testable; hard to mock; global state | P3-001: Convert to MediaWiki Services |
| No PHP namespaces | Legacy `AutoloadClasses` instead of PSR-4 `AutoloadNamespaces` | Part of P3-001 |
| No interfaces | Cannot mock or substitute implementations in tests | P3-002 |
| No unit tests | No regression protection | P3-003 |
| ~~`validateConfig()` is dead code~~ | Fixed in v1.1.0: `validateConfig()` now called from `connect()` | — |
| ~~FIFO connection eviction~~ | ~~Oldest entries evicted even if recently active~~ | ✅ Fixed in v1.1.0 (P2-024): LRU eviction now live |
| ~~Connection ping fails on MS Access~~ | Fixed in v1.1.0: driver-aware probe using `MSysObjects` | — |
| ~~ED connector `odbc_source` ignores driver~~ | Fixed in v1.1.0: driver inherited from `$wgODBCSources` | — |

See [improvement_plan.md](https://github.com/slickdexic/ODBC/blob/main/improvement_plan.md) for the full plan.

---

## Extension Registration Flow

```
LocalSettings.php calls wfLoadExtension('ODBC')
    ↓
extension.json is read by MediaWiki
    ↓
ODBCHooks::onRegistration() is called (extension 'callback')
    - Checks $wgODBCExternalDataIntegration
    - If true and ED is loaded, registers EDConnectorOdbcGeneric
    ↓
MediaWiki hooks are registered:
    ParserFirstCallInit → ODBCHooks::onParserFirstCallInit()
    ↓
At parse time:
    Parser::firstCallInit fires
    → ODBCHooks::onParserFirstCallInit()
    → Parser::setFunctionHook() called for each of the 5 functions
```

---

## Caching Implementation

Query result caching uses MediaWiki's **node-local object cache** (`ObjectCache::getLocalClusterInstance()`). On most single-server deployments this is APCu (in-process memory). It is **not** WANObjectCache and is not shared across application servers in a clustered deployment.

**Cache key composition:**
```
md5( $sql . '|' . json_encode( $params ) . '|' . $maxRows )
```
Prefixed with the MediaWiki object cache key prefix. Different SQL, parameters, or max-row counts produce independent cache entries.

**Cache invalidation:** Time-based only (TTL = `$wgODBCCacheExpiry` seconds). No event-based invalidation. Stale data can be read until the cache entry expires.
