$ErrorActionPreference = 'Stop'
$base = Join-Path $env:LOCALAPPDATA 'OfficeConverters\mysql-8.4.10\mysql-8.4.10-winx64'
if (-not (Test-Path -LiteralPath "$base\data\mysql")) {
    throw 'The dedicated MySQL data directory is not initialized. Follow the README installation instructions.'
}
if (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue) {
    throw 'Port 3307 is already in use. Run php artisan converters:health to identify the configured database.'
}
& "$base\bin\mysqld.exe" --no-defaults "--basedir=$base" "--datadir=$base\data" --port=3307 --bind-address=127.0.0.1 --mysqlx=OFF --console
if ($LASTEXITCODE -ne 0) {
    throw "The dedicated MySQL server exited with code $LASTEXITCODE."
}
