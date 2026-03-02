# Upgrade Guide

## Upgrading to 1.0.1 from 1.0.0

Version 1.0.1 includes critical security fixes and improvements. All users are strongly encouraged to upgrade.

### Breaking Changes

**None.** Version 1.0.1 is fully backward compatible with 1.0.0.

### What's Changed

#### Security Fixes (Action Recommended)

1. **SQL Injection Protection Enhanced**
   - Column and table identifiers are now strictly validated
   - Special characters in identifiers are blocked
   - **Action**: Review your queries if you use unusual column/table names (e.g., with spaces or special chars)

2. **Password Exposure Fixed**
   - Passwords are now stripped from error messages
   - **Action**: None required, but review your logs to ensure no credentials were previously exposed

3. **CSRF Protection Improved**  
   - Token validation is now consistent across all admin actions
   - **Action**: None required, users may need to retry failed admin actions after upgrade

#### Functional Improvements

1. **LIMIT Enforcement Fixed**
   - Queries now properly enforce row limits in SQL, not just post-fetch
   - **Action**: Some queries may return fewer results than before if they exceeded limits
   - **Impact**: Improved performance (database processes fewer rows)

2. **Magic Word Case Sensitivity Fixed**
   - `{{#ODBC_QUERY:}}` now works (previously only lowercase worked)
   - **Action**: No action required, both cases now work

3. **Connection Health Checks Added**
   - Stale connections are automatically detected and replaced
   - **Action**: None required, may see slightly more reconnections in logs

4. **Resource Leak Fixes**
   - ODBC result resources are now properly freed in all error cases
   - **Action**: Monitor for improved memory usage under error conditions

### Configuration Changes

No configuration changes are required. All existing configuration remains valid.

#### Optional: Enhanced Configuration

You may want to take advantage of new features:

```php
// Example: Enhanced source configuration
$wgODBCSources['my-db'] = [
    'driver' => 'ODBC Driver 17 for SQL Server',
    'server' => 'localhost,1433',
    'database' => 'MyDB',
    'user' => 'readonly',
    'password' => 'secret',
    
    // NEW: Named prepared statements for better security
    'prepared' => [
        'get_user' => 'SELECT * FROM users WHERE id = ?',
        'search' => 'SELECT * FROM items WHERE category = ? AND name LIKE ?',
    ],
    
    // Existing options continue to work as before
    'timeout' => 30,
    'allow_queries' => false,  // Recommended: keep false for security
];
```

### Upgrade Steps

1. **Backup Your Data**
   - Backup your `LocalSettings.php`
   - Backup your database (MediaWiki wiki database, not ODBC sources)

2. **Review Current Queries**
   - Check if any queries use unusual identifiers (spaces, special characters)
   - Test queries in Special:ODBCAdmin before deploying to pages

3. **Update Extension Files**
   - Replace all files in `extensions/ODBC/` with new version
   - Or use `git pull` if using git

4. **Clear Caches**
   - MediaWiki object cache: `php maintenance/rebuildrecentchanges.php`
   - PHP opcode cache: Restart PHP-FPM or Apache

5. **Test Functionality**
   - Visit Special:ODBCAdmin and test connections
   - Run test queries through the admin interface
   - View pages that use ODBC parser functions
   - Check error logs for any issues

6. **Review Security Settings**
   - Verify `$wgODBCAllowArbitraryQueries` is still `false`
   - Review `allow_queries` settings on individual sources
   - Consider migrating to prepared statements if using ad-hoc queries

### Post-Upgrade Verification

Run these checks after upgrading:

1. **Connection Test**
   ```
   Navigate to Special:ODBCAdmin
   Click "Test Connection" for each configured source
   Verify all show success messages
   ```

2. **Query Test**
   ```
   In Special:ODBCAdmin, run a simple query:
   SELECT * FROM your_table LIMIT 1
   Verify results are displayed correctly
   ```

3. **Parser Function Test**
   ```
   Create a test page with:
   {{#odbc_query: source=your-source | from=your_table | data=col1,col2 | limit=1 }}
   {{#odbc_value:col1}}
   
   Verify data is displayed
   Verify no errors appear
   ```

4. **Permission Test**
   ```
   Log in as a non-admin user (if odbc-query is granted to them)
   Verify they can run queries
   Verify they cannot access Special:ODBCAdmin (unless odbc-admin granted)
   ```

### Troubleshooting Upgrade Issues

#### "Invalid identifier" errors after upgrade

**Cause**: Version 1.0.1 enforces stricter identifier validation.

**Solution**: 
- Check column/table names in your queries
- Ensure they contain only: letters, numbers, underscores, dots
- Maximum 128 characters per identifier
- If you need special characters, use prepared statements and let the database handle it

#### Queries return fewer results than before

**Cause**: LIMIT enforcement is now correct (applied in SQL, not post-fetch).

**Solution**:
- Increase the `limit=` parameter in your query
- Check `$wgODBCMaxRows` setting (default 1000)
- Use `ORDER BY` to control which rows are returned

#### Connection failures after upgrade

**Cause**: Connection health checks may detect previously-ignored stale connections.

**Solution**:
- Restart your database server
- Check connection parameters in `$wgODBCSources`
- Verify network connectivity
- Review ODBC driver logs

#### Admin interface shows "Invalid token" errors

**Cause**: Improved CSRF protection may require token refresh.

**Solution**:
- Refresh the page and try again
- Clear your browser cookies for the wiki
- Log out and log back in

### Migrating to Prepared Statements (Recommended)

If you're currently using `allow_queries: true`, consider migrating to prepared statements for better security:

**Before (1.0.0 - less secure):**
```php
$wgODBCSources['my-db'] = [
    'driver' => 'ODBC Driver 17 for SQL Server',
    'server' => 'localhost,1433',
    'database' => 'MyDB',
    'user' => 'user',
    'password' => 'pass',
    'allow_queries' => true,  // Allows arbitrary SQL
];
```

Wiki page:
```wiki
{{#odbc_query: source=my-db | from=users | where=id=123 | data=name,email }}
```

**After (1.0.1 - more secure):**
```php
$wgODBCSources['my-db'] = [
    'driver' => 'ODBC Driver 17 for SQL Server',
    'server' => 'localhost,1433',
    'database' => 'MyDB',
    'user' => 'user',
    'password' => 'pass',
    'allow_queries' => false,  // Better: disallow ad-hoc queries
    'prepared' => [
        'get_user' => 'SELECT name, email FROM users WHERE id = ?',
    ],
];
```

Wiki page:
```wiki
{{#odbc_query: source=my-db | query=get_user | parameters=123 | data=name,email }}
```

Benefits:
- Eliminates SQL injection risk
- Better performance (query plan caching)
- Easier to audit (fixed query list in config)
- Clearer separation of concerns

### Rollback Instructions

If you encounter issues and need to rollback to 1.0.0:

1. **Backup Current Version**
   - Keep a copy of version 1.0.1 files for future upgrade attempt

2. **Restore 1.0.0 Files**
   - Replace all files in `extensions/ODBC/` with 1.0.0 version

3. **Clear Caches**
   - Clear object cache
   - Restart PHP

4. **Restore Configuration**
   - Restore your `LocalSettings.php` backup
   - Note: 1.0.0 configuration is compatible with 1.0.1, so no changes needed

5. **Report Issues**
   - File a bug report with details of the issue
   - Include PHP version, MediaWiki version, ODBC driver info
   - Include (sanitized) error logs

### Getting Help

If you encounter issues during upgrade:

1. Check the [Troubleshooting section](README.md#troubleshooting) in README.md
2. Review [SECURITY.md](SECURITY.md) for security best practices
3. Check [CHANGELOG.md](CHANGELOG.md) for detailed change list
4. File an issue on GitHub with:
   - MediaWiki version
   - PHP version
   - ODBC driver name and version
   - Operating system
   - Error messages (sanitized to remove credentials)
   - Steps to reproduce

### Important Security Note

Version 1.0.1 fixes a critical SQL injection vulnerability. **Do not skip this upgrade** if you have `$wgODBCAllowArbitraryQueries = true` or any source with `allow_queries: true`.

Even if you only use prepared statements, upgrading is recommended for the other improvements and fixes.
