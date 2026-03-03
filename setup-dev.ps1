<#
.SYNOPSIS
    Development environment setup for the MediaWiki ODBC extension.

.DESCRIPTION
    Downloads Composer (if missing), installs PHP dependencies, and verifies
    that all development tools (PHPStan, PHPCS, PHPUnit) are available.

    Run from the extension root: .\setup-dev.ps1

.NOTES
    Prerequisites:
      - PHP 8.1+ on PATH (with ext-curl enabled)
      - php.exe must be allowed through Windows Firewall for outbound HTTPS
#>
param(
    [switch]$SkipComposer,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$ExtRoot = $PSScriptRoot

Write-Host "`n=== ODBC Extension — Development Setup ===" -ForegroundColor Cyan

# ── 1. Verify PHP ────────────────────────────────────────────────────────────
Write-Host "`n[1/4] Checking PHP..." -ForegroundColor Yellow
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Error "PHP is not on PATH. Install PHP 8.1+ and add it to your system PATH."
    exit 1
}
$phpVersion = & php -r "echo PHP_VERSION;"
Write-Host "  PHP $phpVersion at $($php.Source)" -ForegroundColor Green

# Check for ext-curl (needed by Composer)
$hasCurl = & php -m 2>&1 | Select-String -Pattern '^curl$'
if (-not $hasCurl) {
    Write-Warning "  ext-curl is not enabled. Composer requires it. Enable it in php.ini."
}

# ── 2. Ensure Composer ───────────────────────────────────────────────────────
Write-Host "`n[2/4] Checking Composer..." -ForegroundColor Yellow
$composerPhar = Join-Path $ExtRoot 'composer.phar'

# Check for global composer first
$globalComposer = Get-Command composer -ErrorAction SilentlyContinue 2>$null
if ($globalComposer -and (& composer --version 2>&1) -match 'Composer version') {
    $composerCmd = 'composer'
    Write-Host "  Using global Composer: $(& composer --version 2>&1)" -ForegroundColor Green
} elseif (Test-Path $composerPhar) {
    $composerCmd = "php `"$composerPhar`""
    Write-Host "  Using local composer.phar" -ForegroundColor Green
} else {
    Write-Host "  Downloading Composer..." -ForegroundColor Yellow
    try {
        Invoke-WebRequest -Uri 'https://getcomposer.org/download/latest-stable/composer.phar' `
            -OutFile $composerPhar -UseBasicParsing
        $composerCmd = "php `"$composerPhar`""
        Write-Host "  Downloaded composer.phar" -ForegroundColor Green
    } catch {
        Write-Error "Failed to download Composer: $_`nDownload manually from https://getcomposer.org/download/ and place composer.phar in $ExtRoot"
        exit 1
    }
}

# ── 3. Install dependencies ─────────────────────────────────────────────────
if (-not $SkipComposer) {
    Write-Host "`n[3/4] Installing Composer dependencies..." -ForegroundColor Yellow

    $installArgs = 'install', '--no-interaction', '--prefer-dist'

    # ext-odbc may not be available on dev machines
    $hasOdbc = & php -m 2>&1 | Select-String -Pattern '^odbc$'
    if (-not $hasOdbc) {
        Write-Host "  ext-odbc not found locally — ignoring platform requirement" -ForegroundColor DarkYellow
        $installArgs += '--ignore-platform-req=ext-odbc'
    }

    if ($composerCmd -eq 'composer') {
        & composer @installArgs
    } else {
        & php $composerPhar @installArgs
    }

    if ($LASTEXITCODE -ne 0) {
        Write-Error "Composer install failed. Check your network / firewall and retry."
        exit 1
    }
    Write-Host "  Dependencies installed." -ForegroundColor Green
} else {
    Write-Host "`n[3/4] Skipping Composer install (--SkipComposer)" -ForegroundColor DarkGray
}

# ── 4. Verify tools ─────────────────────────────────────────────────────────
Write-Host "`n[4/4] Verifying development tools..." -ForegroundColor Yellow

$vendorBin = Join-Path $ExtRoot 'vendor\bin'

$tools = @(
    @{ Name = 'PHPStan';   Bin = Join-Path $vendorBin 'phpstan.bat';  VersionArg = '--version' },
    @{ Name = 'PHPCS';     Bin = Join-Path $vendorBin 'phpcs.bat';    VersionArg = '--version' },
    @{ Name = 'PHPUnit';   Bin = Join-Path $vendorBin 'phpunit.bat';  VersionArg = '--version' }
)

$missing = @()
foreach ($tool in $tools) {
    if (Test-Path $tool.Bin) {
        $ver = & $tool.Bin $tool.VersionArg 2>&1 | Select-Object -First 1
        Write-Host "  $($tool.Name): $ver" -ForegroundColor Green
    } else {
        Write-Host "  $($tool.Name): NOT FOUND" -ForegroundColor Red
        $missing += $tool.Name
    }
}

if ($missing.Count -gt 0) {
    Write-Warning "Missing tools: $($missing -join ', '). Run: php composer.phar install"
}

# ── Summary ──────────────────────────────────────────────────────────────────
Write-Host "`n=== Setup Complete ===" -ForegroundColor Cyan
Write-Host @"

Useful commands:
  php composer.phar phpstan      Run PHPStan static analysis (level 3)
  php composer.phar phpcs        Run PHP_CodeSniffer (MediaWiki standard)
  php composer.phar test         Run PHPUnit tests
  php -l includes/*.php          Quick syntax check

Git remote:
  origin -> https://github.com/slickdexic/ODBC.git

"@ -ForegroundColor Gray
