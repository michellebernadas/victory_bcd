@echo off
REM ============================================================================
REM  VICTORY BACOLOD - 2026 FOUNDATION RESET RUNNER (Windows / XAMPP)
REM
REM  Windows equivalent of reset_2026.sh, for admins who do not have Git Bash.
REM  Same guarantees: backup first, verify it, confirm, then reset.
REM
REM  USAGE
REM    reset_2026.bat                 - DRY RUN. Backup + report only.
REM    reset_2026.bat LIVE            - Performs the reset after confirmation.
REM    reset_2026.bat LIVE mydbname   - Target a different database.
REM
REM  ALWAYS run the dry run first, and test against a COPY before production.
REM ============================================================================

setlocal enabledelayedexpansion

set "DB_NAME=victory_bacolod"
set "DB_USER=root"
set "MODE=DRYRUN"

if /i "%~1"=="LIVE" set "MODE=LIVE"
if not "%~2"=="" set "DB_NAME=%~2"

set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
set "MYSQLDUMP=C:\xampp\mysql\bin\mysqldump.exe"
set "SCRIPT_DIR=%~dp0"
set "RESET_SQL=%SCRIPT_DIR%2026_reset_foundation.sql"
REM Backups are full dumps of member personal data: keep them OUTSIDE the web
REM root and the git repository. The app's parent folder is still inside the
REM website, so default to the user's profile folder. Override with VB_BACKUP_DIR.
if defined VB_BACKUP_DIR (set "BACKUP_DIR=%VB_BACKUP_DIR%") else (set "BACKUP_DIR=%USERPROFILE%\victory_bacolod_backups")

echo ------------------------------------------------------------
echo   VICTORY BACOLOD - 2026 FOUNDATION RESET
echo   Database : %DB_NAME%
if "%MODE%"=="LIVE" (
    echo   Mode     : *** LIVE - DATA WILL BE DELETED ***
) else (
    echo   Mode     : DRY RUN ^(nothing will be deleted^)
)
echo ------------------------------------------------------------
echo.

if not exist "%MYSQL%" (
    echo ABORTED: mysql.exe not found at %MYSQL%
    exit /b 1
)
if not exist "%RESET_SQL%" (
    echo ABORTED: reset SQL not found at %RESET_SQL%
    exit /b 1
)

"%MYSQL%" -u%DB_USER% -e "USE `%DB_NAME%`;" >nul 2>&1
if errorlevel 1 (
    echo ABORTED: cannot connect to '%DB_NAME%' as '%DB_USER%'.
    echo          Check that MySQL is running in the XAMPP Control Panel.
    exit /b 1
)

echo Rows that WILL BE CLEARED:
"%MYSQL%" -u%DB_USER% %DB_NAME% --table -e "SELECT 'members' AS table_name, COUNT(*) AS rows_to_delete FROM members UNION ALL SELECT 'member_discipleship', COUNT(*) FROM member_discipleship UNION ALL SELECT 'program_attendances', COUNT(*) FROM program_attendances UNION ALL SELECT 'vg_members', COUNT(*) FROM vg_members UNION ALL SELECT 'victory_groups', COUNT(*) FROM victory_groups UNION ALL SELECT 'serve_team_members', COUNT(*) FROM serve_team_members UNION ALL SELECT 'serve_teams', COUNT(*) FROM serve_teams UNION ALL SELECT 'historical_completion_reviews', COUNT(*) FROM historical_completion_reviews;"

echo.
echo Rows that WILL BE KEPT:
"%MYSQL%" -u%DB_USER% %DB_NAME% --table -e "SELECT 'accounts (LOGINS)' AS table_name, COUNT(*) AS rows_kept FROM accounts UNION ALL SELECT 'discipleship_steps', COUNT(*) FROM discipleship_steps UNION ALL SELECT 'ministries', COUNT(*) FROM ministries UNION ALL SELECT 'services', COUNT(*) FROM services UNION ALL SELECT 'sf_topics (CURRICULUM)', COUNT(*) FROM sf_topics UNION ALL SELECT 'serve_options', COUNT(*) FROM serve_options UNION ALL SELECT 'vg_options', COUNT(*) FROM vg_options;"

REM ---- Backup (taken in BOTH modes) ----
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set "DT=%%I"
set "STAMP=!DT:~0,8!_!DT:~8,6!"
set "BACKUP_FILE=%BACKUP_DIR%\%DB_NAME%_pre_reset_!STAMP!.sql"

echo.
echo Taking backup -^> !BACKUP_FILE!
"%MYSQLDUMP%" -u%DB_USER% --single-transaction --routines --events --triggers --add-drop-table --complete-insert %DB_NAME% > "!BACKUP_FILE!" 2>nul
if errorlevel 1 (
    echo ABORTED: mysqldump failed. Nothing was deleted.
    exit /b 1
)

REM Verify the backup is real, not truncated.
for %%A in ("!BACKUP_FILE!") do set "BACKUP_BYTES=%%~zA"
if !BACKUP_BYTES! LSS 10240 (
    echo ABORTED: backup is only !BACKUP_BYTES! bytes - implausibly small. Nothing was deleted.
    exit /b 1
)
findstr /C:"Dump completed" "!BACKUP_FILE!" >nul
if errorlevel 1 (
    echo ABORTED: backup has no completion marker - it is truncated. Nothing was deleted.
    exit /b 1
)
echo Backup verified: !BACKUP_BYTES! bytes, structurally complete.

if "%MODE%"=="DRYRUN" (
    echo.
    echo ------------------------------------------------------------
    echo DRY RUN COMPLETE - the database was NOT modified.
    echo A verified backup was still taken, at:
    echo   !BACKUP_FILE!
    echo.
    echo To restore from it at any time:
    echo   "%MYSQL%" -u%DB_USER% %DB_NAME% ^< "!BACKUP_FILE!"
    echo.
    echo When you are ready, run:  reset_2026.bat LIVE
    echo ------------------------------------------------------------
    exit /b 0
)

echo.
echo ------------------------------------------------------------
echo   THIS WILL PERMANENTLY DELETE THE 2023 OPERATIONAL DATA ABOVE.
echo   Admin logins and all configuration will be preserved.
echo   Backup: !BACKUP_FILE!
echo ------------------------------------------------------------
echo.
set "CONFIRM="
set /p "CONFIRM=Type exactly  RESET 2026  to proceed (anything else aborts): "
if not "!CONFIRM!"=="RESET 2026" (
    echo ABORTED: confirmation phrase not matched. Nothing was deleted.
    exit /b 1
)

echo.
echo Running reset...
"%MYSQL%" -u%DB_USER% %DB_NAME% --table < "%RESET_SQL%"
if errorlevel 1 (
    echo.
    echo ABORTED: reset FAILED. The SQL ran in a transaction, so the database
    echo          should be unchanged. Restore if needed:
    echo            "%MYSQL%" -u%DB_USER% %DB_NAME% ^< "!BACKUP_FILE!"
    exit /b 1
)

for /f %%R in ('"%MYSQL%" -u%DB_USER% %DB_NAME% -N -B -e "SELECT (SELECT COUNT(*) FROM members)+(SELECT COUNT(*) FROM member_discipleship)+(SELECT COUNT(*) FROM program_attendances)+(SELECT COUNT(*) FROM vg_members)+(SELECT COUNT(*) FROM victory_groups)+(SELECT COUNT(*) FROM serve_team_members)+(SELECT COUNT(*) FROM serve_teams)+(SELECT COUNT(*) FROM historical_completion_reviews);"') do set "REMAINING=%%R"
for /f %%A in ('"%MYSQL%" -u%DB_USER% %DB_NAME% -N -B -e "SELECT COUNT(*) FROM accounts;"') do set "ACCOUNTS=%%A"

echo.
echo ------------------------------------------------------------
if "!REMAINING!"=="0" (
    echo RESET COMPLETE.
    echo   Operational rows remaining : 0
    echo   Admin accounts preserved   : !ACCOUNTS!
    echo   Backup retained at         : !BACKUP_FILE!
    echo.
    echo NEXT: the church can now encode their 2026 members and discipleship data.
    echo Keep the backup file somewhere safe and OUTSIDE the web root.
) else (
    echo POST-CHECK FAILED.
    echo   Operational rows remaining : !REMAINING!  ^(expected 0^)
    echo.
    echo Restore immediately with:
    echo   "%MYSQL%" -u%DB_USER% %DB_NAME% ^< "!BACKUP_FILE!"
    echo ------------------------------------------------------------
    exit /b 1
)
echo ------------------------------------------------------------
endlocal
