# MediaWiki ODBC Extension

A MediaWiki extension that provides **ODBC database connectivity** with standalone parser functions and optional integration with the [External Data](https://www.mediawiki.org/wiki/Extension:External_Data) extension.

## Features

- **Standalone ODBC Parser Functions** — Query any ODBC-accessible database directly from wiki pages
- **External Data Integration** — Works as a connector for the External Data extension, adding generic ODBC support
- **Prepared Statements** — Define safe, reusable parameterized queries in configuration
- **Admin Special Page** — `Special:ODBCAdmin` for testing connections, browsing tables, and running test queries
- **SQL Injection Protection** — Blocks dangerous SQL patterns and supports prepared statements
- **Permission Control** — Configurable rights (`odbc-query`, `odbc-admin`) to restrict who can query databases
- **Flexible Connection Options** — Supports System DSN, Driver-based connections, and full connection strings
- **Query Result Caching** — Optional caching of query results to reduce database load

## Requirements

- MediaWiki 1.39+
- PHP 7.4+ with the **ODBC extension** (`ext-odbc`) enabled
- An ODBC driver for your target database (e.g., Microsoft ODBC Driver for SQL Server, MySQL ODBC Connector, PostgreSQL ODBC, etc.)

## Installation

1. **Copy the extension** into your MediaWiki `extensions/` directory:
   ```
   extensions/ODBC/
   ```

2. **Add to LocalSettings.php:**
   ```php
   wfLoadExtension( 'ODBC' );
   ```

3. **Configure your data sources** (see Configuration below).

4. **Ensure the PHP ODBC extension is installed:**
   - **Windows:** Usually included; enable `extension=php_odbc.dll` in `php.ini`
   - **Linux (Debian/Ubuntu):** `sudo apt install php-odbc`
   - **Linux (RHEL/CentOS):** `sudo yum install php-odbc`

## Configuration

### Important Security Note

**Credentials in `LocalSettings.php` are stored in plain text.** Ensure your `LocalSettings.php` file has proper file system permissions (readable only by the web server user). Consider using environment variables or a separate credentials file outside the web root for production deployments.

**Connection String Escaping:** If your passwords or other connection parameters contain special characters like `;` or `=`, they must be properly escaped. For passwords with special characters, consider using DSN-less connections or System DSNs configured through your ODBC administrator instead of embedding passwords in connection strings.

### Data Sources (`$wgODBCSources`)

Configure ODBC data sources in `LocalSettings.php`. Each source gets a unique ID:

#### Using a System/User DSN (configured in ODBC Data Source Administrator):
```php
$wgODBCSources['my-access-db'] = [
    'dsn'      => 'MyAccessDSN',
    'user'     => '',
    'password' => '',
];
```

#### Using an ODBC Driver (driver-based connection string):
```php
$wgODBCSources['sql-server'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'localhost,1433',
    'database' => 'MyDatabase',
    'user'     => 'sa',
    'password' => 'YourPassword123',
    'trust_certificate' => true,  // optional: trust self-signed SSL certs
];
```

#### Using a full connection string:
```php
$wgODBCSources['oracle-db'] = [
    'connection_string' => 'Driver={Oracle in OraDB19Home1};DBQ=myserver:1521/myservice;',
    'user'     => 'myuser',
    'password' => 'mypass',
];
```

#### MySQL via ODBC:
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

#### With Prepared Statements (recommended for security):
```php
$wgODBCSources['employees'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'hr-server.local,1433',
    'database' => 'HumanResources',
    'user'     => 'wiki_reader',
    'password' => 'ReadOnly123',
    'prepared' => [
        'get_employee' => 'SELECT FirstName, LastName, Department FROM Employees WHERE EmployeeID = ?',
        'dept_list'    => 'SELECT DISTINCT Department FROM Employees ORDER BY Department',
        'search'       => 'SELECT FirstName, LastName, Title FROM Employees WHERE Department = ? AND Title LIKE ?',
    ],
];
```

#### Per-Source Advanced Options

Each source can include additional optional settings:

```php
$wgODBCSources['advanced-example'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'db-server.local,1433',
    'database' => 'MyDB',
    'user'     => 'readonly',
    'password' => 'secret',

    // Per-source query timeout in seconds (overrides $wgODBCQueryTimeout).
    'timeout' => 60,

    // Allow composed (ad-hoc) queries for this source only,
    // even if $wgODBCAllowArbitraryQueries is false globally.
    // WARNING: Only enable for trusted data sources; this bypasses the prepared statement requirement.
    'allow_queries' => true,

    // Extra DSN parameters appended to the connection string.
    // Useful for driver-specific options not covered above.
    'dsn_params' => [
        'Encrypt'   => 'yes',
        'APP'       => 'MediaWiki',
    ],

    // Trust self-signed SSL certificates (SQL Server).
    'trust_certificate' => true,
    
    // Named prepared statements (recommended for security).
    'prepared' => [
        'get_user' => 'SELECT * FROM users WHERE id = ?',
        'search' => 'SELECT * FROM items WHERE category = ? AND status = ?',
    ],
];
```

| Per-Source Option | Description |
|-------------------|-------------|
| `timeout` | Query timeout in seconds; overrides the global `$wgODBCQueryTimeout`. |
| `allow_queries` | If `true`, allows composed queries for this source even when `$wgODBCAllowArbitraryQueries` is `false`. **WARNING:** Only enable for data sources you fully trust. |
| `dsn_params` | Associative array of extra key=value pairs appended to the driver connection string. |
| `trust_certificate` | If `true`, adds `TrustServerCertificate=yes` to the connection string (useful for SQL Server with self-signed certs). |
| `prepared` | Associative array of named prepared SQL statements for this source. Format: `'name' => 'SQL with ? placeholders'` |

### Global Settings

```php
// Allow arbitrary (ad-hoc) SQL queries from wiki pages (default: false)
// WARNING: Only enable this if you trust all users with odbc-query permission!
$wgODBCAllowArbitraryQueries = false;

// Maximum rows returned per query (default: 1000)
$wgODBCMaxRows = 1000;

// Query timeout in seconds (default: 30)
$wgODBCQueryTimeout = 30;

// Cache expiry for query results in seconds (default: 0 = no caching).
// When set to a positive value, query results are cached using MediaWiki's
// object cache (configured via $wgMainCacheType). This reduces database
// load for frequently-run queries but means results may be stale.
// Cache keys include the SQL, parameters, and row limit, so different queries
// or limits create separate cache entries. There is no manual cache invalidation;
// entries expire automatically after this duration. Choose based on your data
// update frequency and staleness tolerance.
$wgODBCCacheExpiry = 0;

// Enable External Data extension integration (default: true).
// Set to false BEFORE calling wfLoadExtension('ODBC') to disable.
$wgODBCExternalDataIntegration = true;

// Maximum {{#odbc_query:}} calls per page render (default: 0 = unlimited).
// Set to a positive integer to cap resource use from template-driven query floods.
$wgODBCMaxQueriesPerPage = 0;

// Slow-query log threshold in seconds (default: 0 = disabled).
// When set to a positive float (e.g. 2.0), any query whose combined
// execute+fetch time exceeds this value is logged to the 'odbc-slow' log channel.
// Enable the channel in LocalSettings.php:
//   $wgDebugLogGroups['odbc-slow'] = '/var/log/mediawiki/odbc-slow.log';
$wgODBCSlowQueryThreshold = 0;
```

### Permissions

By default, only sysops can use ODBC functions. Grant to other groups as needed:

```php
// Allow all logged-in users to run ODBC queries
$wgGroupPermissions['user']['odbc-query'] = true;

// Keep admin page restricted to sysops (default)
// odbc-admin allows access to Special:ODBCAdmin for testing and browsing
$wgGroupPermissions['sysop']['odbc-admin'] = true;
$wgGroupPermissions['sysop']['odbc-query'] = true;
```

**Permission Model:**
- `odbc-query`: Required to use parser functions (`{{#odbc_query:}}`, etc.) on wiki pages
- `odbc-admin`: Required to access `Special:ODBCAdmin` for connection testing, table browsing, and running test queries

Both permissions are independent. Users can have `odbc-query` without `odbc-admin` (can query from pages but not use admin interface) or vice versa.

## Parser Functions

### `{{#odbc_query:}}` — Fetch Data

Retrieves data from an ODBC source and stores it in page-scoped variables.

#### Composed Query Mode (ad-hoc SQL):
```wiki
{{#odbc_query: source=my-source
 | from=Employees
 | data=name=FullName,dept=Department,email=Email
 | where=Active=1
 | order by=FullName ASC
 | limit=50
}}
```

#### Prepared Statement Mode (recommended):
```wiki
{{#odbc_query: source=employees
 | query=get_employee
 | parameters=12345
 | data=first=FirstName,last=LastName,dept=Department
}}
```

**Parameters:**

| Parameter | Description |
|-----------|-------------|
| `source=` | The source ID from `$wgODBCSources`. Also accepted as the first positional argument: `{{#odbc_query: mydb \| from=...}}` is equivalent to `{{#odbc_query: source=mydb \| from=...}}`. |
| `from=` | Table name(s) for the FROM clause |
| `data=` | Column-to-variable mappings: `localVar=dbColumn,...` |
| `where=` | WHERE clause conditions |
| `order by=` | ORDER BY clause |
| `group by=` | GROUP BY clause |
| `having=` | HAVING clause |
| `limit=` | Maximum rows to return (capped by `$wgODBCMaxRows`) |
| `query=` / `prepared=` | Name of a prepared statement (defined in source config) |
| `parameters=` | Separated list of parameters for a prepared statement (e.g., `12345` or `value1,value2`). Default separator is `,`. **Values containing commas** must use `separator=` to specify a different delimiter (see below). |
| `separator=` | Delimiter used to split `parameters=` values. Default: `,`. Use `separator=\|` (or any other char) when parameter values contain commas, e.g. for names like `Smith, John`. |
| `suppress error` | Suppress error messages on failure (returns empty result instead) |

### `{{#odbc_value:}}` — Display Single Value

Displays a stored variable's value. By default returns the first row; pass an optional third argument to select a specific row:

```wiki
Employee: {{#odbc_value:name}}
Department: {{#odbc_value:dept}}
Default: {{#odbc_value:missing_var|N/A}}
Row 2:    {{#odbc_value:name|N/A|2}}
Last row: {{#odbc_value:name|N/A|last}}
```

The row selector is 1-indexed. `last` returns the final row. Out-of-range values silently fall back to the default.

### `{{#for_odbc_table:}}` — Loop with Inline Template

Iterate over all rows with inline wikitext:

```wiki
{| class="wikitable"
! Name !! Department !! Email
{{#for_odbc_table:
{{!}}-
{{!}} {{{name}}} {{!}}{{!}} {{{dept}}} {{!}}{{!}} {{{email}}}
}}
|}
```

### `{{#display_odbc_table:}}` — Loop with Wiki Template

Iterate over rows using a wiki template:

```wiki
{{#display_odbc_table: template=EmployeeRow }}
```

This calls `{{EmployeeRow|name=...|dept=...|email=...}}` for each row.

### `{{#odbc_clear:}}` — Clear Stored Data

```wiki
{{#odbc_clear:}}           <!-- Clear all stored data -->
{{#odbc_clear:name,dept}}  <!-- Clear specific variables -->
```

## Complete Example

> **Warning — this example enables features that should NOT be used in production:**
> - `$wgODBCAllowArbitraryQueries = true` allows wiki editors to construct arbitrary SQL queries. **Leave this `false`** unless every user with `odbc-query` permission is fully trusted.
> - `$wgGroupPermissions['user']['odbc-query'] = true` grants all logged-in users the ability to query the database. In production, restrict this to a dedicated trusted group.
>
> **Recommended production setup:** define all queries as `prepared` statements in `$wgODBCSources`, keep `$wgODBCAllowArbitraryQueries = false`, and grant `odbc-query` only to specific trusted groups (e.g., `$wgGroupPermissions['sysop']['odbc-query'] = true`).

### LocalSettings.php
```php
wfLoadExtension( 'ODBC' );

$wgODBCSources['northwind'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'localhost,1433',
    'database' => 'Northwind',
    'user'     => 'wiki_user',
    'password' => 'secret123',
    'prepared' => [
        'products_by_category' => 'SELECT ProductName, UnitPrice FROM Products WHERE CategoryID = ? ORDER BY ProductName',
    ],
];

$wgODBCAllowArbitraryQueries = true;
$wgGroupPermissions['user']['odbc-query'] = true;
```

### Wiki Page
```wiki
== Products in Category 1 ==

{{#odbc_query: source=northwind
 | query=products_by_category
 | parameters=1
 | data=product=ProductName,price=UnitPrice
}}

{| class="wikitable sortable"
! Product !! Price
{{#for_odbc_table:
{{!}}-
{{!}} {{{product}}} {{!}}{{!}} ${{{price}}}
}}
|}

{{#odbc_clear:}}

== All Categories ==

{{#odbc_query: source=northwind
 | from=Categories
 | data=id=CategoryID,catname=CategoryName,desc=Description
 | order by=CategoryName
}}

{{#display_odbc_table: template=CategoryCard }}

{{#odbc_clear:}}
```

## External Data Integration

When the External Data extension is installed and `$wgODBCExternalDataIntegration` is `true`, this extension registers an `odbc_generic` connector type. You can then use External Data's parser functions with ODBC sources.

> **Important:** If you want to disable the integration, set `$wgODBCExternalDataIntegration = false;` **before** calling `wfLoadExtension( 'ODBC' );` in `LocalSettings.php`. The connector is registered at load time.

### Configuration
```php
wfLoadExtension( 'ExternalData' );
wfLoadExtension( 'ODBC' );

// Configure via External Data's system.
// Note: use 'name' for the database name (External Data convention):
$wgExternalDataSources['my-odbc'] = [
    'type'     => 'odbc_generic',
    'driver'   => 'MySQL ODBC 8.0 Unicode Driver',
    'server'   => 'mysql-server.local',
    'name'     => 'production_db',   // maps to 'Database' in the connection string
    'user'     => 'readonly',
    'password' => 'secret',
];

// OR reference an $wgODBCSources entry:
$wgODBCSources['my-source'] = [ /* ... */ ];
$wgExternalDataSources['my-source'] = [
    'type'         => 'odbc_generic',
    'odbc_source'  => 'my-source',   // references the $wgODBCSources key
    'user'         => '',
    'password'     => '',
];
```

### Usage with External Data
```wiki
{{#get_db_data: db=my-odbc
 | from=users
 | data=username=user_name,email=user_email
 | where=active=1
 | limit=10
}}

{| class="wikitable"
! Username !! Email
{{#for_external_table:
{{!}}-
{{!}} {{{username}}} {{!}}{{!}} {{{email}}}
}}
|}
```

## Admin Interface

Navigate to **Special:ODBCAdmin** (requires `odbc-admin` permission) to:

- **View all configured sources** with driver, server, and database details
- **Test connections** — verify that ODBC connectivity works
- **Browse tables** — see what tables are available in each source
- **View columns** — inspect table structure
- **Run test queries** — execute SELECT queries and see results in a table (non-SELECT statements are blocked; the same SQL sanitization rules apply)

## Supported Databases

Any database with an ODBC driver should work, including:

| Database | Common ODBC Driver |
|----------|-------------------|
| Microsoft SQL Server | `ODBC Driver 17 for SQL Server` / `ODBC Driver 18 for SQL Server` |
| MySQL / MariaDB | `MySQL ODBC 8.0 Unicode Driver` |
| PostgreSQL | `PostgreSQL Unicode` |
| Oracle | `Oracle in OraDB19Home1` |
| Microsoft Access | `Microsoft Access Driver (*.mdb, *.accdb)` |
| IBM DB2 | `IBM DB2 ODBC DRIVER` |
| SQLite | `SQLite3 ODBC Driver` |
| SAP HANA | `HDBODBC` |
| Snowflake | `SnowflakeDSIIDriver` |
| Amazon Redshift | `Amazon Redshift (x64)` |

## File Structure

```
ODBC/
├── extension.json                         # Extension manifest
├── ODBCMagic.php                          # Magic word definitions for parser functions
├── composer.json                          # Composer metadata
├── README.md                              # This file
├── CHANGELOG.md                           # Version history
├── SECURITY.md                            # Security policy
├── UPGRADE.md                             # Upgrade notes
├── i18n/
│   ├── en.json                            # English messages
│   └── qqq.json                           # Message documentation
└── includes/
    ├── ODBCHooks.php                      # Hook handlers and registration
    ├── ODBCParserFunctions.php            # Parser function implementations
    ├── ODBCConnectionManager.php          # Connection pooling and DSN construction
    ├── ODBCQueryRunner.php                # Query execution and sanitization
    ├── connectors/
    │   └── EDConnectorOdbcGeneric.php     # External Data extension bridge
    └── specials/
        └── SpecialODBCAdmin.php           # Special:ODBCAdmin admin page
```

## Security Considerations

### Critical Security Practices

1. **Use prepared statements** whenever possible — they prevent SQL injection by design and are the recommended approach for production use
2. **Keep `$wgODBCAllowArbitraryQueries = false`** (the default) in production environments unless you fully trust all users with `odbc-query` permission
3. **Per-source `allow_queries` should be used sparingly** — only enable for specific trusted data sources where prepared statements are impractical
4. **Use a read-only database user** — create a database account with only SELECT privileges to limit potential damage
5. **Restrict permissions** — only grant `odbc-query` to trusted user groups; consider requiring login at minimum
6. **Secure your credentials** — `LocalSettings.php` contains plain-text passwords; ensure proper file permissions and consider separating credentials
7. **The extension blocks dangerous patterns** (DROP, DELETE, TRUNCATE, GRANT, EXEC, etc.) in composed queries, but this is defense-in-depth, not a substitute for prepared statements

### What the Extension Does

- **SQL injection protection**: Blocks dangerous SQL keywords and patterns in ad-hoc queries
- **Identifier validation**: Column and table names are validated to contain only safe characters
- **Error message sanitization**: Query details are logged server-side; only ODBC driver errors are shown to users (passwords are stripped from error messages)
- **Admin interface enforcement**: `Special:ODBCAdmin` enforces SELECT-only queries even for administrators
- **CSRF protection**: All state-changing actions in the admin interface require a valid session token
- **Query logging**: All executed queries are logged to the debug log for audit trails
- **Connection pooling limits**: Maximum of `$wgODBCMaxConnections` (default: 10) cached connections across all sources combined to prevent resource exhaustion

## Troubleshooting

### "ODBC extension not found"
Install the PHP ODBC extension for your platform (see Installation).

Verify installation:
- **Check phpinfo()**: Look for an "odbc" section
- **Command line**: `php -m | grep odbc`
- **Restart web server** after installing the extension

### "Could not connect"
- **Verify the ODBC driver is installed**: 
  - Linux: check `odbcinst -j` and `odbcinst -q -d`
  - Windows: check ODBC Data Source Administrator (odbcad32.exe)
- **Test the DSN outside of MediaWiki**:
  - Linux: `isql DSN_NAME username password`
  - Windows: use the "Test Connection" button in ODBC Data Source Administrator
- **Check firewall rules** if connecting to a remote server
- **Verify credentials** - check username, password, and permissions
- **Check server name and port** - ensure the database server is accessible
- **Review error logs** - check MediaWiki debug log and ODBC driver logs

### "Arbitrary SQL not allowed"
Either:
- Set `$wgODBCAllowArbitraryQueries = true` globally (not recommended for production), OR
- Set `'allow_queries' => true` on the specific source in `$wgODBCSources` (use sparingly), OR  
- Define prepared statements in your source configuration (recommended)

### "Invalid identifier" or "Illegal SQL pattern"
The query contains characters or keywords that are blocked for security:
- **Identifiers** (table/column names) can only contain alphanumeric characters, underscores, and dots
- **Dangerous keywords** are blocked: DROP, DELETE, INSERT, UPDATE, EXEC, etc.
- **SQL injection patterns** are blocked: `--`, `/*`, semicolons, etc.
- **Solution**: Use prepared statements or ensure your query only uses allowed patterns

### Query returns no results but should
- **Check case sensitivity**: Some databases are case-sensitive for table/column names
- **Verify data mappings**: The `data=` parameter maps local variables to database columns
- **Check WHERE conditions**: The `where=` parameter may be too restrictive
- **Review column names**: Use Special:ODBCAdmin to browse table structure
- **Check query limit**: Default is 1000 rows (`$wgODBCMaxRows`)

### Performance issues
- **Enable query caching**: Set `$wgODBCCacheExpiry` to a positive value (e.g., 300 for 5 minutes)
- **Add database indexes**: Ensure frequently queried columns are indexed
- **Limit result sets**: Use the `limit=` parameter to reduce rows returned
- **Optimize queries**: Use prepared statements with appropriate WHERE clauses
- **Check connection pooling**: The connection pool defaults to 10 simultaneous connections across all sources combined. Increase by setting `$wgODBCMaxConnections` in `LocalSettings.php` (e.g., `$wgODBCMaxConnections = 20;`)

### "Connection test failed" in admin interface
- The connection may be alive but returning an error code
- Check the error message details for specific ODBC driver errors
- Verify the source configuration in `$wgODBCSources`
- Test with a simple SELECT query instead of just connection test

### Magic words not working (case sensitivity)
- Ensure you're using the correct case: `{{#odbc_query:}}` (lowercase)
- After updating to version 1.0.3+, uppercase variants also work: `{{#ODBC_QUERY:}}`
- To clear the parser cache for a specific page, perform a null edit or run: `php maintenance/purgeParserCache.php`

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.
