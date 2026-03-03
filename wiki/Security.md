# Security

This page describes the extension's security model, the protections it provides, its limitations, and recommended practices for production deployments.

---

## Security Model

The extension has **two operating modes** with very different security profiles:

| Mode | Enabled when | SQL source | Risk level |
|------|-------------|------------|------------|
| **Prepared statements** | `prepared` queries defined in config; `$wgODBCAllowArbitraryQueries = false` | SQL is in `LocalSettings.php`, never exposed to wiki editors | Low — recommended for production |
| **Composed (ad-hoc) queries** | `$wgODBCAllowArbitraryQueries = true` or source `allow_queries = true` | SQL elements (`FROM`, `WHERE`, etc.) are supplied by wiki editors | Higher — only for trusted users |

**The safest production setup:** define all queries as prepared statements in `LocalSettings.php`, leave `$wgODBCAllowArbitraryQueries = false`, and restrict `odbc-query` to trusted user groups.

---

## Protections in Place

### SQL Injection Protection (Composed Queries)

When composed queries are allowed, user-supplied SQL fragments (`where=`, `from=`, `order by=`, etc.) are passed through a sanitizer before execution.

**Keyword blocklist** — the following patterns cause an immediate rejection:

| Pattern | Reason blocked |
|---------|---------------|
| `;` | Statement termination — prevents query chaining |
| `--` | SQL line comment |
| `#` | MySQL/MariaDB line comment |
| `/*` / `*/` | Block comment delimiters |
| `<?` | PHP tag injection |
| `CHAR(` | Obfuscation function |
| `CONCAT(` | Obfuscation / evasion function |
| `UNION` | UNION-based injection |
| `DROP` / `DELETE` / `INSERT` / `UPDATE` / `TRUNCATE` / `GRANT` / `REVOKE` / `ALTER` / `CREATE` / `EXEC` / `EXECUTE` | DDL/DML commands |

**Identifier validation** — table and column names supplied via `from=` and `data=` are validated to contain only `[A-Za-z0-9_.]`. Names that contain any other character are rejected.

> **Limitation:** The keyword blocklist is a defence-in-depth measure. It is not guaranteed to block all SQL injection vectors, particularly across all database dialects or with unusual encoding. **Prepared statements are the only injection-safe approach.**

> **Fixed in v1.1.0 (KI-024):** `UNION` now uses word-boundary matching — identifiers like `LABOUR_UNION` or `TRADE_UNION_TYPE` are no longer blocked.

### Output Escaping

- **HTML output** from parser functions is HTML-escaped before being emitted into wikitext, preventing XSS via database content.
- **Template parameter output** from `#display_odbc_table` is escaped via template parameter escaping, preventing wikitext injection from stored values.

### Error Message Sanitization

When a query fails, the ODBC error string may contain the connection DSN or credentials. The extension strips password-like material from error strings before displaying them to users. Full error details are written to the MediaWiki debug log (`$wgDebugLogFile`) server-side only.

### CSRF Protection

All state-changing POST actions in `Special:ODBCAdmin` (including the "Run Query" function) require a valid MediaWiki session edit token (`wpEditToken`). Read-only GET actions (connection test, table browse, column browse) do not require a token, consistent with standard MediaWiki admin page conventions.

### Permission Enforcement

- `odbc-query` is required to invoke any parser function from a wiki page. Unauthenticated users and users without this right cannot trigger any database access.
- `odbc-admin` is required to access `Special:ODBCAdmin`. Even with `odbc-admin`, the admin page enforces SELECT-only queries — it does not bypass the sanitizer.
- The connection test in `Special:ODBCAdmin` bypasses the `$wgODBCAllowArbitraryQueries` check (any admin can test connections regardless of that setting). This is intentional.

### Connection Pooling Limits

The connection pool is capped at `$wgODBCMaxConnections` (default: 10) to prevent resource exhaustion from excessive ODBC handle accumulation.

---

## Recommended Practices

### 1. Use a Read-Only Database Account

Create a database account with **only `SELECT` privileges** on the tables the wiki needs to query. Even if the extension's protections are bypassed somehow, a read-only account cannot modify or delete data.

```sql
-- SQL Server example
CREATE LOGIN wiki_reader WITH PASSWORD = 'StrongPassword123!';
CREATE USER wiki_reader FOR LOGIN wiki_reader;
GRANT SELECT ON SCHEMA::dbo TO wiki_reader;

-- MySQL example
CREATE USER 'wiki_reader'@'%' IDENTIFIED BY 'StrongPassword123!';
GRANT SELECT ON mydb.* TO 'wiki_reader'@'%';
FLUSH PRIVILEGES;
```

### 2. Use Prepared Statements for All Production Queries

Define every query you need in `$wgODBCSources['prepared']` and keep `$wgODBCAllowArbitraryQueries = false`. Wiki editors specify parameters but never see or control the SQL.

```php
$wgODBCSources['products'] = [
    // ...
    'prepared' => [
        'by_category' => 'SELECT Name, Price FROM Products WHERE CategoryID = ? ORDER BY Name',
        'by_sku'      => 'SELECT Name, Price, Stock FROM Products WHERE SKU = ?',
    ],
];
```

### 3. Protect `LocalSettings.php`

Credentials are stored in plain text in `LocalSettings.php`. Ensure:
- File is readable only by the web server user: `chmod 640 LocalSettings.php`
- Consider storing passwords in environment variables and reading them with `getenv()`:
  ```php
  'password' => getenv( 'DB_WIKI_PASSWORD' ),
  ```
- Consider a separate `DBCredentials.php` outside the web root, included with `require_once`.

### 4. Restrict `odbc-query` Permission

Do not grant `odbc-query` to `*` (all users) unless your wiki is private and all visitors are trusted. At minimum, require login.

```php
// Require login to query (don't grant to '*' or anonymous users)
$wgGroupPermissions['user']['odbc-query'] = true;      // logged-in users
$wgGroupPermissions['*']['odbc-query']    = false;     // not anonymous
```

### 5. Keep Queries Scoped to Specific Tables

Rather than allowing access to an entire database, define your prepared statements to query only the specific tables and columns the wiki actually needs. This limits exposure if the database account's privileges are ever expanded unintentionally.

### 6. Use Encrypted Connections

For databases over a network, enable TLS:
```php
// SQL Server with TLS
$wgODBCSources['secure-db'] = [
    'driver'            => 'ODBC Driver 18 for SQL Server',
    'server'            => 'db.example.com,1433',
    'database'          => 'MyDB',
    'user'              => 'wiki_reader',
    'password'          => 'secret',
    'trust_certificate' => false,   // false = validate the TLS certificate
    'dsn_params'        => [ 'Encrypt' => 'yes' ],
];
```

---

## Attack Surface

### What can a wiki editor with `odbc-query` do?

In **prepared-statement-only mode** (`$wgODBCAllowArbitraryQueries = false`):
- Call named prepared statements defined by the server administrator
- Supply bind parameters — these are safely parameterized by the ODBC driver
- Read data defined by those statements

In **ad-hoc mode** (`$wgODBCAllowArbitraryQueries = true` or per-source `allow_queries`):
- Specify table names, WHERE conditions, ORDER BY, etc.
- These are passed through the keyword blocklist and identifier validator
- The risk of injection is real — only enable for fully trusted users

### What can a wiki editor with `odbc-admin` do?

- View configured source names, driver names, and server addresses (not passwords)
- Test whether a connection succeeds
- Browse table and column names
- Run ad-hoc SELECT queries (blocked patterns still apply; non-SELECT is blocked)

> **Note (KI-026):** The admin test query function bypasses the `$wgODBCAllowArbitraryQueries` check — admin users can run test queries regardless of whether arbitrary queries are allowed globally.

---

## Known Security Limitations

| Description | Mitigation |
|-------------|------------|
| SQL keyword blocklist is not exhaustive — new obfuscation techniques or database-specific functions may bypass it | Use prepared statements exclusively; `$wgODBCAllowArbitraryQueries` is for trusted internal deployments only |
| Query timeout is best-effort — not all ODBC drivers support `SQL_QUERY_TIMEOUT`; failure is logged to the ODBC debug channel | Monitor debug log; use a read-only database account to limit blast radius |

---

## Security Release History

| Version | Security changes |
|---------|-----------------|
| v1.0.0 | Initial release |
| v1.0.1 | Parser function magic word case sensitivity fixed |
| v1.0.2 | XSS fix in `#display_odbc_table` column output; wikitext injection fix in `escapeTemplateParam`; UNION keyword added to blocklist; password stripped from ODBC error messages; CSRF token added to admin POST actions |
| v1.0.3 | Cache key collision fix (prevented cross-user data leakage with caching enabled); `#` MySQL comment added to blocklist; `$wgODBCMaxRows` enforced in External Data connector; connection liveness detection added |
| v1.1.0 | UNION word-boundary matching fix (KI-024) — identifiers like `TRADE_UNION_ID` no longer falsely blocked; connection string value escaping (KI-025) — `;`/`{`/`}` in credentials now correctly handled; sanitizer word-boundary fix for all DDL/DML keywords (KI-032); per-page query limit added (`$wgODBCMaxQueriesPerPage`, KI-018); timeout failures now logged instead of silently discarded |
| v1.2.0 | Parser function error returns now correctly marked as HTML (`isHTML: true`) — prevents edge-case wikitext re-parsing of error spans; `withOdbcWarnings()` DRY refactor applied to all error handlers |
| v1.3.0 | `Special:ODBCAdmin` test-query form now respects `$wgODBCAllowArbitraryQueries` and per-source `allow_queries` — admins could previously run arbitrary SQL even when the policy was disabled |
| v1.4.0 | `EDConnectorOdbcGeneric` guarded against missing `EDConnectorComposed` — prevents a potential fatal error if External Data is not installed |
| v1.5.0 | `validateIdentifier()` regex tightened (KI-065) — rejects over-segmented names and malformed dotted identifiers; ED table alias validation via `validateIdentifier()` (KI-067) — closes a potential injection vector in External Data alias parameters; `HAVING` without `GROUP BY` now rejected before reaching the driver (KI-064); `withOdbcWarnings()` now filters to ODBC-originated warnings only (KI-066) |

To report a security vulnerability, see [SECURITY.md](https://github.com/slickdexic/ODBC/blob/main/SECURITY.md).
