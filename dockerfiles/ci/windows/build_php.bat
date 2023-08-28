cd php-src

cmd /c buildconf.bat --force
if %errorlevel% neq 0 exit /b 3

if "%THREAD_SAFE%" equ "0" set ADD_CONF=%ADD_CONF% --disable-zts

cmd /c configure.bat ^
	--enable-snapshot-build ^
	--enable-com-dotnet=shared ^
	--disable-debug-pack ^
	--without-analyzer ^
	--enable-object-out-dir=%PHP_BUILD_OBJ_DIR% ^
	--with-php-build=..\deps ^
	%ADD_CONF% ^
	--disable-test-ini
if %errorlevel% neq 0 exit /b 3

nmake /NOLOGO
if %errorlevel% neq 0 exit /b 3

nmake install /NOLOGO
if %errorlevel% neq 0 exit /b 3

:: Mirror newer php versions here to simplify php.ini
move C:\php\ext\php_gd2.dll C:\php\ext\php_gd.dll

exit /b 0