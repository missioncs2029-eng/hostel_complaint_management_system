@echo off
setlocal EnableExtensions
cd /d "%~dp0"
title HavenHostel - One Click Launcher
color 0B
echo.
echo  =====================================================
echo       HAVENHOSTEL  ^|  HOSTEL MANAGEMENT SYSTEM
echo  =====================================================
echo.

rem ---- Find XAMPP automatically (C:\xampp, D:\xampp, D:\xamapp, saved path, ...) ----
set "XAMPP="
if exist "%~dp0xampp-path.txt" set /p XAMPP=<"%~dp0xampp-path.txt"
if defined XAMPP if not exist "%XAMPP%\xampp-control.exe" set "XAMPP="
if not defined XAMPP for %%D in (C D E F G H) do for %%N in (xampp xamapp XAMPP Xampp) do if not defined XAMPP if exist "%%D:\%%N\xampp-control.exe" set "XAMPP=%%D:\%%N"
if not defined XAMPP (
  echo [!] XAMPP was not found automatically.
  set /p XAMPP=Type your XAMPP folder path [example D:\xampp]: 
)
if not exist "%XAMPP%\xampp-control.exe" (
  echo [ERROR] Could not find xampp-control.exe in "%XAMPP%". Install XAMPP or type the correct folder.
  pause
  exit /b 1
)
>"%~dp0xampp-path.txt" echo %XAMPP%
echo Using XAMPP at: %XAMPP%
echo.

set "DEST=%XAMPP%\htdocs\hostel-management-system"
for %%I in ("%CD%") do set "HERE=%%~fI"
if /I not "%HERE%"=="%DEST%" (
  echo [1/4] Installing project files into XAMPP htdocs...
  if not exist "%XAMPP%\htdocs" mkdir "%XAMPP%\htdocs"
  if not exist "%DEST%" mkdir "%DEST%"
  robocopy "%HERE%" "%DEST%" /E /XD .git /XF *.zip >nul
  if errorlevel 8 (
    echo [ERROR] Could not copy project files. Check permissions and retry.
    pause
    exit /b 1
  )
) else echo [1/4] Project is already in the correct XAMPP folder.
echo.

rem ---- Warn early if port 3306 is taken (the usual reason MySQL will not start) ----
netstat -ano | findstr /R /C:":3306 .*LISTENING" >nul
if not errorlevel 1 (
  echo [!] Port 3306 is already in use by another program.
  echo     If MySQL fails to start in XAMPP, close this window and double-click fix-mysql.bat
  echo     ^(it fixes this automatically^). Then run setup.bat again.
  echo.
)

echo [2/4] Opening XAMPP Control Panel...
start "XAMPP Control Panel" "%XAMPP%\xampp-control.exe"
echo       Click Start for Apache and MySQL, then wait for both to turn green.
echo.
echo [3/4] Opening the one-time database installer...
timeout /t 3 /nobreak >nul
start "HavenHostel Setup" "http://localhost/hostel-management-system/setup.php"
echo.
echo [4/4] After setup completes, click Open HavenHostel in your browser.
echo       Next time, use run.bat from the installed project folder.
echo       Any problem? Open http://localhost/hostel-management-system/health.php
echo.
pause
endlocal
