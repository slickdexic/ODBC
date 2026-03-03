# External Data Integration

The ODBC extension optionally integrates with the [External Data](https://www.mediawiki.org/wiki/Extension:External_Data) (ED) extension. When both are installed and integration is enabled, ODBC registers itself as an `odbc_generic` connector type, allowing External Data's parser functions (`{{#get_db_data:}}`, `{{#for_external_table:}}`, etc.) to query ODBC databases.

---

## Prerequisites

- External Data extension installed and loaded **before** the ODBC extension
- `$wgODBCExternalDataIntegration` set to `true` (the default)

> The ODBC extension detects the External Data extension at load time. Load order in `LocalSettings.php` matters:
> ```php
> wfLoadExtension( 'ExternalData' );  // must come first
> wfLoadExtension( 'ODBC' );
> ```

---

## Configuration

There are two ways to configure an ODBC source for External Data.

### Option A — Direct Configuration in `$wgExternalDataSources`

Define the connection details directly in the External Data sources array. Use `type = 'odbc_generic'`.

```php
$wgExternalDataSources['my-odbc-source'] = [
    'type'     => 'odbc_generic',
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'db.example.com,1433',
    'name'     => 'ProductionDB',   // Note: ED uses 'name' for the database name
    'user'     => 'ed_readonly',
    'password' => 'ReadOnly123',
];
```

> **Note:** External Data uses `name` for the database name (not `database`). This is an ED convention; the ODBC connector maps `name` → `Database` in the connection string.

The supported keys for an `odbc_generic` source in `$wgExternalDataSources` are: `type`, `driver`, `server`, `name`, `user`, `password`, `port`, `connection_string`, `dsn`.

### Option B — Reference an `$wgODBCSources` Entry

If you already have a source defined in `$wgODBCSources`, you can reference it from External Data using `odbc_source`:

```php
// Define in ODBCSources as usual
$wgODBCSources['hr-system'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'hr-server.internal,1433',
    'database' => 'HumanResources',
    'user'     => 'wiki_hr_ro',
    'password' => 'secret',
];

// Reference it in External Data sources
$wgExternalDataSources['hr-system'] = [
    'type'        => 'odbc_generic',
    'odbc_source' => 'hr-system',   // references the $wgODBCSources key
    'user'        => '',
    'password'    => '',
];
```

> **Known issue (KI-027):** When using `odbc_source`, the ODBC connector does not inherit the driver type from `$wgODBCSources`. As of v1.0.3, this means TOP/LIMIT syntax detection may fail for SQL Server databases configured via `odbc_source`. **Workaround:** add `'driver' => 'ODBC Driver 17 for SQL Server'` (or your actual driver name) redundantly to the `$wgExternalDataSources` entry even when using `odbc_source`.

---

## Feature Parity

The ODBC-via-ED connector has different capabilities than the native ODBC parser functions:

| Feature | Native ODBC (`#odbc_query`) | External Data (`#get_db_data`) |
|---------|---------------------------|-------------------------------|
| Prepared statements | ✅ Yes | ❌ No |
| Query result caching | ✅ Yes (`$wgODBCCacheExpiry`) | ❌ No |
| `$wgODBCMaxRows` enforcement | ✅ Yes | ✅ Yes (since v1.0.3) |
| TOP/LIMIT auto-detection | ✅ Yes | ⚠️ Partial (KI-027) |
| UTF-8 conversion | ✅ Yes | ❌ No |
| Access to `$wgODBCSources` auth | ✅ Yes | ✅ Via `odbc_source` |

For full feature parity, prefer the native ODBC parser functions in most situations. Use External Data integration when you need its specific features (e.g., cross-connector data merging, ED templates, or existing ED infrastructure).

---

## Usage on Wiki Pages

Once configured, use External Data's own parser functions. The ODBC integration is transparent from the wiki editor's perspective.

### `{{#get_db_data:}}`

```wiki
{{#get_db_data: db=my-odbc-source
 | from=Products
 | data=product_name=ProductName,unit_price=UnitPrice
 | where=Active=1
 | order by=ProductName
 | limit=25
}}
```

### `{{#for_external_table:}}`

```wiki
{{#get_db_data: db=my-odbc-source
 | from=Categories
 | data=cat_id=CategoryID,cat_name=CategoryName
}}

{| class="wikitable"
! ID !! Category
{{#for_external_table:
{{!}}-
{{!}} {{{cat_id}}} {{!}}{{!}} {{{cat_name}}}
}}
|}
```

### `{{#display_external_table:}}`

```wiki
{{#get_db_data: db=hr-system
 | from=Employees
 | data=name=FullName,dept=Department,email=Email
 | where=Active=1
 | order by=LastName
}}

{{#display_external_table: template=EmployeeCard }}
```

Refer to the [External Data extension documentation](https://www.mediawiki.org/wiki/Extension:External_Data) for the full reference on its parser functions, filtering, and template options.

---

## Disabling the Integration

Set `$wgODBCExternalDataIntegration = false;` **before** loading the ODBC extension:

```php
$wgODBCExternalDataIntegration = false;   // Must be before wfLoadExtension
wfLoadExtension( 'ODBC' );
```

> **Known issue (KI-028):** Only the boolean literal `false` disables the integration. Integer `0` and `null` are not treated as disabling values. Always use `false`.

---

## Troubleshooting

### "Unknown connector type: odbc_generic"

The ODBC connector was not registered. Causes:
1. `$wgODBCExternalDataIntegration` was set to something other than `true` — check if it's accidentally `false` or `0`.
2. `wfLoadExtension( 'ODBC' )` was called before `wfLoadExtension( 'ExternalData' )` — swap the order.
3. The External Data extension is not installed or not loading — check `Special:Version`.

### Query returns wrong results (SQL Server via `odbc_source`)

This is KI-027. The TOP/LIMIT syntax detection fails when using `odbc_source` mode. Add the driver key redundantly:

```php
$wgExternalDataSources['my-source'] = [
    'type'        => 'odbc_generic',
    'odbc_source' => 'my-source',
    'driver'      => 'ODBC Driver 17 for SQL Server',  // ← add this
    'user'        => '',
    'password'    => '',
];
```

### Results are not cached

The External Data connector does not use `$wgODBCCacheExpiry`. If caching is needed, use the native ODBC parser functions (`#odbc_query`) instead. See [[Known-Issues#ki-020]].
