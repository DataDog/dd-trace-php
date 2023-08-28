del /f /q C:\Windows\System32\libcrypto-1_1-x64.dll >NUL 2>NUL
if %errorlevel% neq 0 exit /b 3
del /f /q C:\Windows\System32\libssl-1_1-x64.dll >NUL 2>NUL
if %errorlevel% neq 0 exit /b 3

mkdir deps

for %%F in ("%PHP_VERSION%") do SET BRANCH=%%~nF

cmd /c phpsdk_deps --update --no-backup --branch %BRANCH% -d deps
if %errorlevel% neq 0 exit /b 3