#!/usr/bin/env pwsh
# push-wiki.ps1
# Run this after initializing the wiki on GitHub (see README below).
# Usage: ./push-wiki.ps1
#        ./push-wiki.ps1 -Message "Updated wiki"

param(
    [string]$Message = "Update wiki documentation"
)

$repoOwner = "slickdexic"
$repoName  = "ODBC"
$wikiDir   = Join-Path $env:TEMP "ODBC-wiki-push"
$sourceDir = Join-Path $PSScriptRoot "wiki"

Write-Host "=== MediaWiki ODBC Extension — Wiki Push Script ===" -ForegroundColor Cyan

# 1. Verify source pages exist
if (-not (Test-Path (Join-Path $sourceDir "Home.md"))) {
    Write-Error "wiki/ directory not found or missing Home.md. Run this script from the repo root."
    exit 1
}

# 2. Clone the wiki repo
Write-Host "`nCloning wiki repository..." -ForegroundColor Yellow
if (Test-Path $wikiDir) {
    Remove-Item -Recurse -Force $wikiDir
}
git clone "https://github.com/$repoOwner/$repoName.wiki.git" $wikiDir
if ($LASTEXITCODE -ne 0) {
    Write-Error @"
Failed to clone wiki repository.

The GitHub wiki must be initialized before this script can run:
  1. Go to https://github.com/$repoOwner/$repoName/wiki
  2. Click 'Create the first page'
  3. Save any content (e.g., just type 'stub' and save)
  4. Re-run this script — it will replace that content with the real pages

"@
    exit 1
}

# 3. Copy all wiki pages into the cloned repo
Write-Host "`nCopying wiki pages..." -ForegroundColor Yellow
$pages = Get-ChildItem -Path $sourceDir -Filter "*.md"
foreach ($page in $pages) {
    Copy-Item $page.FullName -Destination $wikiDir -Force
    Write-Host "  Copied: $($page.Name)"
}

# 4. Stage, commit, push
Push-Location $wikiDir
try {
    git add -A
    $status = git status --porcelain
    if (-not $status) {
        Write-Host "`nNo changes to push." -ForegroundColor Green
    } else {
        Write-Host "`nChanges to be committed:" -ForegroundColor Yellow
        git status --short
        git commit -m $Message
        git push origin master 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Host "`nWiki pushed successfully!" -ForegroundColor Green
            Write-Host "View at: https://github.com/$repoOwner/$repoName/wiki" -ForegroundColor Cyan
        } else {
            Write-Error "Push failed. Check your authentication (GitHub token/credential manager)."
            exit 1
        }
    }
} finally {
    Pop-Location
}
