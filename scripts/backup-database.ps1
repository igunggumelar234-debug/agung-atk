<#
.SYNOPSIS
    Backup database Agung ATK (MySQL) untuk Windows/Laragon.

.DESCRIPTION
    Membaca kredensial dari .env, menjalankan mysqldump, lalu mengompres
    hasilnya menjadi .sql.gz di folder storage/backups.
    Restore:  Get-Content backup.sql.gz | gunzip | mysql -u root agung_atk
              (atau mysql --default-character-set=utf8mb4 -u root agung_atk < backup.sql)

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup-database.ps1
    powershell -ExecutionPolicy Bypass -File scripts\backup-database.ps1 -Destination D:\backups
#>
param(
    [string]$Destination = (Join-Path $PSScriptRoot "..\storage\backups")
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $root

$envFile = Join-Path $root ".env"
if (-not (Test-Path $envFile)) { throw ".env tidak ditemukan di $root" }

function Get-EnvValue([string]$name) {
    $line = Select-String -Path $envFile -Pattern "^$name=(.*)$" | Select-Object -Last 1
    if (-not $line) { return "" }
    return $line.Matches[0].Groups[1].Value.Trim('"', "'")
}

$dbHost = Get-EnvValue "DB_HOST";  if (-not $dbHost) { $dbHost = "127.0.0.1" }
$dbPort = Get-EnvValue "DB_PORT";  if (-not $dbPort) { $dbPort = "3306" }
$dbName = Get-EnvValue "DB_DATABASE"
$dbUser = Get-EnvValue "DB_USERNAME"
$dbPass = Get-EnvValue "DB_PASSWORD"
$connection = Get-EnvValue "DB_CONNECTION"

if (-not $dbName) { throw "DB_DATABASE belum diisi di .env" }

New-Item -ItemType Directory -Force -Path $Destination | Out-Null
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"

if ($connection -eq "sqlite") {
    $dbPath = Join-Path $root ($dbName -replace "^\.\/", "")
    if (-not (Test-Path $dbPath)) { throw "File SQLite tidak ditemukan: $dbPath" }
    $out = Join-Path $Destination "sqlite_$stamp.sqlite"
    Copy-Item $dbPath $out
    Write-Host "Backup SQLite selesai: $out"
    exit 0
}

# Cari mysqldump: PATH -> Laragon bawaan
$mysqldump = (Get-Command mysqldump -ErrorAction SilentlyContinue)
if (-not $mysqldump) {
    $candidate = Get-ChildItem "C:\laragon\bin\mysql\*\bin\mysqldump.exe" -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($candidate) { $mysqldumpPath = $candidate.FullName } else { throw "mysqldump tidak ditemukan di PATH/Laragon" }
} else { $mysqldumpPath = $mysqldump.Source }

$plain = Join-Path $Destination "agungatk_$stamp.sql"

# Redirect native via cmd agar byte hasil mysqldump TIDAK melewati encoding PowerShell
$env:MYSQL_PWD = $dbPass
$dumpCmd = "& `"$mysqldumpPath`" -h $dbHost -P $dbPort -u $dbUser --single-transaction --routines --triggers `"$dbName`" > `"$plain`""
cmd /c $dumpCmd
if ($LASTEXITCODE -ne 0) { throw "mysqldump gagal (exit $LASTEXITCODE)" }
if (-not (Test-Path $plain)) { throw "File backup tidak terhasilkan: $plain" }

# Kompres gzip (butuh gzip di PATH — opsional; file .sql tetap valid tanpa kompresi)
$gz = "$plain.gz"
try {
    & gzip -f $plain 2>$null
    if (Test-Path $gz) { Write-Host "Backup database selesai: $gz" }
    else { Write-Host "Backup database selesai (tanpa kompresi): $plain" }
} catch {
    Write-Host "Backup database selesai (gzip tidak tersedia): $plain"
}

Write-Host "Catatan: pindahkan file backup ke lokasi terpisah dari server ini."
