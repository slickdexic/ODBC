# Troubleshooting

This page covers common problems with the ODBC extension and their solutions. For issues not listed here, check [[Known-Issues]] for documented bugs with workarounds.

---

## Installation & Setup

### "Call to undefined function odbc_connect()"

**Cause:** PHP's `ext-odbc` module is not installed or not enabled.

**Fix:**
- **Windows:** Enable `extension=php_odbc.dll` in `php.ini`, then restart the web server.
- **Linux (apt):** `sudo apt install php-odbc && sudo phpenmod odbc && sudo systemctl restart apache2`
- **Linux (yum):** `sudo yum install php-odbc && sudo systemctl restart httpd`

Verify: `php -m | grep -i odbc` should output `odbc`.

---

### Extension not appearing in Special:Version

**Cause:** The extension is not loaded, or loaded with the wrong directory name.

**Check:**
1. The directory must be named exactly `ODBC` (capital letters, no spaces).
2. `wfLoadExtension( 'ODBC' );` is present in `LocalSettings.php`.
3. No PHP parse errors in `LocalSettings.php` — check the MediaWiki error log.

---

### "Could not connect to source 'my-source'"

**Cause:** The ODBC connection cannot be established.

**Diagnostic steps:**

1. **Test from Special:ODBCAdmin** — click "Test Connection" next to the source. The error message from the ODBC driver is shown.

2. **Verify the driver is installed:**
   - Linux: `odbcinst -q -d` — does your driver name appear?
   - Windows: ODBC Data Source Administrator → Drivers tab

3. **Verify the driver name is exact** — the `driver` value in `$wgODBCSources` must match the driver registration name exactly (including capitalisation):
   ```php
   'driver' => 'ODBC Driver 17 for SQL Server',  // ← exact match required
   ```

4. **Test the connection outside PHP** from the web server:
   - Linux: `isql -v "DSN_NAME" username password`
   - Or write a minimal PHP test script: `<?php var_dump( odbc_connect( 'DSN', 'user', 'pass' ) ); ?>`

5. **Check firewall rules** — is the database port (e.g., 1433 for SQL Server, 3306 for MySQL) reachable from the web server?

6. **Check database server logs** — does the server see the connection attempt? Does it report an authentication failure?

---

## Parser Function Issues

### `{{#odbc_query:}}` shows an error message on the page

The error appears inline where the parser function call is. Common messages:

| Error Message | Cause |
|---------------|-------|
| `Unknown source: 'my-source'` | The `source=` value doesn't match any key in `$wgODBCSources`. Check spelling and case. |
| `Arbitrary SQL not allowed` | `$wgODBCAllowArbitraryQueries` is `false` and the source doesn't have `allow_queries = true`. Use a prepared statement or enable ad-hoc queries. |
| `Unknown prepared query 'query-name'` | The `query=` name doesn't match any key in the source's `prepared` array. Check spelling and the config. |
| `Illegal SQL pattern 'UNION'` | The word "union" appears in your query (including within an identifier). See KI-024. |
| `ODBC error: [driver message]` | The ODBC driver returned an error. Check the driver message for specifics. |
| `Query returned no results` | Not an error — the query ran but found no rows. Check your WHERE conditions and data. |

---

### Parser function output is empty (no error, no data)

1. **Check that `#odbc_query` returned data** — add a test with `{{#odbc_value: some_var | (no data) }}` to see if the variable was populated.
2. **Check the `data=` mapping** — column names in the mapping must match the actual column names returned by the query. Use Special:ODBCAdmin → Show Columns to verify column names.
3. **Check the `where=` condition** — it may be too restrictive. Test the query directly in Special:ODBCAdmin → Run Test Query.
4. **Check `limit=`** — make sure it isn't set to `0`.
5. **Check `$wgODBCMaxRows`** — the default is 1000. If expecting more rows, increase the limit.

---

### "Arbitrary SQL not allowed"

You're trying to use `from=`, `where=`, etc. but arbitrary queries are disabled.

**Options (choose one):**
- Define the query as a prepared statement in `$wgODBCSources['your-source']['prepared']` and use `query=` on the wiki page instead.
- Add `'allow_queries' => true` to the specific source in `$wgODBCSources` to allow ad-hoc queries for that source only.
- Set `$wgODBCAllowArbitraryQueries = true` globally (only do this if all users with `odbc-query` are trusted).

---

### "Illegal SQL pattern 'UNION'" — but query has no UNION

This is KI-024. The word "union" appears somewhere in the query as a substring of an identifier (e.g., a table named `TRADE_UNION` or a column named `UNION_ID`). The sanitizer matches "UNION" as a substring, not as a keyword.

**Workaround:** Use a prepared statement — the SQL is in `LocalSettings.php` and never goes through the sanitizer.

---

### `{{{variable_name}}}` appears literally in the output

`#for_odbc_table` or `#display_odbc_table` is rendering the template syntax literally instead of substituting values.

**Causes:**
1. The variable name in `{{{name}}}` does not match the local variable name in `data=name=ColumnName`. Check for typos.
2. `#odbc_query` was not called before the display function, or was called with a different set of variable names.
3. `#odbc_clear:` was called between `#odbc_query` and `#for_odbc_table`.

---

### Table shows only one row when multiple rows are expected

`{{#odbc_value:}}` only returns the first row's value. Use `{{#for_odbc_table:}}` or `{{#display_odbc_table:}}` to iterate over all rows.

---

### `#for_odbc_table` pipe characters causing template parameter issues

Inside `{{#for_odbc_table: ... }}`, literal pipe characters `|` are interpreted as parameter separators. Use `{{!}}` in place of `|`:

```wiki
{{#for_odbc_table:
{{!}}-
{{!}} {{{col1}}} {{!}}{{!}} {{{col2}}}
}}
```

---

### Prepared statement parameters containing commas

If a parameter value itself contains a comma (e.g., full names like `"Smith, John"`), the default comma separator will split it incorrectly.

**Fix:** Use the `separator=` parameter with a different delimiter:

```wiki
{{#odbc_query: source=hr
 | query=get_employee
 | parameters=Smith, John
 | separator=;
}}
```

And pass the name as a semicolon-separated list (just the one item here, so no ambiguity).

---

## Performance Issues

### Pages load slowly

1. **Enable query caching:**
   ```php
   $wgODBCCacheExpiry = 300;  // cache for 5 minutes
   ```
   This requires `$wgMainCacheType` to be configured to something other than `CACHE_NONE`.

2. **Use the `limit=` parameter** to reduce rows returned.

3. **Review the query** — run it in Special:ODBCAdmin to see how long it takes. Add database indexes if needed.

4. **Check the database server** — is it under load? Is the connection latency high?

5. **Reduce `$wgODBCQueryTimeout`** — a low timeout fails fast instead of blocking page renders.

### "Connection pool exhausted" errors

Increase `$wgODBCMaxConnections`:
```php
$wgODBCMaxConnections = 25;
```
Default is 10. Increase if multiple sources are used simultaneously on the same page or under high load.

---

## Admin Interface Issues

### Special:ODBCAdmin shows "Permission denied"

The visiting user does not have the `odbc-admin` right. Grant it:
```php
$wgGroupPermissions['your-group']['odbc-admin'] = true;
```
By default only `sysop` has this right.

### "Connection pool exhausted" / "MAX_CONNECTIONS reached"

In the README (v1.0.3 and earlier) there was a reference to a `MAX_CONNECTIONS` constant. This is now a configuration setting. Increase:
```php
$wgODBCMaxConnections = 25;
```

### Test query in admin returns "non-SELECT statements not allowed"

The admin test query interface enforces SELECT-only queries. Rewrite the query to start with `SELECT`.

---

## External Data Integration

### `{{#get_db_data:}}` with type `odbc_generic` returns nothing

1. Verify External Data is installed and loads *before* the ODBC extension in `LocalSettings.php`.
2. Verify `$wgODBCExternalDataIntegration` is `true` (the default) — and that it was set before `wfLoadExtension( 'ODBC' )`.
3. Check `Special:Version` — the ODBC extension should appear under Parser hooks.
4. Try the native `{{#odbc_query:}}` parser function with the same source to rule out connection issues.

See [[External-Data-Integration]] for full diagnostics.

---

## Debug Logging

Enable MediaWiki debug logging to see detailed ODBC activity:

```php
$wgDebugLogFile = '/tmp/mediawiki-debug.log';
$wgDebugLogGroups['ODBC'] = '/tmp/mediawiki-odbc.log';
```

The ODBC log group records:
- Every query executed (SQL, source, row count, timing)
- Connection open and close events
- Cache hit/miss events
- `odbc_setoption()` failures (timeout setting not supported by driver)

**Warning:** The debug log may contain sensitive data (query SQL, result data). Do not enable in production for extended periods.
