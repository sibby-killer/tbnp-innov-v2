@echo off
echo Creating Courier Management System Directory Structure...
echo.

REM ============================================
REM Root directories
REM ============================================
mkdir logs 2>nul
mkdir uploads 2>nul
mkdir backups 2>nul
mkdir cache 2>nul
mkdir temp 2>nul
mkdir ai 2>nul
mkdir storage 2>nul
mkdir tests 2>nul
mkdir docs 2>nul

REM ============================================
REM Logs structure
REM ============================================
mkdir logs\dev 2>nul
mkdir logs\prod 2>nul
echo. > logs\.gitkeep
echo. > logs\dev\.gitkeep
echo. > logs\prod\.gitkeep

REM ============================================
REM Uploads complete structure
REM ============================================
mkdir uploads\images\original 2>nul
mkdir uploads\images\thumbnails 2>nul
mkdir uploads\images\resized 2>nul
mkdir uploads\images\temp 2>nul
mkdir uploads\documents 2>nul
mkdir uploads\temp 2>nul
mkdir uploads\users 2>nul
mkdir uploads\drivers 2>nul
mkdir uploads\trucks 2>nul
mkdir uploads\clients 2>nul

REM Drivers subfolders
mkdir uploads\drivers\licenses 2>nul
mkdir uploads\drivers\insurance 2>nul
mkdir uploads\drivers\photos 2>nul

REM Trucks subfolders
mkdir uploads\trucks\insurance 2>nul
mkdir uploads\trucks\registration 2>nul
mkdir uploads\trucks\photos 2>nul

REM Clients subfolders
mkdir uploads\clients\contracts 2>nul
mkdir uploads\clients\invoices 2>nul

REM Uploads .gitkeep files
echo. > uploads\.gitkeep
echo. > uploads\images\.gitkeep
echo. > uploads\images\original\.gitkeep
echo. > uploads\images\thumbnails\.gitkeep
echo. > uploads\images\resized\.gitkeep
echo. > uploads\images\temp\.gitkeep
echo. > uploads\documents\.gitkeep
echo. > uploads\temp\.gitkeep
echo. > uploads\users\.gitkeep
echo. > uploads\drivers\.gitkeep
echo. > uploads\drivers\licenses\.gitkeep
echo. > uploads\drivers\insurance\.gitkeep
echo. > uploads\drivers\photos\.gitkeep
echo. > uploads\trucks\.gitkeep
echo. > uploads\trucks\insurance\.gitkeep
echo. > uploads\trucks\registration\.gitkeep
echo. > uploads\trucks\photos\.gitkeep
echo. > uploads\clients\.gitkeep
echo. > uploads\clients\contracts\.gitkeep
echo. > uploads\clients\invoices\.gitkeep

REM ============================================
REM Backups complete structure
REM ============================================
mkdir backups\database\daily 2>nul
mkdir backups\database\weekly 2>nul
mkdir backups\database\monthly 2>nul
mkdir backups\files\daily 2>nul
mkdir backups\files\weekly 2>nul
mkdir backups\files\monthly 2>nul
mkdir backups\config 2>nul
mkdir backups\system\full 2>nul
mkdir backups\system\incremental 2>nul
mkdir backups\archives 2>nul

REM Backups .gitkeep files
echo. > backups\.gitkeep
echo. > backups\database\.gitkeep
echo. > backups\database\daily\.gitkeep
echo. > backups\database\weekly\.gitkeep
echo. > backups\database\monthly\.gitkeep
echo. > backups\files\.gitkeep
echo. > backups\files\daily\.gitkeep
echo. > backups\files\weekly\.gitkeep
echo. > backups\files\monthly\.gitkeep
echo. > backups\config\.gitkeep
echo. > backups\system\.gitkeep
echo. > backups\system\full\.gitkeep
echo. > backups\system\incremental\.gitkeep
echo. > backups\archives\.gitkeep

REM ============================================
REM Cache structure
REM ============================================
mkdir cache\data 2>nul
mkdir cache\views 2>nul
mkdir cache\routes 2>nul
mkdir cache\config 2>nul

echo. > cache\.gitkeep
echo. > cache\data\.gitkeep
echo. > cache\views\.gitkeep
echo. > cache\routes\.gitkeep
echo. > cache\config\.gitkeep

REM ============================================
REM Temp structure
REM ============================================
echo. > temp\.gitkeep

REM ============================================
REM AI structure
REM ============================================
mkdir ai\models\tensorflow 2>nul
mkdir ai\models\pytorch 2>nul
mkdir ai\training 2>nul
mkdir ai\predictions 2>nul
mkdir ai\data 2>nul
mkdir ai\datasets 2>nul
mkdir ai\checkpoints 2>nul

echo. > ai\.gitkeep
echo. > ai\models\.gitkeep
echo. > ai\models\tensorflow\.gitkeep
echo. > ai\models\pytorch\.gitkeep
echo. > ai\training\.gitkeep
echo. > ai\predictions\.gitkeep
echo. > ai\data\.gitkeep
echo. > ai\datasets\.gitkeep
echo. > ai\checkpoints\.gitkeep

REM ============================================
REM Storage structure
REM ============================================
mkdir storage\logs 2>nul
mkdir storage\framework\cache 2>nul
mkdir storage\framework\sessions 2>nul
mkdir storage\framework\views 2>nul
mkdir storage\app 2>nul

echo. > storage\.gitkeep
echo. > storage\logs\.gitkeep
echo. > storage\framework\.gitkeep
echo. > storage\framework\cache\.gitkeep
echo. > storage\framework\sessions\.gitkeep
echo. > storage\framework\views\.gitkeep
echo. > storage\app\.gitkeep

REM ============================================
REM Tests structure
REM ============================================
mkdir tests\_output 2>nul
mkdir tests\_support\_generated 2>nul
mkdir tests\_data 2>nul

echo. > tests\.gitkeep
echo. > tests\_output\.gitkeep
echo. > tests\_support\.gitkeep
echo. > tests\_support\_generated\.gitkeep
echo. > tests\_data\.gitkeep

REM ============================================
REM Docs structure
REM ============================================
mkdir docs\api 2>nul
mkdir docs\generated 2>nul

echo. > docs\.gitkeep
echo. > docs\api\.gitkeep
echo. > docs\generated\.gitkeep

REM ============================================
REM Core structure
REM ============================================
mkdir core\security\exceptions 2>nul
mkdir core\scripts 2>nul

echo. > core\.gitkeep
echo. > core\security\.gitkeep
echo. > core\security\exceptions\.gitkeep
echo. > core\scripts\.gitkeep

REM ============================================
REM Config structure
REM ============================================
echo. > config\.gitkeep

REM ============================================
REM Database structure
REM ============================================
mkdir database\exports 2>nul
mkdir database\seeds 2>nul
mkdir database\dumps 2>nul
mkdir database\migrations 2>nul

echo. > database\.gitkeep
echo. > database\exports\.gitkeep
echo. > database\seeds\.gitkeep
echo. > database\dumps\.gitkeep
echo. > database\migrations\.gitkeep

REM ============================================
REM Bootstrap structure
REM ============================================
mkdir bootstrap\cache 2>nul

echo. > bootstrap\.gitkeep
echo. > bootstrap\cache\.gitkeep

echo.
echo ✅ Directory structure created successfully!
echo.
echo 📁 Folders created:
echo    - logs/
echo    - uploads/ (with complete subfolders)
echo    - backups/ (with daily/weekly/monthly)
echo    - cache/
echo    - temp/
echo    - ai/ (with models, training, predictions)
echo    - storage/
echo    - tests/
echo    - docs/
echo    - core/security/
echo    - config/
echo    - database/
echo    - bootstrap/
echo.
echo 🔒 All folders have .gitkeep files to maintain structure in git
echo.
pause