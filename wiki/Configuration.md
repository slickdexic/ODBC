# Configuration

All ODBC configuration lives in `LocalSettings.php`. There are two categories: the `$wgODBCSources` array that defines individual database connections, and global settings that control extension behaviour.

---

## Data Sources — `$wgODBCSources`

Each entry in `$wgODBCSources` defines one database connection. The array key is the **source ID** — the string you pass to `source=` in parser functions on wiki pages.

```php
$wgODBCSources['my-source-id'] = [
    // ... connection options ...
];
```

### Connection Modes

There are three mutually exclusive ways to specify the connection. Use exactly one of these per source.

#### Mode 1 — System/User DSN

The DSN is pre-configured in the ODBC Data Source Administrator (Windows) or `/etc/odbc.ini` (Linux). The extension just provides the name.

```php
$wgODBCSources['my-access-db'] = [
    'dsn'      => 'MyAccessDSN',   // Name as registered in ODBC Data Source Administrator
    'user'     => '',
    'password' => '',
];
```

Best for: production deployments where the database administrator manages connections externally; passwords not stored in `LocalSettings.php`.

#### Mode 2 — Driver-based (recommended for most setups)

The extension builds a connection string from the individual parameters.

```php
$wgODBCSources['sql-server'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',  // Exact driver name as registered
    'server'   => 'db-server.example.com,1433',      // hostname or IP, optional port after comma
    'database' => 'MyDatabase',
    'user'     => 'wiki_reader',
    'password' => 'ReadOnlyPassword',
];
```

Required keys: `driver`, `server`, `database`. `user` and `password` can be omitted for Windows Integrated Authentication on SQL Server.

#### Mode 3 — Full Connection String

Pass a raw ODBC connection string. Use this for drivers or connection options not covered by Mode 2.

```php
$wgODBCSources['oracle-db'] = [
    'connection_string' => 'Driver={Oracle in OraDB19Home1};DBQ=oraserver:1521/ORCLPDB1;',
    'user'     => 'wiki_user',
    'password' => 'Oracle123',
];
```

> **Note:** Values containing `;`, `{`, or `}` in a connection string require ODBC-standard brace-escaping. Use Mode 1 (DSN) or pre-configure these in the ODBC Data Source Administrator to avoid escaping issues.

---

### Connection Options Reference

All options that can appear in a single `$wgODBCSources` entry:

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `dsn` | string | Mode 1 only | Name of a pre-configured System or User DSN |
| `driver` | string | Mode 2 only | Exact ODBC driver name as registered (see [[Supported-Databases]]) |
| `server` | string | Mode 2 | Host name or IP address; append port after a comma: `'host,1433'` |
| `database` | string | Mode 2 | Database/catalog name on the target server |
| `connection_string` | string | Mode 3 only | Full raw ODBC connection string |
| `user` | string | No | Database username. Omit for Windows Integrated Auth. |
| `password` | string | No | Database password. Omit for Windows Integrated Auth. |
| `port` | string | No | TCP port (alternative to appending to `server`). Not all drivers use this. |
| `timeout` | int | No | Query timeout in seconds for this source. Overrides `$wgODBCQueryTimeout`. |
| `allow_queries` | bool | No | If `true`, allow composed ad-hoc queries for this source even when `$wgODBCAllowArbitraryQueries` is globally `false`. Use sparingly. |
| `trust_certificate` | bool | No | If `true`, adds `TrustServerCertificate=yes` to the connection string (SQL Server only — suppresses SSL cert validation warnings for self-signed certs). |
| `charset` | string | No | Expected encoding of data returned by this source. When set (e.g. `'charset' => 'ISO-8859-1'`), automatic `mb_detect_encoding()` detection is skipped and all result strings are converted from this encoding to UTF-8. Valid values: any name accepted by `mb_convert_encoding()`. Available since v1.5.0. |
| `host` | string | No | **Progress OpenEdge only.** Host name or IP address. Maps to `Host=` in the connection string. Use instead of `server` when the driver is a Progress/OpenEdge driver. |
| `db` | string | No | **Progress OpenEdge only.** Database name. Maps to `DB=` in the connection string. Use instead of `database` when the driver is a Progress/OpenEdge driver. |
| `dsn_params` | array | No | Additional `key => value` pairs appended to the ODBC connection string. Useful for driver-specific options. |
| `prepared` | array | No | Named prepared statements. Format: `'query-name' => 'SELECT ... WHERE col = ?'`. See below. |

### Per-Source Examples

#### MySQL with port
```php
$wgODBCSources['mysql-reports'] = [
    'driver'   => 'MySQL ODBC 8.0 Unicode Driver',
    'server'   => '192.168.1.100',
    'database' => 'reports_db',
    'port'     => '3306',
    'user'     => 'readonly_user',
    'password' => 'secret',
];
```

#### SQL Server with extra DSN params and certificate trust
```php
$wgODBCSources['sql-server-prod'] = [
    'driver'            => 'ODBC Driver 18 for SQL Server',
    'server'            => 'prod-db.internal,1433',
    'database'          => 'Production',
    'user'              => 'wiki_svc',
    'password'          => 'SvcPassword!',
    'trust_certificate' => true,
    'timeout'           => 60,
    'dsn_params'        => [
        'Encrypt' => 'yes',
        'APP'     => 'MediaWiki-ODBC',
    ],
];
```

#### Source restricted to prepared statements only
```php
$wgODBCSources['hr-db'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'hr-server.local,1433',
    'database' => 'HumanResources',
    'user'     => 'wiki_hr_reader',
    'password' => 'HRReadOnly456',
    // No 'allow_queries' key here = only prepared statements allowed for this source
    'prepared' => [
        'employee_by_id'  => 'SELECT FirstName, LastName, Title, Department FROM Employees WHERE EmployeeID = ?',
        'dept_roster'     => 'SELECT FirstName, LastName, Email FROM Employees WHERE Department = ? ORDER BY LastName',
        'search_by_name'  => 'SELECT EmployeeID, FirstName, LastName, Department FROM Employees WHERE LastName LIKE ?',
    ],
];
```

#### Source that allows ad-hoc queries
```php
$wgODBCSources['public-catalogue'] = [
    'dsn'           => 'PublicCatalogueDSN',
    'allow_queries' => true,   // allow composed queries for this source only
];
```

---

## Prepared Statements

Prepared statements are the **recommended way** to query databases. They are defined in `$wgODBCSources` and called by name from wiki pages. The SQL is never exposed to wiki editors.

```php
'prepared' => [
    'query-name' => 'SELECT col1, col2 FROM table WHERE id = ?',
]
```

- `?` is the parameter placeholder (ODBC standard)
- Multiple `?` placeholders are supported; parameters are bound positionally
- Only `SELECT` statements should be used (the extension does not restrict this at the config level, but the read-only database user should)

On a wiki page:
```wiki
{{#odbc_query: source=hr-db
 | query=employee_by_id
 | parameters=42
 | data=first=FirstName,last=LastName,dept=Department
}}
```

See [[Parser-Functions#odbc_query]] for the full `parameters=` and `separator=` usage.

---

## Global Settings

All global settings are set in `LocalSettings.php` *before or after* `wfLoadExtension( 'ODBC' )`. Exception: `$wgODBCExternalDataIntegration` must be set **before** the extension loads.

### `$wgODBCAllowArbitraryQueries`

```php
$wgODBCAllowArbitraryQueries = false;  // default
```

When `false`, wiki pages can only use named prepared statements. When `true`, users with `odbc-query` permission can also compose ad-hoc `FROM` / `WHERE` / `ORDER BY` queries from wiki parameters.

> **Security warning:** Only set to `true` if you trust all users who have `odbc-query` permission. The extension's keyword blocklist is a defence-in-depth measure, not a substitute for prepared statements.

Individual sources can override this with `'allow_queries' => true` in `$wgODBCSources`, allowing ad-hoc queries for that source only regardless of this global setting.

### `$wgODBCMaxRows`

```php
$wgODBCMaxRows = 1000;  // default
```

Hard cap on the number of rows returned by any single query. The `limit=` parameter on a wiki page cannot exceed this value. Protects against runaway queries filling page memory.

### `$wgODBCQueryTimeout`

```php
$wgODBCQueryTimeout = 30;  // default: 30 seconds
```

Global query timeout in seconds. Per-source `timeout` keys override this for specific sources. Set to `0` to disable timeouts (not recommended for production).

> **Note:** Not all ODBC drivers support query timeouts. Drivers that do not support `SQL_QUERY_TIMEOUT` will silently ignore this setting. A warning is logged in the MediaWiki debug log when this happens.

### `$wgODBCCacheExpiry`

```php
$wgODBCCacheExpiry = 0;  // default: no caching
```

Seconds to cache query results using MediaWiki's object cache (`$wgMainCacheType`). When `0`, every page load runs a fresh query.

**Choosing a value:**
- `300` (5 minutes) — suitable for data that changes infrequently (reference tables, lookup data)
- `3600` (1 hour) — suitable for daily-updated reporting data
- `0` — always fresh; required for real-time data

Cache keys include the full SQL, all parameters, and the row limit. Different queries, different parameters, and different row limits each produce independent cache entries. Cache is invalidated by TTL expiry only — there is no manual invalidation.

### `$wgODBCMaxConnections`

```php
$wgODBCMaxConnections = 10;  // default
```

Maximum number of ODBC connection handles cached simultaneously across the connection pool. When the pool reaches this limit, the least-recently-used connection is closed before a new one is opened.

In most use cases the default of `10` is sufficient. Increase if you have many distinct sources accessed on the same page or under high concurrent load.

```php
$wgODBCMaxConnections = 25;
```

### `$wgODBCExternalDataIntegration`

```php
$wgODBCExternalDataIntegration = true;  // default
```

Controls whether the extension registers an `odbc_generic` connector for the External Data extension. When `true` and External Data is installed, its parser functions can use ODBC sources.

> **Important:** This setting is read at extension load time. It **must** be set **before** `wfLoadExtension( 'ODBC' )` to take effect. Setting it afterwards has no effect.

```php
// Disable ED integration — must come BEFORE wfLoadExtension:
$wgODBCExternalDataIntegration = false;
wfLoadExtension( 'ODBC' );
```

> **Note:** Any falsy value (`false`, `0`, `null`, `''`) disables External Data integration. This was corrected in v1.1.0 — you are not required to use the exact boolean `false`.

---

## Permissions

```php
// Allow logged-in users to run queries from wiki pages
$wgGroupPermissions['user']['odbc-query'] = true;

// Allow a custom group to access the admin interface
$wgGroupPermissions['dbadmin']['odbc-admin'] = true;
$wgGroupPermissions['dbadmin']['odbc-query']  = true;

// Revoke sysop access to the admin page (not typical, but possible)
$wgGroupPermissions['sysop']['odbc-admin'] = false;
```

| Right | Default Holders | What it grants |
|-------|----------------|----------------|
| `odbc-query` | `sysop` | Use parser functions (`{{#odbc_query:}}`, etc.) on wiki pages |
| `odbc-admin` | `sysop` | Access `Special:ODBCAdmin` for connections testing and table browsing |

---

## Minimal Production Configuration Example

```php
wfLoadExtension( 'ODBC' );

// --- Data Sources ---
$wgODBCSources['products'] = [
    'driver'   => 'MySQL ODBC 8.0 Unicode Driver',
    'server'   => 'db.internal',
    'database' => 'catalogue',
    'user'     => 'wiki_ro',
    'password' => getenv( 'WIKI_DB_PASSWORD' ),  // read from environment
    'prepared' => [
        'active_products' => 'SELECT Name, SKU, Price FROM Products WHERE Active = 1 ORDER BY Name',
        'product_detail'  => 'SELECT Name, SKU, Price, Description, Category FROM Products WHERE SKU = ?',
    ],
];

// --- Global Settings ---
$wgODBCAllowArbitraryQueries = false;  // prepared statements only
$wgODBCMaxRows               = 500;
$wgODBCQueryTimeout          = 15;
$wgODBCCacheExpiry           = 600;   // cache for 10 minutes

// --- Permissions ---
$wgGroupPermissions['editor']['odbc-query'] = true;
```
