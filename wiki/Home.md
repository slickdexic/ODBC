# MediaWiki ODBC Extension

**Version:** 1.0.3 &nbsp;|&nbsp; **License:** GPL-2.0-or-later &nbsp;|&nbsp; **Requires:** MediaWiki 1.39+, PHP 7.4+

The ODBC extension connects MediaWiki to any ODBC-accessible database. Wiki editors can query databases directly from page content using parser functions, with optional integration into the [External Data](https://www.mediawiki.org/wiki/Extension:External_Data) extension.

---

## Features

| Feature | Description |
|---------|-------------|
| **5 Parser Functions** | `#odbc_query`, `#odbc_value`, `#for_odbc_table`, `#display_odbc_table`, `#odbc_clear` |
| **Prepared Statements** | Define parameterized SQL in configuration — the safest way to query |
| **Ad-hoc Queries** | Optional composed queries with SQL injection protection |
| **Connection Pooling** | Cached ODBC handles reused across parser function calls on the same page |
| **Query Caching** | Optional result caching via MediaWiki's object cache |
| **Admin Interface** | `Special:ODBCAdmin` for connection testing, table/column browsing, and test queries |
| **External Data Bridge** | Registers as an `odbc_generic` connector for the External Data extension |
| **Permission Control** | `odbc-query` and `odbc-admin` rights independently managed |
| **Multi-database** | Works with SQL Server, MySQL, PostgreSQL, Oracle, Access, and any ODBC driver |

---

## Quick Start

### 1. Install

Copy the extension into `extensions/ODBC/` and add to `LocalSettings.php`:

```php
wfLoadExtension( 'ODBC' );
```

### 2. Configure a data source

```php
$wgODBCSources['my-db'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'localhost,1433',
    'database' => 'MyDatabase',
    'user'     => 'wiki_reader',
    'password' => 'ReadOnly123',
    'prepared' => [
        'get_items' => 'SELECT Name, Price FROM Items WHERE Active = 1 ORDER BY Name',
    ],
];
```

### 3. Use on a wiki page

```wiki
{{#odbc_query: source=my-db | query=get_items | data=name=Name,price=Price }}

{| class="wikitable"
! Item !! Price
{{#for_odbc_table:
{{!}}-
{{!}} {{{name}}} {{!}}{{!}} {{{price}}}
}}
|}
{{#odbc_clear:}}
```

See the [[Installation]] page for full setup instructions.

---

## Wiki Contents

| Page | Description |
|-------|------------|
| [[Installation]] | Requirements, installation steps, and verification |
| [[Configuration]] | All `$wgODBC*` settings and `$wgODBCSources` reference |
| [[Parser-Functions]] | All five parser functions with parameter reference and examples |
| [[Security]] | Security model, recommended practices, and attack surface |
| [[External-Data-Integration]] | Using the extension as an External Data connector |
| [[Special-ODBCAdmin]] | Admin interface walkthrough |
| [[Supported-Databases]] | Database compatibility and driver names |
| [[Troubleshooting]] | Common errors and solutions |
| [[Architecture]] | Code structure and design for contributors |
| [[Contributing]] | Development setup, standards, and PR process |
| [[Known-Issues]] | Current open issues and workarounds |

---

## Getting Help

- Open an issue on [GitHub](https://github.com/slickdexic/ODBC/issues)
- See [[Troubleshooting]] for common problems
- See [[Known-Issues]] for documented bugs with workarounds
