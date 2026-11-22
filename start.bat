@echo off
rem Encounter Manager: double-click on Windows.
rem Starts a private web server on this computer and opens the app in your browser.
cd /d "%~dp0"
set PORT=8765
where php >nul 2>nul
if errorlevel 1 (
  echo PHP isn't installed or isn't on your PATH.
  echo Install it with:  winget install PHP.PHP.8.3
  echo or download it from https://windows.php.net/download/  ^(see README.md^)
  pause
  exit /b 1
)
php -r "exit(PHP_VERSION_ID >= 80100 && extension_loaded('pdo_sqlite') && extension_loaded('curl') ? 0 : 1);"
if errorlevel 1 (
  echo PHP needs to be 8.1 or newer with the pdo_sqlite and curl extensions turned on in php.ini.
  echo See README.md for how.
  pause
  exit /b 1
)
echo Encounter Manager is running at http://localhost:%PORT%/
echo Leave this window open while you play. Close it to stop.
start "" http://localhost:%PORT%/
php -S 127.0.0.1:%PORT% router.php
