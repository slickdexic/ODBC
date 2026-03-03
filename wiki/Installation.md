# Installation

## Requirements

| Requirement | Minimum Version | Notes |
|-------------|----------------|-------|
| MediaWiki | 1.39.0 | |
| PHP | 7.4.0 | |
| PHP ext-odbc | Any | Required — see below |
| ODBC Driver | Database-specific | Install separately for your target database |
| External Data | Any compatible | *Optional* — only needed for ED integration |

### Installing the PHP ODBC Extension

The PHP `ext-odbc` module must be enabled for the extension to function.

**Windows (typical XAMPP/WAMP installations):**
```ini
; Uncomment this line in php.ini:
extension=php_odbc.dll
```

Restart IIS or Apache after enabling. On most Windows PHP distributions the DLL is already present.

**Linux — Debian / Ubuntu:**
```bash
sudo apt install php-odbc
sudo phpenmod odbc
sudo systemctl restart apache2   # or php-fpm
```

**Linux — RHEL / CentOS / Rocky:**
```bash
sudo yum install php-odbc
sudo systemctl restart httpd
```

**Verification:**
```bash
php -m | grep -i odbc
```
You should see `odbc` in the output. You can also check `phpinfo()` for an "odbc" section.

---

## Installing the Extension

### Step 1 — Copy the files

Copy or clone the extension into your MediaWiki `extensions/` directory so that the folder is named `ODBC`:

```
extensions/
└── ODBC/
    ├── extension.json
    ├── ODBCMagic.php
    ├── composer.json
    ├── includes/
    └── i18n/
```

> **Important:** The directory must be named exactly `ODBC` (case-sensitive on Linux).

You can clone directly from GitHub:
```bash
cd /path/to/mediawiki/extensions
git clone https://github.com/slickdexic/ODBC.git ODBC
```

Or download a release ZIP and extract it as `extensions/ODBC/`.

### Step 2 — Register in LocalSettings.php

Add the following line to `LocalSettings.php`, **after** any `require_once` for MediaWiki core but **before** the closing PHP tag:

```php
wfLoadExtension( 'ODBC' );
```

If you plan to use [[External-Data-Integration|External Data integration]], load them in this order:

```php
wfLoadExtension( 'ExternalData' );
wfLoadExtension( 'ODBC' );
```

> **Note:** If you want to disable External Data integration, set `$wgODBCExternalDataIntegration = false;` *before* calling `wfLoadExtension( 'ODBC' )`.

### Step 3 — Configure at least one data source

The extension requires at least one entry in `$wgODBCSources` to be useful. See [[Configuration]] for the full reference. A minimal example:

```php
$wgODBCSources['my-source'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'db.example.com,1433',
    'database' => 'MyDatabase',
    'user'     => 'wiki_reader',
    'password' => 'secret',
];
```

### Step 4 — Verify the installation

1. Navigate to **Special:Version** on your wiki. The ODBC extension should appear in the "Parser hooks" section. Confirm the version shown matches the version in `extension.json` in your installation directory (e.g. `extensions/ODBC/extension.json`).
2. Navigate to **Special:ODBCAdmin** (requires `odbc-admin` permission, granted to sysops by default).
3. Click **Test Connection** next to your configured source. A green "Connection successful" message confirms the setup is working.

---

## Installing ODBC Database Drivers

Each target database requires its own ODBC driver installed on the *web server*. The extension itself does not include any drivers.

| Database | Driver Download |
|----------|----------------|
| SQL Server | [Microsoft ODBC Driver for SQL Server](https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server) |
| MySQL / MariaDB | [MySQL Connector/ODBC](https://dev.mysql.com/downloads/connector/odbc/) |
| PostgreSQL | [psqlODBC](https://odbc.postgresql.org/) |
| Oracle | [Oracle Instant Client + ODBC](https://www.oracle.com/database/technologies/instant-client.html) |
| Microsoft Access | Included with Microsoft Office or [AccessDatabaseEngine](https://www.microsoft.com/en-us/download/details.aspx?id=54920) |
| IBM DB2 | [IBM Data Server Driver for ODBC and CLI](https://www.ibm.com/support/pages/download-dsdriver) |

See [[Supported-Databases]] for a full compatibility table.

### Verifying an ODBC Driver is Registered

**Linux (unixODBC):**
```bash
odbcinst -q -d        # list all registered drivers
isql -v "MyDSN" user password  # test a DSN connection
```

**Windows:**
Open `odbcad32.exe` (ODBC Data Source Administrator) → Drivers tab to see installed drivers.

---

## Upgrading

See [UPGRADE.md](https://github.com/slickdexic/ODBC/blob/main/UPGRADE.md) in the repository for version-specific upgrade notes.

The key steps for any upgrade are:
1. Replace all extension files with the new version.
2. Read the UPGRADE.md entry for your target version.
3. Run `php maintenance/purgeParserCache.php` if the version notes recommend it.
4. Test with Special:ODBCAdmin.
