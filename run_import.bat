@echo off
cls
title HOA Resident Data Import Utility (Enhanced)

:: --- Configuration ---
SET NAS_PATH=\\nas.fsbhoa.com\shared\AccessControl
SET CSV_FILENAME=ResidentImport.csv
SET API_ENDPOINT=https://access.fsbhoa.com/wp-json/fsbhoa/v1/import/run
SET SERVER_FILE_PATH=/mnt/shared/AccessControl/ResidentImport.csv
SET API_KEY=ArZ5Zvhdq6d6Xtp4eHjHh2iua+/0M8UstTu3FrO9rNY=
:: -------------------

:START
echo =================================================================
echo  FSBHOA Access Control - Resident Data Import Utility
echo =================================================================
echo.

:: Check for a /dryrun flag
SET DRY_RUN_JSON=
SET MODE_TEXT=LIVE RUN (database will be modified)
IF /I "%1" == "/dryrun" (
    SET DRY_RUN_JSON=, "dry_run":true
    SET MODE_TEXT=DRY RUN (no changes will be made)
)

echo  ** MODE: %MODE_TEXT% **
echo.

:: We don't need the status check for the batch file, as the user is actively running it.

:EXPORT_STEP
echo -----------------------------------------------------------------
echo  STEP 1: Export Resident Data
echo -----------------------------------------------------------------
echo.
echo Please save the export file as "%CSV_FILENAME%"
echo to this network location: %NAS_PATH%
echo.
echo Press any key when you have finished saving the file...
pause >nul
echo.

:TRIGGER_STEP
echo -----------------------------------------------------------------
echo  STEP 2: Triggering Import via REST API
echo -----------------------------------------------------------------
echo.
echo Sending secure request to the server... Please wait.
echo.

:: Conditionally build the JSON payload and call the API
curl -X POST ^
   -H "Content-Type: application/json" ^
   -H "X-API-KEY: %API_KEY%" ^
   -d "{\"file_path\": \"%SERVER_FILE_PATH%\"%DRY_RUN_JSON%}" ^
   %API_ENDPOINT%

echo.
echo.
echo -----------------------------------------------------------------
echo  PROCESS COMPLETE
echo -----------------------------------------------------------------
echo.
echo Press any key to close this window.
pause >nul
exit

