@echo off
REM Laravel scheduler - runs every minute via Windows Task Scheduler (task: cdp-hrms-scheduler)
cd /d C:\laragon\www\cdp-hrms-backend
"C:\laragon\bin\php\php-8.3.9-Win32-vs16-x64\php.exe" artisan schedule:run >> "C:\laragon\www\cdp-hrms-backend\storage\logs\scheduler.log" 2>&1
