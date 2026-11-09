# Stops the processes started by start.ps1. MySQL keeps running unless -WithMySql is passed (other projects share it).
param([switch]$WithMySql)

$root = $PSScriptRoot
$pidFile = Join-Path $root 'storage\app\postloom-pids.txt'

if (Test-Path $pidFile) {
    foreach ($line in Get-Content $pidFile) {
        $name, $processId = $line -split '='
        taskkill /PID $processId /T /F *> $null
        "Stopped $name"
    }
    Remove-Item $pidFile
} else {
    'Postloom is not running (no PID file).'
}

if ($WithMySql) {
    & (Join-Path (Split-Path $root -Parent) '.mysql-brc\stop-mysql.ps1')
}
