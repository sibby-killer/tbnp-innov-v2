@echo off
title Courier System Backup with 7-Zip
echo ========================================
echo Courier Management System Backup
echo ========================================
echo.

REM Set paths
set "PROJECT_PATH=C:\laragon\www\courier-system"
set "BACKUP_PATH=%PROJECT_PATH%\backups\archives"
set "DATE=%date:~10,4%%date:~4,2%%date:~7,2%_%time:~0,2%%time:~3,2%%time:~6,2%"
set "DATE=%DATE: =0%"
set "BACKUP_NAME=courier-backup-%DATE%"

REM Check if 7-Zip exists
if not exist "C:\Program Files\7-Zip\7z.exe" (
    echo [ERROR] 7-Zip not found at C:\Program Files\7-Zip\7z.exe
    echo Please install 7-Zip from: https://www.7-zip.org/
    pause
    exit /b 1
)

echo [1/6] Creating backup directory...
if not exist "%BACKUP_PATH%" mkdir "%BACKUP_PATH%"
echo     ✓ Backup directory: %BACKUP_PATH%

echo [2/6] Creating database backup...
if exist "%PROJECT_PATH%\backups\database" (
    "C:\Program Files\7-Zip\7z.exe" a -tzip "%BACKUP_PATH%\%BACKUP_NAME%-database.zip" "%PROJECT_PATH%\backups\database\*" -mx5 >nul
    echo     ✓ Database backup created
) else (
    echo     ⚠ No database backup folder found
)

echo [3/6] Creating uploads backup...
if exist "%PROJECT_PATH%\uploads" (
    "C:\Program Files\7-Zip\7z.exe" a -t7z "%BACKUP_PATH%\%BACKUP_NAME%-uploads.7z" "%PROJECT_PATH%\uploads\*" -mx9 -m0=LZMA2 -ms=on -mmt=on >nul
    echo     ✓ Uploads backup created (compressed with 7-Zip)
) else (
    echo     ⚠ No uploads folder found
)

echo [4/6] Creating logs backup...
if exist "%PROJECT_PATH%\logs" (
    "C:\Program Files\7-Zip\7z.exe" a -tzip "%BACKUP_PATH%\%BACKUP_NAME%-logs.zip" "%PROJECT_PATH%\logs\*" -mx5 >nul
    echo     ✓ Logs backup created
) else (
    echo     ⚠ No logs folder found
)

echo [5/6] Creating full project backup...
"C:\Program Files\7-Zip\7z.exe" a -t7z "%BACKUP_PATH%\%BACKUP_NAME%-full.7z" "%PROJECT_PATH%" -mx7 -xr!vendor -xr!node_modules -xr!.git -xr!backups -xr!cache -xr!temp -xr!*.log -xr!*.tmp >nul
echo     ✓ Full project backup created (excluding vendor, node_modules, git)

echo [6/6] Creating backup manifest...
(
    echo Backup Date: %DATE%
    echo Project: Courier Management System
    echo Path: %PROJECT_PATH%
    echo Files included:
    echo   - Database backup
    echo   - Uploads folder
    echo   - Logs folder
    echo   - Full project (excluding vendor, node_modules, git)
    echo.
    echo 7-Zip Compression:
    echo   - Uploads: Ultra compression (LZMA2)
    echo   - Full: Normal compression
    echo   - Others: ZIP format
) > "%BACKUP_PATH%\%BACKUP_NAME%-manifest.txt"

echo.
echo ========================================
echo Backup Complete!
echo ========================================
echo.
echo Backup files created:
dir "%BACKUP_PATH%\%BACKUP_NAME%-*.*" /b

echo.
echo Total backup size:
for %%I in ("%BACKUP_PATH%\%BACKUP_NAME%-*.*") do set /a "size+=%%~zI"
set /a "sizeMB=%size%/1024/1024"
echo %sizeMB% MB

echo.
echo ========================================
echo Backup saved to: %BACKUP_PATH%
echo ========================================

pause