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
   This installs dev dependencies (PHPUnit, PHP_CodeSniffer, PHPStan) required for running tests and linting.

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

The extension has a standalone PHPUnit test suite that runs without a MediaWiki installation. Tests are in `tests/unit/` and use a lightweight bootstrap (`tests/bootstrap.php`) that provides MW type stubs.

**Running the full test suite:**
```bash
composer test
```

**Running a specific test file:**
```bash
vendor/bin/phpunit tests/unit/ODBCParserFunctionsTest.php
```

**Running linting and static analysis:**
```bash
composer phpcs        # PHP_CodeSniffer (MediaWiki coding standard)
composer phpstan      # PHPStan level 3
```

Currently tested areas include `ODBCParserFunctions` (data mapping, escaping, row selection), `ODBCConnectionManager` (connection string building, config validation), and `ODBCQueryRunner` (sanitization, identifier validation, row-limit syntax). Adding test coverage for `SpecialODBCAdmin` and `EDConnectorOdbcGeneric` is actively encouraged.

When making changes, also manually test:
1. The specific parser function(s) affected
2. The `Special:ODBCAdmin` interface if touching the admin page
3. External Data integration if touching `EDConnectorOdbcGeneric`
4. Both DSN mode and driver mode connections if touching `ODBCConnectionManager`

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

### Architecture (v2.0.0)

| Item | Description |
|------|-------------|
| P3-001 | Convert `ODBCConnectionManager` to a MediaWiki service (eliminate static state) |
| P3-002 | Introduce interfaces for `ODBCConnectionManager` and `ODBCQueryRunner` (enables mocking) |
| P3-006 | Parameterized WHERE — allow safe runtime parameter injection in composed queries |

### Quality / Infrastructure

| Item | Description |
|------|-------------|
| P3-003 | Expand unit test suite — `SpecialODBCAdmin` and `EDConnectorOdbcGeneric` have no test coverage yet |
| P3-004 | Raise PHPStan level from 3 to 5+ (part of v2.0.0 quality target) |
| P2-093 | Commit `composer.lock` — run `composer install` on PHP 8.1+ and commit the lockfile |

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
