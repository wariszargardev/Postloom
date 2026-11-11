# Starts everything Postloom needs locally: MySQL, web server, scheduler, queue worker and Vite.
# With Windows Terminal they open as four tabs in one window; without it, as four windows.
$root = $PSScriptRoot
$pidDir = Join-Path $root 'storage\app\postloom-pids'
$runDir = Join-Path $root 'storage\app\postloom-run'
$legacyPidFile = Join-Path $root 'storage\app\postloom-pids.txt'
$mysqlStart = Join-Path (Split-Path $root -Parent) '.mysql-brc\start-mysql.ps1'

if ((Test-Path $legacyPidFile) -or ((Test-Path $pidDir) -and (Get-ChildItem $pidDir -Filter *.pid))) {
    'Postloom looks like it is already running. Run ./stop.ps1 first.'
    return
}

# MySQL may already run as the "MySQL-BRC" Windows service, whose port start-mysql.ps1 can't always see.
# Connecting to the port is the reliable check; only start MySQL when nothing answers.
$mysqlAnswers = $false
try {
    $client = New-Object System.Net.Sockets.TcpClient
    $mysqlAnswers = $client.ConnectAsync('127.0.0.1', 3307).Wait(1000)
    $client.Close()
} catch {
    $mysqlAnswers = $false
}

if ($mysqlAnswers) {
    'MySQL running on 127.0.0.1:3307'
} else {
    & $mysqlStart
}

New-Item -ItemType Directory -Force -Path $pidDir, $runDir | Out-Null

# The web server runs PHP's built-in server directly so the upload limits below apply to this project only
# (Herd's php.ini allows 2 MB per file). "php artisan serve" starts a child process that would not get them.
$uploadLimits = '-d upload_max_filesize=512M -d post_max_size=520M -d memory_limit=512M'
# Laravel's router file loads index.php from the current folder, so the server must run inside public/.
$router = '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'

$processes = [ordered]@{
    'web'       = "Set-Location public; php $uploadLimits -S localhost:8000 -t . $router"
    'scheduler' = 'php artisan schedule:work'
    'queue'     = 'php artisan queue:work --tries=1 --timeout=300'
    'vite'      = 'npm run dev'
}

# Windows Terminal starts tabs with its own environment, which may not include Herd's php or Node.
# Each tab gets the PATH of the terminal that ran start.ps1, so php, npm and ffmpeg resolve the same way.
foreach ($tool in 'php', 'npm') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        "Can't find '$tool' on PATH. Open a terminal where '$tool --version' works and run ./start.ps1 there."
        return
    }
}
$pathForTabs = $env:Path -replace "'", "''"

# One small script per process: it names the tab, saves its own process id for stop.ps1, then runs the command.
# Running a file (instead of -Command) keeps Windows Terminal from reading ";" as "next tab".
$scripts = [ordered]@{}
foreach ($name in $processes.Keys) {
    $script = Join-Path $runDir "$name.ps1"
    @(
        "`$env:Path = '$pathForTabs'"
        "`$host.UI.RawUI.WindowTitle = 'Postloom $name'"
        "Set-Location '$root'"
        "Set-Content -Path '$(Join-Path $pidDir "$name.pid")' -Value `$PID -Encoding ascii"
        $processes[$name]
    ) | Set-Content -Path $script -Encoding utf8
    $scripts[$name] = $script
}

$terminal = Get-Command wt.exe -ErrorAction SilentlyContinue
# Full path: Windows Terminal resolves programs with its own PATH, which may not include Windows PowerShell.
$powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

if ($terminal) {
    $tabs = foreach ($name in $scripts.Keys) {
        "new-tab --title `"Postloom $name`" --suppressApplicationTitle -d `"$root`" `"$powershell`" -NoExit -ExecutionPolicy Bypass -File `"$($scripts[$name])`""
    }
    Start-Process $terminal.Source -ArgumentList ('-w new ' + ($tabs -join ' ; '))
    $layout = 'one Windows Terminal window, one tab each'
} else {
    foreach ($name in $scripts.Keys) {
        Start-Process $powershell -ArgumentList '-NoExit', '-ExecutionPolicy', 'Bypass', '-File', "`"$($scripts[$name])`""
    }
    $layout = 'their own windows (install Windows Terminal to get tabs)'
}

$deadline = (Get-Date).AddSeconds(15)
while ((Get-ChildItem $pidDir -Filter *.pid).Count -lt $processes.Count -and (Get-Date) -lt $deadline) {
    Start-Sleep -Milliseconds 300
}

$running = (Get-ChildItem $pidDir -Filter *.pid).Count
if ($running -lt $processes.Count) {
    "Only $running of $($processes.Count) processes reported in. Check the Postloom tabs for errors."
}

"Postloom is running on http://localhost:8000 (web, scheduler, queue and vite in $layout)."
