# Supported Databases

The ODBC extension works with any database that has an ODBC driver available for the web server's operating system. The extension itself does not bundle any drivers — you must install the appropriate driver separately.

---

## Supported Database Matrix

| Database | Status | Notes |
|----------|--------|-------|
| Microsoft SQL Server | ✅ Supported | Use Driver 17 or 18 |
| MySQL 5.7+ | ✅ Supported | Use Unicode driver |
| MariaDB | ✅ Supported | Use MySQL ODBC driver |
| PostgreSQL 9.6+ | ✅ Supported | Use psqlODBC |
| Oracle 12c+ | ✅ Supported | Use Oracle Instant Client ODBC |
| Progress OpenEdge 11+ | ✅ Supported | Uses `SELECT FIRST n` syntax; use `host=` and `db=` config keys |
| Microsoft Access (Jet/ACE) | ⚠️ Limited | Connection ping uses `MSysObjects` fallback; see Access notes below |
| IBM DB2 | ✅ Supported | Limited testing |
| SQLite | ✅ Supported | Requires SQLite3 ODBC driver |
| SAP HANA | ✅ Supported | Requires HDBODBC driver |
| Snowflake | ✅ Supported | Requires Snowflake ODBC driver |
| Amazon Redshift | ✅ Supported | Requires Amazon Redshift ODBC driver |
| Sybase ASE | ✅ Supported | Use Adaptive Server ODBC |
| Teradata | ✅ Supported | Requires Teradata ODBC driver |

"Supported" means the ODBC extension's PHP code is compatible with this driver. The underlying database and ODBC driver must be installed and configured separately.

---

## Driver Names and Configuration

### Microsoft SQL Server

```php
$wgODBCSources['sql-server'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',   // or 'ODBC Driver 18 for SQL Server'
    'server'   => 'db.example.com,1433',
    'database' => 'MyDB',
    'user'     => 'readonly_user',
    'password' => 'password',
];
```

**Common driver names:**
- `ODBC Driver 17 for SQL Server` (SQL Server 2012–2019)
- `ODBC Driver 18 for SQL Server` (SQL Server 2019+, requires explicit `Encrypt=no` or TLS cert for older servers)
- `SQL Server Native Client 11.0` (legacy — deprecated)

**Download:** [Microsoft ODBC Driver for SQL Server](https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server)

**Linux installation (Ubuntu):**
```bash
curl https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
curl https://packages.microsoft.com/config/ubuntu/22.04/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt update
sudo ACCEPT_EULA=Y apt install msodbcsql17
```

---

### MySQL / MariaDB

```php
$wgODBCSources['mysql'] = [
    'driver'   => 'MySQL ODBC 8.0 Unicode Driver',
    'server'   => '192.168.1.100',
    'database' => 'mydb',
    'port'     => '3306',
    'user'     => 'readonly',
    'password' => 'pw',
];
```

**Common driver names:**
- `MySQL ODBC 8.0 Unicode Driver`
- `MySQL ODBC 8.0 ANSI Driver` (use Unicode for proper UTF-8 support)
- `MySQL ODBC 5.3 Unicode Driver` (for legacy installations)

**Download:** [MySQL Connector/ODBC](https://dev.mysql.com/downloads/connector/odbc/)

---

### PostgreSQL

```php
$wgODBCSources['postgres'] = [
    'driver'   => 'PostgreSQL Unicode',
    'server'   => 'pg-server.example.com',
    'database' => 'mydb',
    'user'     => 'wiki_reader',
    'password' => 'secret',
];
```

**Common driver names:**
- `PostgreSQL Unicode`
- `PostgreSQL Unicode(x64)` (64-bit Windows)
- `PostgreSQL ANSI` (avoid — use Unicode variant)

**Download:** [psqlODBC](https://odbc.postgresql.org/)

**Linux installation:**
```bash
sudo apt install odbc-postgresql
```

---

### Oracle

```php
$wgODBCSources['oracle'] = [
    'connection_string' => 'Driver={Oracle 19 ODBC driver};DBQ=oraserver:1521/ORCLPDB1;',
    'user'     => 'wiki_user',
    'password' => 'OraclePass1',
];
```

**Common driver names** (vary by Instant Client version and OS):
- `Oracle 19 ODBC driver`
- `Oracle in OraDB19Home1`

**Download:** [Oracle Instant Client ODBC](https://www.oracle.com/database/technologies/instant-client/downloads.html)

---

### Progress OpenEdge

Progress OpenEdge (versions 11+) is supported via its ODBC driver. The extension automatically detects Progress from the driver name and uses `SELECT FIRST n` row-limit syntax instead of `SELECT TOP n` or `LIMIT n`.

**Configuration keys:**
- Use `host` (not `server`) — Progress uses `Host=` in its connection string
- Use `db` (not `database`) — Progress uses `DB=` in its connection string
- Default broker port is **32770** (older installations) or **5162** (OpenEdge 11.7+)

```php
$wgODBCSources['progress-erp'] = [
    'driver'   => 'Progress OpenEdge 11.7 Driver',
    'host'     => 'db.example.com',   // Progress uses Host=, not Server=
    'port'     => '32770',            // Progress broker/AppServer port
    'db'       => 'sports2000',       // Progress uses DB=, not Database=
    'user'     => 'admin',
    'password' => 'pw',
];
```

Or via a preconfigured System DSN (recommended if the Progress ODBC driver is already configured in ODBC Data Source Administrator):

```php
$wgODBCSources['progress-erp'] = [
    'dsn'      => 'MyProgressDSN',
    'user'     => 'admin',
    'password' => 'pw',
];
```

**Common driver names:**
- `Progress OpenEdge 11.7 Driver`
- `Progress OpenEdge 12.x Driver`
- `DataDirect 8.0 Progress OpenEdge Wire Protocol` (third-party DataDirect driver)

**Row-limit syntax:** Progress uses `SELECT FIRST n col FROM table`. The extension detects any driver whose name contains "progress" or "openedge" and automatically uses `FIRST` syntax. Other limit syntaxes (`TOP`, `LIMIT`) are not supported by Progress OpenEdge SQL.

**Schema-qualified table names:** Progress tables are typically in the `PUB` schema. Use `from=PUB.TableName` or configure the schema default in your DSN.

**Download:** Progress OpenEdge ODBC drivers are bundled with the OpenEdge product. Contact Progress Software or your OpenEdge vendor.

---

### Microsoft Access

```php
// System DSN — recommended for Access (avoids connection string issues)
$wgODBCSources['access-db'] = [
    'dsn'      => 'MyAccessDatabase',
    'user'     => '',
    'password' => '',
];
```

> **Note:** Access does not support bare `SELECT 1` without a `FROM` clause. The extension detects Access drivers and uses `SELECT 1 FROM MSysObjects WHERE 1=0` as its liveness probe instead, ensuring connection-pool health checks work correctly.

**Driver name (if not using DSN):**
- `Microsoft Access Driver (*.mdb, *.accdb)` (32-bit)
- Requires `Microsoft Access Database Engine 2016 Redistributable` on 64-bit systems

---

### SQLite

```php
$wgODBCSources['sqlite'] = [
    'connection_string' => 'Driver=SQLite3 ODBC Driver;Database=/path/to/database.sqlite;',
    'user'     => '',
    'password' => '',
];
```

**Driver name:** `SQLite3 ODBC Driver`

**Download:** [SQLite ODBC Driver](http://www.ch-werner.de/sqliteodbc/)

---

## Checking Installed Drivers

### Linux (unixODBC)
```bash
odbcinst -q -d            # list all registered drivers
cat /etc/odbcinst.ini     # raw driver registration file
```

### Windows
Open **ODBC Data Sources (64-bit)** from Control Panel → Administrative Tools → ODBC Data Sources (64-bit), then click the **Drivers** tab.

Or run from the command line:
```batch
reg query "HKLM\SOFTWARE\ODBC\ODBCINST.INI\ODBC Drivers"
```

---

## Database-Specific Notes

### SQL Server — TOP vs. LIMIT syntax

SQL Server uses `SELECT TOP n` instead of `SELECT ... LIMIT n`. The extension automatically detects SQL Server drivers by name and uses the correct syntax. When using External Data `odbc_source` mode, the driver name is now automatically inherited from the referenced `$wgODBCSources` entry, so `TOP` syntax works correctly in that mode too.

### MySQL — Character Set

Always use the Unicode ODBC driver variant (`MySQL ODBC 8.0 Unicode Driver`) to ensure proper UTF-8 handling. The ANSI variant may corrupt non-ASCII characters.

### Oracle — Connection String Format

Oracle uses a different DSN format. Use `connection_string` (Mode 3) for Oracle rather than the individual `driver`/`server`/`database` keys, which map to SQL Server/MySQL conventions.

### Progress OpenEdge — FIRST N Syntax and Config Keys

Progress OpenEdge uses `SELECT FIRST n` to limit rows, not `TOP n` or `LIMIT n`. The extension detects Progress drivers (names containing "progress" or "openedge") and applies the correct syntax automatically.

Progress also uses different connection-string keys. When specifying driver/host/port/database directly (Mode 2), use:
- `host` instead of `server` — maps to `Host=`
- `db` instead of `database` — maps to `DB=`

All other sources use `server`/`database`. Using a System DSN (Mode 1) avoids this entirely since the ODBC driver handles the mapping internally.

### Microsoft Access — No Query Timeout Support

Access (Jet/ACE) does not support ODBC query timeouts (`SQL_QUERY_TIMEOUT`). The extension logs a warning in the debug log but continues normally. The `timeout` setting is silently ignored for Access sources.

### PostgreSQL — Schema-Qualified Table Names

To query a specific schema other than `public`, use a schema-qualified table name in `from=`:
```wiki
| from=myschema.tablename
```
Both the schema name and table name must pass identifier validation (`[A-Za-z0-9_.]`).
