# Starts everything Postloom needs locally: MySQL, web server, scheduler, queue worker and Vite.
$root = $PSScriptRoot
$pidFile = Join-Path $root 'storage\app\postloom-pids.txt'
$mysqlStart = Join-Path (Split-Path $root -Parent) '.mysql-brc\start-mysql.ps1'

if (Test-Path $pidFile) {
    'Postloom looks like it is already running. Run ./stop.ps1 first.'
    return
}

& $mysqlStart

# The web server runs PHP's built-in server directly so the upload limits below apply to this project only
# (Herd's php.ini allows 2 MB per file). "php artisan serve" starts a child process that would not get them.
$uploadLimits = '-d upload_max_filesize=512M -d post_max_size=520M -d memory_limit=512M'
$router = 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'

$processes = [ordered]@{
    'web'       = "php $uploadLimits -S localhost:8000 -t public $router"
    'scheduler' = 'php artisan schedule:work'
    'queue'     = 'php artisan queue:work --tries=1 --timeout=300'
    'vite'      = 'npm run dev'
}

$started = foreach ($name in $processes.Keys) {
    $command = "`$host.UI.RawUI.WindowTitle = 'Postloom $name'; Set-Location '$root'; $($processes[$name])"
    $process = Start-Process powershell -ArgumentList '-NoExit', '-Command', $command -PassThru
    "$name=$($process.Id)"
}

$started | Set-Content -Path $pidFile -Encoding ascii
'Postloom is running on http://localhost:8000 (web, scheduler, queue and vite each have their own window).'
