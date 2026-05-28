@echo off

where cl.exe
if %errorlevel% neq 0 exit /b %errorlevel%

where link.exe
if %errorlevel% neq 0 exit /b %errorlevel%

exit /b 0
