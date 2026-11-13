# Stops the processes started by start.ps1 (their tabs or windows close). MySQL keeps running unless -WithMySql is passed (other projects share it).
param([switch]$WithMySql)

$root = $PSScriptRoot
$pidDir = Join-Path $root 'storage\app\postloom-pids'
$legacyPidFile = Join-Path $root 'storage\app\postloom-pids.txt'

$entries = @()

if (Test-Path $pidDir) {
    $entries += Get-ChildItem $pidDir -Filter *.pid | ForEach-Object {
        [pscustomobject]@{ Name = $_.BaseName; ProcessId = (Get-Content $_.FullName -Raw).Trim(); File = $_.FullName }
    }
}

if (Test-Path $legacyPidFile) {
    $entries += Get-Content $legacyPidFile | ForEach-Object {
        $name, $processId = $_ -split '='
        [pscustomobject]@{ Name = $name; ProcessId = $processId; File = $null }
    }
}

if ($entries.Count -eq 0) {
    'Postloom is not running (no PID files).'
} else {
    foreach ($entry in $entries) {
        taskkill /PID $entry.ProcessId /T /F *> $null
        "Stopped $($entry.Name)"
        if ($entry.File) { Remove-Item $entry.File -ErrorAction SilentlyContinue }
    }
    Remove-Item $legacyPidFile -ErrorAction SilentlyContinue
}

Remove-Item (Join-Path $root 'storage\app\postloom-run\tunnel-url.txt') -ErrorAction SilentlyContinue

if ($WithMySql) {
    & (Join-Path (Split-Path $root -Parent) '.mysql-brc\stop-mysql.ps1')
}
