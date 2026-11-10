# Builds docs/guides/<platform>/<platform>-setup.mp4 from steps.txt and the screenshots in images/.
# Usage: powershell -File scripts/make-guide-video.ps1 linkedin [-SecondsPerSlide 5]
param(
    [Parameter(Mandatory = $true)][string]$Platform,
    [int]$SecondsPerSlide = 5
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$guideDir = Join-Path $root "docs\guides\$Platform"
$stepsFile = Join-Path $guideDir 'steps.txt'
$outputFile = Join-Path $guideDir "$Platform-setup.mp4"
$ffmpeg = if ($env:FFMPEG_PATH) { $env:FFMPEG_PATH } else { 'ffmpeg' }
$fontSource = 'C:\Windows\Fonts\segoeui.ttf'
$utf8 = New-Object System.Text.UTF8Encoding $false

if (-not (Test-Path $stepsFile)) { throw "Missing $stepsFile" }

$workDir = Join-Path ([System.IO.Path]::GetTempPath()) ("guide-video-" + [guid]::NewGuid())
New-Item -ItemType Directory -Path $workDir | Out-Null
Copy-Item $fontSource (Join-Path $workDir 'font.ttf')

$steps = Get-Content $stepsFile -Encoding UTF8 | Where-Object { $_.Trim() -ne '' -and -not $_.TrimStart().StartsWith('#') }
$clips = @()
$missing = @()
$index = 0

Push-Location $workDir
try {
    foreach ($line in $steps) {
        $index++
        $parts = $line.Split('|', 2)
        $imageName = $parts[0].Trim()
        $caption = if ($parts.Count -gt 1) { $parts[1].Trim() } else { '' }
        $imagePath = Join-Path $guideDir "images\$imageName"
        $clip = ('clip-{0:D2}.mp4' -f $index)
        $captionFile = ('caption-{0:D2}.txt' -f $index)
        [System.IO.File]::WriteAllText((Join-Path $workDir $captionFile), $caption, $utf8)

        $text = "drawtext=fontfile=font.ttf:textfile=${captionFile}:fontcolor=white:fontsize=36"

        if (Test-Path $imagePath) {
            $filter = "scale=1920:960:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(960-ih)/2:color=0x111111,${text}:x=(w-text_w)/2:y=995,format=yuv420p"
            & $ffmpeg -loglevel error -y -loop 1 -t $SecondsPerSlide -i $imagePath -vf $filter -r 25 -c:v libx264 -preset veryfast $clip
        } else {
            $missing += $imageName
            $noteFile = ('note-{0:D2}.txt' -f $index)
            [System.IO.File]::WriteAllText((Join-Path $workDir $noteFile), "Screenshot to add: $imageName", $utf8)
            $filter = "${text}:x=(w-text_w)/2:y=(h/2)-40,drawtext=fontfile=font.ttf:textfile=${noteFile}:fontcolor=0x888888:fontsize=28:x=(w-text_w)/2:y=(h/2)+30,format=yuv420p"
            & $ffmpeg -loglevel error -y -f lavfi -i "color=c=0x111111:s=1920x1080:d=$SecondsPerSlide" -vf $filter -r 25 -c:v libx264 -preset veryfast $clip
        }

        if ($LASTEXITCODE -ne 0) { throw "ffmpeg failed on step $index ($imageName)" }
        $clips += $clip
    }

    $listFile = Join-Path $workDir 'clips.txt'
    [System.IO.File]::WriteAllText($listFile, (($clips | ForEach-Object { "file '$_'" }) -join "`n"), $utf8)
    & $ffmpeg -loglevel error -y -f concat -safe 0 -i 'clips.txt' -c copy -movflags +faststart $outputFile
    if ($LASTEXITCODE -ne 0) { throw 'ffmpeg failed joining the clips' }
} finally {
    Pop-Location
    Remove-Item -Recurse -Force $workDir
}

Write-Host "Built $outputFile ($($clips.Count) slides, $($clips.Count * $SecondsPerSlide) s)"
if ($missing.Count -gt 0) {
    Write-Host "Text slides for missing screenshots: $($missing -join ', ')"
}
