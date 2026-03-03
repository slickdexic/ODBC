# Contributing

Contributions are welcome. This page covers how to set up a development environment, the project's coding standards, and the process for submitting changes.

---

## Getting Started

### Prerequisites

- PHP 7.4 or higher with `ext-odbc` enabled
- A MediaWiki 1.39+ installation for integration testing
- Composer
- Git

### Setting Up a Development Environment

1. **Clone the repository** into your MediaWiki `extensions/` directory:
   ```bash
   cd /path/to/mediawiki/extensions
   git clone https://github.com/slickdexic/ODBC.git ODBC
   cd ODBC
   ```

2. **Install Composer dependencies:**
   ```bash
   composer install
   ```
   > **Note:** There are currently no `require-dev` dependencies defined. This is a known gap — PHPUnit and MediaWiki CodeSniffer will be added in a future release.

3. **Load the extension** in your test MediaWiki's `LocalSettings.php`:
   ```php
   wfLoadExtension( 'ODBC' );
   ```

4. **Configure a test source** — use a locally accessible database or a mock. A SQLite file with the SQLite3 ODBC driver is a lightweight option for basic testing.

---

## Making Changes

### Branching

- Work on a feature branch: `git checkout -b feature/my-feature`
- Bug fixes: `git checkout -b fix/ki-024-union-false-positive`
- Use the issue/KI number in branch names where relevant.

### Coding Standards

The extension follows [MediaWiki's PHP coding conventions](https://www.mediawiki.org/wiki/Manual:Coding_conventions/PHP). Key points:

- Tabs for indentation (not spaces)
- Opening braces on the same line for control structures; new line for class/function declarations
- `camelCase` for variables and method names; `CamelCase` for class names
- PHPDoc blocks on all public methods
- Strings use single quotes unless interpolation is needed
- No short PHP tags; no closing `?>` tag at end of file

Example:
```php
/**
 * Build an ODBC connection string from source configuration.
 *
 * @param array $config Source configuration from $wgODBCSources.
 * @return string Connection string.
 */
public static function buildConnectionString( array $config ): string {
    $parts = [];
    $parts[] = 'Driver={' . $config['driver'] . '}';
    // ...
    return implode( ';', $parts );
}
```

### Testing

There are currently no automated tests. When making changes, manually test:
1. The specific parser function(s) affected
2. The `Special:ODBCAdmin` interface if touching the admin page
3. External Data integration if touching `EDConnectorOdbcGeneric`
4. Both DSN mode and driver mode connections if touching `ODBCConnectionManager`

Adding automated test coverage is actively encouraged — see [improvement_plan.md P3-003](https://github.com/slickdexic/ODBC/blob/main/improvement_plan.md).

---

## Submitting Changes

### Pull Request Process

1. **Ensure your branch is up to date** with `main`:
   ```bash
   git fetch origin
   git rebase origin/main
   ```

2. **Write a clear PR description** including:
   - What problem this solves (reference KI-XXX or a GitHub issue number if applicable)
   - What changed
   - How to test the change
   - Whether there are any backwards-incompatible changes

3. **Update documentation** if: the change affects user-facing behaviour, adds or removes a configuration key, changes parser function parameters, or affects any documented behaviour.

4. **Update CHANGELOG.md** following the [Keep a Changelog](https://keepachangelog.com/) format. Add your entry under `## [Unreleased]`.

5. **Submit the PR** targeting the `main` branch.

---

## Reporting Bugs

Use [GitHub Issues](https://github.com/slickdexic/ODBC/issues) to report bugs.

Include:
- MediaWiki version
- PHP version and OS
- ODBC extension version (from Special:Version)
- Database type and ODBC driver name/version
- Steps to reproduce
- Expected behaviour vs actual behaviour
- Any relevant error messages (sanitize any credentials first)
- Debug log output if available (see [[Troubleshooting#debug-logging]])

**Security vulnerabilities** should be reported privately. See [SECURITY.md](https://github.com/slickdexic/ODBC/blob/main/SECURITY.md) for the responsible disclosure process.

---

## Areas Needing Contribution

The following areas have open improvement plan items and are good candidates for contribution. See [improvement_plan.md](https://github.com/slickdexic/ODBC/blob/main/improvement_plan.md) for full details.

### High Priority

| Item | Description |
|------|-------------|
| P2-017 | Fix `pingConnection()` to use `odbc_tables()` instead of `SELECT 1` — fixes MS Access |
| P2-018 | Move `UNION` to word-boundary regex match — fixes false positive on identifiers like `LABOUR_UNION` |
| P2-021 | Fix ED connector `odbc_source` mode to inherit driver from `$wgODBCSources` |

### Medium Priority

| Item | Description |
|------|-------------|
| P2-007 | Add per-page query count limit (`$wgODBCMaxQueriesPerPage`) |
| P2-019 | Add ODBC connection string value escaping to `buildConnectionString()` |
| P2-020 | Call `validateConfig()` from `connect()` (it's currently dead code) |
| P2-022 | Fix falsy check for `$wgODBCExternalDataIntegration` |
| P2-023 | Add debug log entry when `odbc_setoption()` fails to set timeout |

### Quality / Infrastructure

| Item | Description |
|------|-------------|
| P3-003 | Add a PHPUnit test suite — there are currently zero automated tests |
| P3-004 | Add `.phpcs.xml` and a CI workflow (GitHub Actions) |
| P2-008 | Extract the repeated error handler installation pattern to a shared utility |

---

## File and Message Changes

### Adding a Configuration Variable

1. Add the entry to the `config` object in `extension.json` with a description and default value.
2. Read it in PHP via `MediaWikiServices::getInstance()->getMainConfig()->get( 'ODBC<name>' )`.
3. Document it in [[Configuration]].

### Adding a Parser Function Parameter

1. Update the appropriate method in `ODBCParserFunctions.php` to read the new parameter.
2. Add the parameter to the function's PHPDoc block.
3. Document it in [[Parser-Functions]].

### Adding i18n Messages

1. Add the English string to `i18n/en.json` with a `odbc-` prefix key:
   ```json
   "odbc-my-new-message": "My new message text."
   ```
2. Add a documentation entry to `i18n/qqq.json`:
   ```json
   "odbc-my-new-message": "Displayed when [explain context and usage]."
   ```
3. Use it in PHP via `wfMessage( 'odbc-my-new-message' )->text()`.
