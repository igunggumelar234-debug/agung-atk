# install-autostart.ps1 - pasang auto-start keep-alive Agung ATK di Windows.
#
# Membuat scheduled task "AgungATK-KeepAlive" (trigger logon, mode interactive,
# tanpa hak admin) yang menjalankan keep-alive.ps1 tersembunyi setiap login.
# Mesin ini menonaktifkan Windows Script Host (HKCU ...WSH\Settings\Enabled=0)
# sehingga .vbs di folder Startup tidak berfungsi - Task Scheduler tidak
# terpengaruh oleh itu. Bila pembuatan task ditolak, fallback otomatis ke
# shortcut .lnk di folder Startup. Idempotent: boleh dijalankan berulang.
#
# Pakai : powershell -ExecutionPolicy Bypass -File ai-service\install-autostart.ps1
# Hapus : schtasks /Delete /TN AgungATK-KeepAlive /F
$ErrorActionPreference = 'Stop'

$ps1     = Join-Path $PSScriptRoot 'keep-alive.ps1'
$root    = Split-Path -Parent $PSScriptRoot
$argStr  = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $ps1 + '"'
$mode    = $null

try {
    $svc = New-Object -ComObject Schedule.Service
    $svc.Connect()
    $def = $svc.NewTask(0)
    $def.RegistrationInfo.Description = 'Jaga layanan Agung ATK (port 8090/8899/3306) tetap hidup - ai-service/keep-alive.ps1'
    $trig = $def.Triggers.Create(9)   # 9 = TASK_TRIGGER_LOGON
    $trig.UserId = ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name)
    $def.Settings.Hidden = $true
    $def.Settings.StopIfGoingOnBatteries = $false
    $def.Settings.DisallowStartIfOnBatteries = $false
    $def.Settings.ExecutionTimeLimit = 'PT0S'   # tanpa batas waktu eksekusi
    $act = $def.Actions.Create(0)     # 0 = TASK_ACTION_EXEC
    $act.Path = 'powershell.exe'
    $act.Arguments = $argStr
    $act.WorkingDirectory = $root
    $svc.GetFolder('\').RegisterTaskDefinition('AgungATK-KeepAlive', $def, 6, $null, $null, 3, $null) | Out-Null
    $mode = 'task'
} catch {
    Write-Warning ("Pembuatan task gagal ({0}) - memakai shortcut .lnk di folder Startup." -f $_.Exception.Message)
    $wsh = New-Object -ComObject WScript.Shell
    $lnk = Join-Path ([Environment]::GetFolderPath('Startup')) 'AgungATK-KeepAlive.lnk'
    $s = $wsh.CreateShortcut($lnk)
    $s.TargetPath = 'powershell.exe'
    $s.Arguments = $argStr
    $s.WorkingDirectory = $root
    $s.WindowStyle = 7   # minimized
    $s.Description = 'Jaga layanan Agung ATK tetap hidup'
    $s.Save()
    if (-not (Test-Path $lnk)) { throw 'Gagal membuat shortcut .lnk.' }
    $mode = 'lnk'
}

if ($mode -eq 'task') {
    Write-Host 'OK: scheduled task AgungATK-KeepAlive terpasang (trigger logon, tersembunyi).'
} else {
    Write-Host 'OK: shortcut AgungATK-KeepAlive.lnk terpasang di folder Startup.'
}

# Jalankan keep-alive untuk sesi login sekarang bila belum berjalan.
$running = @(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -like '*keep-alive.ps1*' })
if ($running.Count -gt 0) {
    Write-Host 'keep-alive sudah berjalan - tidak dijalankan ulang.'
} elseif ($mode -eq 'task') {
    schtasks /Run /TN 'AgungATK-KeepAlive' | Out-Null
    Write-Host 'keep-alive dijalankan sekarang juga (schtasks /Run).'
} else {
    Start-Process -FilePath 'powershell.exe' -ArgumentList $argStr -WindowStyle Hidden
    Write-Host 'keep-alive dijalankan sekarang juga.'
}
