param(
    [string]$PhpDirectory,
    [string]$PythonDirectory,
    [string]$LibreOfficeDirectory = "$env:ProgramFiles\LibreOffice",
    [string]$TesseractDirectory = "$env:LOCALAPPDATA\Programs\Tesseract-OCR",
    [switch]$StageOnly,
    [switch]$Rebuild
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root = Split-Path $PSScriptRoot -Parent
Set-Location $root
if (-not [Environment]::Is64BitOperatingSystem) { throw 'An x64 Windows build machine is required.' }
if (-not $PhpDirectory) { $PhpDirectory = Split-Path (Get-Command php -ErrorAction Stop).Source -Parent }
if (-not $PythonDirectory) {
    $PythonDirectory = (& python -c 'import sys; print(sys.base_prefix)').Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Could not identify the build-time Python installation.' }
}

function Invoke-Checked([string]$Executable, [string[]]$Arguments) {
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Executable failed with exit code $LASTEXITCODE." }
}

function Copy-Tree([string]$Source, [string]$Destination, [string[]]$Exclusions = @()) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    & robocopy $Source $Destination /E /NFL /NDL /NJH /NJS /NP /XD __pycache__ @Exclusions /XF '*.pyc' '*.pyo' | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "Could not stage $Source." }
}

foreach ($file in @("$PhpDirectory\php.exe", "$PythonDirectory\python.exe",
    "$LibreOfficeDirectory\program\soffice.com", "$TesseractDirectory\tesseract.exe",
    "$TesseractDirectory\tessdata\eng.traineddata")) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing build input: $file" }
}
$expected = Get-Content "$root\desktop\runtime-versions.json" -Raw | ConvertFrom-Json
$actualPhp = (& "$PhpDirectory\php.exe" -n -r 'echo PHP_VERSION;').Trim()
$actualPython = (& "$PythonDirectory\python.exe" -c 'import platform; print(platform.python_version())').Trim()
$actualOffice = (& "$LibreOfficeDirectory\program\soffice.com" --version | Out-String).Trim()
$actualTesseract = (& "$TesseractDirectory\tesseract.exe" --version | Out-String).Trim()
if ($actualPhp -ne $expected.php -or $actualPython -ne $expected.python -or
    $actualOffice -notmatch [regex]::Escape($expected.libreoffice) -or
    $actualTesseract -notmatch [regex]::Escape($expected.tesseract)) {
    throw 'Runtime versions differ from desktop\runtime-versions.json. Review and update the controlled runtime versions before building.'
}
$version = (Get-Content package.json -Raw | ConvertFrom-Json).version
$build = Join-Path $root '.desktop-build'
$payload = Join-Path $build 'payload'
if (Test-Path -LiteralPath $payload) {
    if (-not $Rebuild) {
        throw 'A staged payload already exists. Pass -Rebuild to replace only the generated .desktop-build\payload directory.'
    }
    Remove-Item -LiteralPath $payload -Recurse -Force
}
New-Item -ItemType Directory -Path $payload -Force | Out-Null

Invoke-Checked 'npm.cmd' @('run', 'test:desktop')
Invoke-Checked "$PhpDirectory\php.exe" @('vendor\bin\phpunit', '--configuration', 'phpunit.desktop.xml')
Invoke-Checked 'npm.cmd' @('run', 'build')
if (-not (Test-Path 'node_modules\electron\dist\electron.exe')) {
    Invoke-Checked 'node' @('node_modules\electron\install.js')
}

$application = Join-Path $payload 'app'
New-Item -ItemType Directory -Path "$application\bootstrap\cache" -Force | Out-Null
foreach ($directory in @('app', 'config', 'routes', 'resources\views', 'database\migrations', 'database\seeders')) {
    Copy-Tree (Join-Path $root $directory) (Join-Path $application $directory)
}
Copy-Tree "$root\public\build" "$application\public\build"
Copy-Item "$root\public\index.php", "$root\public\favicon.ico", "$root\public\robots.txt" "$application\public"
Copy-Item "$root\bootstrap\app.php", "$root\bootstrap\providers.php" "$application\bootstrap"
Copy-Item "$root\artisan", "$root\composer.json", "$root\composer.lock" $application
New-Item -ItemType Directory -Path "$application\scripts" -Force | Out-Null
Copy-Item "$root\scripts\convert_document.py", "$root\scripts\conversion-capabilities.json" "$application\scripts"
[IO.File]::WriteAllText("$application\desktop-version.json", (@{ version = $version } | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))
Copy-Item "$root\desktop\supervisor.py", "$root\desktop\router.php" $payload
Invoke-Checked 'composer' @('install', "--working-dir=$application", '--no-dev', '--prefer-dist', '--no-interaction', '--no-scripts', '--optimize-autoloader')

$php = Join-Path $payload 'php'
New-Item -ItemType Directory -Path "$php\ext" -Force | Out-Null
Copy-Item "$PhpDirectory\php.exe" $php
Get-ChildItem $PhpDirectory -Filter '*.dll' |
    Where-Object Name -NotIn @('php8apache2_4.dll', 'php8phpdbg.dll') |
    Copy-Item -Destination $php
foreach ($extension in @('curl', 'fileinfo', 'mbstring', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'sqlite3', 'zip')) {
    Copy-Item "$PhpDirectory\ext\php_$extension.dll" "$php\ext"
}
Copy-Item "$PhpDirectory\license.txt", "$PhpDirectory\readme-redist-bins.txt" $php
Copy-Item "$root\desktop\php.ini" $php

$python = Join-Path $payload 'python'
New-Item -ItemType Directory -Path $python -Force | Out-Null
Copy-Item "$PythonDirectory\python.exe", "$PythonDirectory\python3.dll", "$PythonDirectory\python3*.dll", "$PythonDirectory\LICENSE.txt" $python
Copy-Tree "$PythonDirectory\DLLs" "$python\DLLs"
Copy-Tree "$PythonDirectory\Lib" "$python\Lib" @('site-packages', 'test', 'tests', 'ensurepip', 'idlelib', 'tkinter', 'turtledemo', 'venv')
$pythonDll = Get-ChildItem $python -Filter 'python3*.dll' | Where-Object Name -NE 'python3.dll' | Select-Object -First 1
@('.', 'Lib', 'DLLs', 'Lib\site-packages', 'import site') | Set-Content (Join-Path $python ($pythonDll.BaseName + '._pth')) -Encoding ASCII
Invoke-Checked "$PythonDirectory\python.exe" @('-m', 'pip', 'install', '--only-binary=:all:', '--no-compile', '--no-deps', '--target', "$python\Lib\site-packages", '--requirement', "$root\scripts\requirements-desktop.txt")

Copy-Tree $LibreOfficeDirectory "$payload\libreoffice"
New-Item -ItemType Directory -Path "$payload\tesseract\tessdata", "$payload\tesseract\doc" -Force | Out-Null
Copy-Item "$TesseractDirectory\tesseract.exe", "$TesseractDirectory\*.dll" "$payload\tesseract"
Copy-Tree "$TesseractDirectory\doc" "$payload\tesseract\doc"
Copy-Item "$TesseractDirectory\tessdata\eng.traineddata", "$TesseractDirectory\tessdata\osd.traineddata", "$TesseractDirectory\tessdata\pdf.ttf" "$payload\tesseract\tessdata"
Copy-Tree "$TesseractDirectory\tessdata\configs" "$payload\tesseract\tessdata\configs"

$crt = Join-Path $build 'vc-runtime'
Invoke-Checked 'node' @('scripts\extract-vc-runtime.cjs', "$build\downloads", $crt)
$signature = Get-AuthenticodeSignature "$build\downloads\VC_redist.x64.exe"
if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') {
    throw 'The pinned Microsoft runtime signature is not valid.'
}
foreach ($destination in @($php, $python, "$payload\libreoffice\program")) {
    Copy-Item "$crt\*.dll", "$crt\SOURCE.txt" $destination
}

New-Item -ItemType Directory -Path "$payload\notices" -Force | Out-Null
Copy-Item "$root\THIRD_PARTY_NOTICES.md", "$root\docs\LICENSING.md" "$payload\notices"
Copy-Item "$root\node_modules\electron\dist\LICENSE", "$root\node_modules\electron\dist\LICENSES.chromium.html" "$payload\notices"
Invoke-Checked 'node' @('scripts\write-runtime-inventory.cjs', $payload)
& "$payload\python\python.exe" -I -c 'import sys, PIL, pdfplumber, pypdfium2, docx, pptx, openpyxl, pytesseract; print("Bundled Python:", sys.version); print("Location:", sys.prefix)'
if ($LASTEXITCODE -ne 0) { throw 'The staged Python runtime is not self-contained.' }
Invoke-Checked 'node' @('scripts\smoke-desktop.cjs', '--payload', $payload)

Add-Type -AssemblyName System.Drawing
New-Item -ItemType Directory -Path "$root\desktop\assets" -Force | Out-Null
$bitmap = New-Object System.Drawing.Bitmap 256, 256
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.Clear([System.Drawing.Color]::FromArgb(104, 84, 219))
$white = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::White)
$graphics.FillRectangle($white, 60, 38, 112, 170)
$purple = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(104, 84, 219))
$graphics.FillRectangle($purple, 80, 80, 72, 12)
$graphics.FillRectangle($purple, 80, 110, 72, 12)
$graphics.FillRectangle($purple, 80, 140, 50, 12)
$icon = [System.Drawing.Icon]::FromHandle($bitmap.GetHicon())
$stream = [IO.File]::Create("$root\desktop\assets\icon.ico")
$icon.Save($stream)
$stream.Dispose()
$icon.Dispose()
$graphics.Dispose()
$white.Dispose()
$purple.Dispose()
$bitmap.Dispose()

if (-not $StageOnly) {
    Invoke-Checked 'npm.cmd' @('run', 'package:windows')
    Get-FileHash "$root\dist\officeConverters-Setup-x64.exe" -Algorithm SHA256 |
        Select-Object Algorithm, Hash | ConvertTo-Json | Set-Content "$root\dist\officeConverters-Setup-x64.sha256.json"
}
Write-Host "Staged standalone Office Converters $version at $payload"
