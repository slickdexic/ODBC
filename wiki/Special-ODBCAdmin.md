# Special:ODBCAdmin

`Special:ODBCAdmin` is the administration interface for the ODBC extension. It provides a web-based way to test connections, browse database structure, and run test queries without writing wikitext.

**Required permission:** `odbc-admin` (granted to `sysop` by default)

Navigate to: `https://your-wiki.example.com/wiki/Special:ODBCAdmin`

---

## Interface Overview

The admin page is organized into sections. The top of the page lists all sources configured in `$wgODBCSources`.

### Source List

Each configured source is displayed with:
- **Source ID** — the key from `$wgODBCSources`
- **Driver / DSN** — the connection type (e.g., "ODBC Driver 17 for SQL Server" or "DSN: MyDSN")
- **Server / Database** — where applicable
- **Status** — whether prepared statements are configured and whether ad-hoc queries are allowed

---

## Actions

### Test Connection

Click **Test Connection** next to a source to verify that the extension can open an ODBC connection to that database.

**What it tests:**
- The ODBC driver is installed and accessible
- The connection string or DSN resolves
- The database server accepts the connection
- Authentication succeeds

**Success output:**
```
Connection successful. (Driver: ODBC Driver 17 for SQL Server)
```

**Failure output:**
The ODBC error message from the driver is shown. Common causes and fixes are listed in [[Troubleshooting]].

> **Note:** A successful connection test means the ODBC layer can connect. It does not verify that any specific tables exist or that the configured user has SELECT privileges on them.

---

### Browse Tables

Click **Browse Tables** to list all tables (and views) visible to the configured database user in that source.

The list comes from the ODBC metadata function `odbc_tables()`, which returns tables accessible to the connection. The list respects the database user's permissions — tables the user cannot access may not appear.

---

### Browse Columns

Select a table from the Tables list and click **Show Columns** to inspect the table's structure.

The column browser shows:

| Column | Description |
|--------|-------------|
| **Column Name** | The column name as it should be specified in `data=` mappings |
| **Data Type** | SQL data type (e.g., `VARCHAR`, `INT`, `DATETIME`) |
| **Nullable** | Whether the column accepts NULL values |
| **Max Length** | For character types, the maximum length |

Use the column browser to identify exact column names for use in `data=ColVar=ColumnName` mappings in parser functions.

---

### Run Test Query

The **Run Test Query** form accepts a `SELECT` statement and executes it against the selected source, displaying up to 100 result rows in a table.

**Restrictions:**
- Only `SELECT` statements are accepted. All other statement types are blocked.
- The same SQL sanitization rules apply — keyword blocklist, identifier validation.
- Results are capped at **100 rows** regardless of `$wgODBCMaxRows`.
- The query bypasses the `$wgODBCAllowArbitraryQueries` check — admin users can always run test queries via this interface.

> This is useful for verifying the exact column names returned by a query before writing a `data=` mapping, or for debugging why a wiki page query isn't returning expected results.

**Example:**
```sql
SELECT TOP 10 ProductName, UnitPrice, CategoryID
FROM Products
WHERE Active = 1
ORDER BY ProductName
```

---

## Common Admin Tasks

### Verifying a New Source

After adding a new source to `$wgODBCSources`:
1. Go to Special:ODBCAdmin
2. Find the new source in the list
3. Click **Test Connection** — this confirms the driver and credentials are correct
4. Click **Browse Tables** — this confirms the user has access and lists available tables
5. Use **Show Columns** on the relevant table to find exact column names
6. Optionally run a **Test Query** to verify the data looks as expected

### Diagnosing a Broken Wiki Page

If a page using `{{#odbc_query:}}` is returning an error or no data:
1. Go to Special:ODBCAdmin
2. Test the connection for the relevant source
3. Browse to the table in question and verify column names match your `data=` mapping
4. Run the equivalent SELECT query in the Test Query form to check for data

### Checking What Queries Are Available

The Source List shows whether each source has prepared statements configured. To see the exact statement names and SQL, you need to check `$wgODBCSources` in `LocalSettings.php` directly — they are not exposed through the admin UI (for security reasons).

---

## Permissions Reference

| Task | Required Permission |
|------|-------------------|
| View source list | `odbc-admin` |
| Test connection | `odbc-admin` |
| Browse tables | `odbc-admin` |
| Browse columns | `odbc-admin` |
| Run test query | `odbc-admin` |
| Use parser functions on wiki pages | `odbc-query` |

The two permissions are independent. A user can have `odbc-admin` without `odbc-query` (can use admin interface but not query from pages) and vice versa.
