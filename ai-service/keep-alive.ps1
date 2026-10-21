# keep-alive.ps1 - jaga layanan Agung ATK tetap hidup tanpa perintah manual.
#
# Dipanggil otomatis saat login Windows melalui scheduled task
# "AgungATK-KeepAlive" (dibuat install-autostart.ps1; VBS tidak dipakai karena
# Windows Script Host dinonaktifkan di mesin ini).
# Idempotent: aman bila berjalan lebih dari sekali.
# Loop 10 detik; bila port 8090 (layanan visi YOLO), 8899 (Laravel dev), atau
# 3306 (MySQL Laragon) kosong DAN tidak ada proses yang sedang memulainya /
# masih loading, prosesnya dinyalakan kembali.
# Penting: SELALU cek proses yang sudah berjalan sebelum start - start buta
# menyebabkan beberapa proses memuat model bersamaan (OOM di RAM kecil).
# Log aksi: storage/logs/keep-alive.log.
#
# Berhenti sementara : ai-service/stop-agungatk.bat  (nyala lagi saat login)
# Nonaktif permanen : schtasks /Delete /TN AgungATK-KeepAlive /F
#                     lalu jalankan stop-agungatk.bat
$ErrorActionPreference = 'SilentlyContinue'

$root = Split-Path -Parent $PSScriptRoot   # .../agungatkopencode
$log  = Join-Path $root 'storage\logs\keep-alive.log'

$env:PYTHONIOENCODING = 'utf-8'
$env:PYTHONUTF8       = '1'

New-Item -Path (Split-Path -Parent $log) -ItemType Directory -Force | Out-Null

function Write-Log([string]$msg) {
    Add-Content -Path $log -Value ('{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg) -Encoding utf8
}

function Listening([int]$port) {
    return [bool](Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
}

function Process-Running([string]$like) {
    return [bool](Get-CimInstance Win32_Process -Filter "Name='python.exe' or Name='php.exe'" |
        Where-Object { $_.CommandLine -like $like })
}

function Find-Php {
    $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $hit = Get-ChildItem 'C:\laragon\bin\php\php-*\php.exe' -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | Select-Object -First 1
    if ($hit) { return $hit.FullName }
    return $null
}

Write-Log 'keep-alive dimulai'
$lastNote = @{}

while ($true) {
    try {
        # 0) MySQL Laragon - tanpa ini aplikasi error database saat login awal
        if (-not (Listening 3306) -and -not (Get-Process laragon -ErrorAction SilentlyContinue)) {
            if (Test-Path 'C:\Laragon\laragon.exe') {
                Start-Process -FilePath 'C:\Laragon\laragon.exe' -WindowStyle Hidden
                Write-Log 'port 3306 kosong & Laragon tak berjalan -> laragon.exe dijalankan'
            }
        }

        # 1) Layanan visi YOLO (FastAPI) - port 8090.
        #    Jangan di-start bila proses server.py sudah ada (masih loading model).
        if (-not (Listening 8090) -and -not (Process-Running '*ai-service*server.py*')) {
            Start-Process -FilePath 'python' -ArgumentList 'ai-service/server.py' `
                -WorkingDirectory $root -WindowStyle Hidden
            if (-not $lastNote['8090'] -or ((Get-Date) - $lastNote['8090']).TotalSeconds -ge 60) {
                Write-Log 'port 8090 kosong -> ai-service/server.py dijalankan'
                $lastNote['8090'] = Get-Date
            }
        }

        # 2) Server Laravel dev - port 8899 (guard proses serupa: hindari dua
        #    'artisan serve' berebut port).
        if (-not (Listening 8899) -and -not (Process-Running '*artisan*serve*')) {
            $php = Find-Php
            if ($php) {
                Start-Process -FilePath $php -ArgumentList 'artisan', 'serve', '--port=8899' `
                    -WorkingDirectory $root -WindowStyle Hidden
                if (-not $lastNote['8899'] -or ((Get-Date) - $lastNote['8899']).TotalSeconds -ge 60) {
                    Write-Log 'port 8899 kosong -> php artisan serve dijalankan'
                    $lastNote['8899'] = Get-Date
                }
            } elseif (-not $lastNote['nophp']) {
                Write-Log 'php.exe tidak ditemukan - artisan serve dilewati'
                $lastNote['nophp'] = Get-Date
            }
        }
    } catch {
        Write-Log ('ERROR loop: ' + $_.Exception.Message)
    }

    Start-Sleep -Seconds 10
}
