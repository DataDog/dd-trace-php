del /f /q C:\Windows\System32\libcrypto-1_1-x64.dll >NUL 2>NUL
if %errorlevel% neq 0 exit /b 3
del /f /q C:\Windows\System32\libssl-1_1-x64.dll >NUL 2>NUL
if %errorlevel% neq 0 exit /b 3

mkdir deps

for %%F in ("%PHP_VERSION%") do SET BRANCH=%%~nF

cmd /c phpsdk_deps --update --no-backup --branch %BRANCH% -d deps
if %errorlevel% neq 0 exit /b 3

:: curl's config.w32 checks for libzstd.lib, but the zstd dep now ships only the
:: static libzstd_a.lib. Alias it so curl is detected and enabled (otherwise the
:: built PHP has no curl and ~every phpt that uses it fails).
if exist deps\lib\libzstd_a.lib if not exist deps\lib\libzstd.lib copy /y deps\lib\libzstd_a.lib deps\lib\libzstd.lib