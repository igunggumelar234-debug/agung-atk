# stop-agungatk.ps1 - hentikan keep-alive + kedua layanan Agung ATK (8090 & 8899).
# (Sesi ini berhenti; bila scheduled task AgungATK-KeepAlive masih terpasang,
#  layanan akan hidup lagi otomatis saat login berikutnya.)
$ErrorActionPreference = 'SilentlyContinue'

# 1) Matikan proses keep-alive
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -like '*keep-alive.ps1*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force }

# 2) Matikan layanan visi (8090) dan Laravel dev (8899)
foreach ($port in @(8090, 8899)) {
    Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue |
        ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
}

Write-Host 'Layanan Agung ATK (port 8090 & 8899) dihentikan.'
Write-Host 'Catatan: auto-start masih aktif - akan menyala lagi saat login berikutnya.'
Write-Host 'Nonaktif permanen: schtasks /Delete /TN AgungATK-KeepAlive /F'
