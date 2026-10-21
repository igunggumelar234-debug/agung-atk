@echo off
REM Hentikan keep-alive + layanan Agung ATK (port 8090 & 8899)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop-agungatk.ps1"
pause
