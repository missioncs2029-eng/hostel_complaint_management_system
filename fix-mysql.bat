@echo off
setlocal EnableExtensions
cd /d "%~dp0"
title HavenHostel - Fix MySQL (port 3306 in use / MySQL shutdown unexpectedly)
net session >nul 2>&1
if errorlevel 1 (
  echo Asking for Administrator permission - click YES on the Windows prompt...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\fix-mysql.ps1"
echo.
pause
endlocal
