@echo off
setlocal EnableExtensions
cd /d "%~dp0"
title HavenHostel - Run
set "XAMPP="
if exist "%~dp0xampp-path.txt" set /p XAMPP=<"%~dp0xampp-path.txt"
if defined XAMPP if not exist "%XAMPP%\xampp-control.exe" set "XAMPP="
if not defined XAMPP for %%D in (C D E F G H) do for %%N in (xampp xamapp XAMPP Xampp) do if not defined XAMPP if exist "%%D:\%%N\xampp-control.exe" set "XAMPP=%%D:\%%N"
if not defined XAMPP (
  echo XAMPP not found. Run setup.bat first.
  pause
  exit /b 1
)
if not exist "%XAMPP%\htdocs\hostel-management-system\index.php" (
  echo Project not installed in XAMPP htdocs yet. Run setup.bat first.
  pause
  exit /b 1
)
start "XAMPP Control Panel" "%XAMPP%\xampp-control.exe"
timeout /t 2 /nobreak >nul
start "HavenHostel" "http://localhost/hostel-management-system/"
echo If the website does not load, start Apache and MySQL in XAMPP.
echo If MySQL will not start ("Port 3306 in use"), double-click fix-mysql.bat.
pause
endlocal
