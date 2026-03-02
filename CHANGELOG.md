# Changelog

All notable changes to the MediaWiki ODBC Extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-03-02

### Security

- **CRITICAL**: Fixed `UNION`/`UNION SELECT` not blocked by SQL sanitizer — added `UNION` to the `$charPatterns` blocklist in `ODBCQueryRunner::sanitize()`, preventing classic union-based SQL injection in composed queries
- **CRITICAL**: Fixed XSS vulnerability in Special:ODBCAdmin query results — database cell values were written via `Html::rawElement()` without escaping; now always use `Html::element()` which auto-escapes output
- Fixed wikitext injection in `{{#display_odbc_table:}}` — database values containing `|` or `}}` could inject extra template parameters or close the template call; values are now escaped via `escapeTemplateParam()` using `{{!}}` and HTML entities
- Fixed fake `{{{variable}}}` injection in `{{#for_odbc_table:}}` — database values containing `{{{` are now HTML-entity-escaped before substitution
- Fixed password exposure in `ODBCConnectionManager::testConnection()` — `odbc_errormsg()` was passed directly to the error message without first calling `sanitizeErrorMessage()`; credentials in the DSN could appear in browser output
- Removed CSRF tokens from admin GET URL parameters — tokens were embedded in `action=test` and `action=tables` links, causing them to appear in server logs, browser history, and HTTP Referer headers; read-only GET actions now require no token (standard MediaWiki practice)
- Added `INFORMATION_SCHEMA` and `SYS.` to the SQL keyword blocklist to prevent metadata enumeration via composed queries

### Fixed

- **CRITICAL**: Fixed `SpecialODBCAdmin::showColumns()` method missing entirely — its body was accidentally merged into the catch block of `showTables()`, causing a PHP parse error that prevented the entire extension from loading
- **CRITICAL**: Fixed `executeComposed()` always emitting both `TOP n` (SQL Server) and `LIMIT n` (MySQL/PostgreSQL) in the same query — every database rejected one of the two syntaxes; now uses driver-aware `requiresTopSyntax()` to emit only the correct syntax
- Fixed misleadingly named constants in `ODBCConnectionManager` — `SQL_ATTR_CONNECTION_TIMEOUT` (value 2) was actually the `SQL_HANDLE_DBC` handle type for `odbc_setoption()`; renamed to `SQL_HANDLE_DBC` to accurately document purpose
- Fixed `EDConnectorOdbcGeneric::disconnect()` connection leak — standalone External Data connections (not via `odbc_source`) were opened but never closed; `disconnect()` now explicitly calls `odbc_close()` for non-managed connections
- Fixed incorrect comment in `EDConnectorOdbcGeneric::disconnect()` claiming the code used `odbc_pconnect` (persistent connections); the code has always used `odbc_connect`
- Replaced deprecated `wfLogWarning()` (removed in MW 1.43) with `wfDebugLog( 'odbc', ... )` throughout `ODBCQueryRunner` and `ODBCConnectionManager`

### Added

- Added `ODBCQueryRunner::requiresTopSyntax()` — detects SQL Server, MS Access, and Sybase drivers from config to select `TOP n` vs `LIMIT n` row-limit syntax
- Added `MAX_CLAUSE_LENGTH = 1000` constant and per-clause length enforcement in `executeComposed()` to prevent resource exhaustion via excessively long WHERE/FROM/ORDER BY/etc. inputs
- Added `ODBCParserFunctions::escapeTemplateParam()` helper for safe wikitext template parameter value escaping
- Added `separator=` parameter to `{{#odbc_query:}}` — allows specifying an alternate delimiter for `parameters=` when parameter values themselves contain commas (e.g., `separator=|` for names like "Smith, John")

### Documentation

- **README**: Fixed incorrect maintenance script citation — `rebuildrecentchanges.php` has nothing to do with parser cache; corrected to `purgeParserCache.php` / null edit
- **README**: Removed non-existent `LICENSE` file from the File Structure listing
- **README**: Updated `parameters=` table entry to document the comma limitation and `separator=` workaround
- **README**: Updated File Structure section to accurately reflect all files present in the repository

## [1.0.1] - 2026-03-02

### Security

- **CRITICAL**: Fixed SQL injection vulnerability in column alias building - now validates all identifiers
- **CRITICAL**: Added strict identifier validation for column and table names (alphanumeric, underscore, dot only, max 128 chars)
- Added password sanitization in ODBC error messages to prevent credential exposure
- Enhanced control character stripping in SQL sanitizer (now removes all C0 control characters)
- Improved CSRF token validation consistency across GET and POST requests in admin interface
- Added query logging for security audit trails (logged to debug log)
- Enforced connection pool size limit (max 10 connections) to prevent resource exhaustion

### Fixed

- **CRITICAL**: Fixed incorrect LIMIT enforcement - now applies LIMIT in SQL (using TOP/LIMIT syntax) instead of only post-fetch
- Fixed race condition in connection pooling - now checks connection health before reusing cached connections
- Fixed resource leaks in error paths - added proper try-finally blocks for result resource cleanup
- Fixed encoding detection false positives - added more character sets and improved detection logic
- Fixed missing resource cleanup when exceptions occur during query execution
- Fixed case sensitivity in magic words (changed from 0 to 1) - now `{{#ODBC_QUERY:}}` works correctly
- Fixed inconsistent parameter naming - standardized on `source=` (still accepts legacy parameter for compatibility)
- Fixed data mapping length validation - now enforces limits to prevent memory exhaustion attacks

### Changed

- Improved error handling throughout - consistent use of MWException and proper cleanup
- Replaced hardcoded magic numbers with constants (SQL_ATTR_CONNECTION_TIMEOUT, SQL_ATTR_QUERY_TIMEOUT)
- Migrated Special:ODBCAdmin to use Html helper methods instead of raw HTML strings
- Enhanced connection manager to log timeout setting failures instead of silently suppressing
- Improved getTables() and getTableColumns() to log errors instead of silently returning empty arrays
- Updated encoding detection to include ISO-8859-15, Windows-1252, and ASCII

### Added

- Added `validateIdentifier()` method for strict SQL identifier validation
- Added `sanitizeErrorMessage()` to strip passwords from connection error messages
- Added connection health checks before returning cached connections
- Added connection pool size enforcement with automatic cleanup of oldest connection
- Added comprehensive query logging with source ID, SQL snippet, and row count
- Added query result caching with proper cleanup on errors
- Added new i18n messages: `odbc-error-identifier-too-long`, `odbc-error-invalid-identifier`, `odbc-error-invalid-token`, `odbc-admin-query-sql`
- Created SECURITY.md with comprehensive security documentation and best practices
- Added Html namespace import for improved HTML generation in Special:ODBCAdmin

### Documentation

- **README**: Added critical security warning about plain-text credentials in LocalSettings.php
- **README**: Documented connection string escaping requirements for special characters
- **README**: Clarified prepared statement array format options
- **README**: Expanded security considerations section with specific protection mechanisms
- **README**: Improved permission model documentation (odbc-query vs odbc-admin)
- **README**: Enhanced cache behavior explanation (per-query cache keys, no manual invalidation)
- **README**: Added warning to `allow_queries` documentation
- **extension.json**: Improved configuration descriptions with more detail
- **composer.json**: Enhanced External Data suggestion description

### Removed

- Removed unnecessary `getGroupName()` override in SpecialODBCAdmin (default 'other' is correct)

## [1.0.0] - Initial Release

### Added

- Initial release with ODBC database connectivity for MediaWiki
- Standalone parser functions: `{{#odbc_query:}}`, `{{#odbc_value:}}`, `{{#for_odbc_table:}}`, `{{#display_odbc_table:}}`, `{{#odbc_clear:}}`
- External Data extension integration (odbc_generic connector)
- Prepared statement support for secure parameterized queries
- SQL injection protection via keyword blocklist and pattern detection
- Special:ODBCAdmin interface for testing connections, browsing tables, and running test queries
- Permission system with `odbc-query` and `odbc-admin` rights
- Query result caching via MediaWiki object cache
- Support for System DSN, driver-based, and full connection string configurations
- Configurable query timeout (global and per-source)
- Per-source `allow_queries` override for composed queries
- Connection pooling for performance
- Automatic UTF-8 encoding conversion
- Comprehensive i18n support (English messages and documentation)

### Security Features (Initial)

- Dangerous SQL keyword blocking (DROP, DELETE, INSERT, UPDATE, EXEC, etc.)
- CSRF protection for admin interface
- SELECT-only enforcement in admin test queries
- Permission-based access control
- Error messages don't expose full SQL queries to users
- Query result row limits (default 1000, configurable)

---

## Version Numbering

- **Major version**: Incompatible API changes
- **Minor version**: New features, backward compatible
- **Patch version**: Bug fixes, backward compatible

## Links

- [Extension Page](https://www.mediawiki.org/wiki/Extension:ODBC)
- [Repository](https://github.com/nordstern-group/mediawiki-odbc)
- [Issue Tracker](https://github.com/nordstern-group/mediawiki-odbc/issues)
