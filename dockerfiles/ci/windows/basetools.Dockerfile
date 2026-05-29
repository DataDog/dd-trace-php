ARG vsVersion
FROM datadog/dd-trace-ci:windows-base-$vsVersion

RUN powershell.exe "Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; $Env:chocolateyVersion = '0.10.15'; $Env:chocolateyUseWindowsCompression = 'false'; iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1')); ''"

# I really need some sane file editing utilities
RUN powershell "Invoke-WebRequest https://ftp.nluug.nl/pub/vim/pc/vim90w32.zip -OutFile /tmp/vim90w32.zip; Expand-Archive /tmp/vim90w32.zip /tmp; move C:\tmp\vim\vim90\tee.exe C:\Windows\tee.exe; move C:\tmp\vim\vim90\vim.exe C:\Windows\vim.exe; move C:\tmp\vim\vim90\xxd.exe C:\Windows\xxd.exe; Remove-Item /tmp/vim90w32.zip; Remove-Item -Recurse C:\tmp\vim"

RUN powershell "Invoke-WebRequest https://static.rust-lang.org/rustup/dist/x86_64-pc-windows-msvc/rustup-init.exe -OutFile /tmp/rustup-init.exe; cmd /S /C /tmp/rustup-init.exe --profile minimal -y --default-toolchain=1.87.0; Remove-Item /tmp/rustup-init.exe"

RUN choco install -y cmake
RUN choco install -y nasm
RUN choco install -y llvm

RUN powershell "[Environment]::SetEnvironmentVariable('PATH', $env:PATH + ';C:\Program Files\NASM;C:\Program Files\CMake\bin', 'Machine')"

# initial setup

ARG sdkVersion
RUN powershell "cd /tmp; Invoke-WebRequest https://github.com/php/php-sdk-binary-tools/archive/refs/tags/php-sdk-%sdkVersion%.zip -OutFile php-sdk.zip; Expand-Archive php-sdk.zip; move php-sdk\php-sdk-binary-tools-php-sdk-%sdkVersion% /php-sdk; Remove-Item php-sdk; Remove-Item php-sdk.zip"
# Older PHP SDK tags expect Apache indexes to prefix package links with /.
RUN powershell "$config = 'C:\php-sdk\lib\php\libsdk\SDK\Config.php'; $text = [IO.File]::ReadAllText($config).Replace(',/packages-', ',>packages-'); if ($text.Contains(',/packages-')) { throw 'Failed to patch PHP SDK dependency series regex' }; [IO.File]::WriteAllText($config, $text, [System.Text.Encoding]::ASCII)"
# The PHP downloads CDN rejects the SDK's user-agent for some reason.
RUN powershell "$fileOps = 'C:\php-sdk\lib\php\libsdk\SDK\FileOps.php'; $text = [IO.File]::ReadAllText($fileOps); $text = $text -replace '(?m)^\s*curl_setopt\(\$ch, CURLOPT_USERAGENT, Config::getSdkUserAgentName\(\)\);\r?\n?', ''; if ($text.Contains('CURLOPT_USERAGENT')) { throw 'Failed to remove PHP SDK curl user-agent' }; [IO.File]::WriteAllText($fileOps, $text, [System.Text.Encoding]::ASCII)"

WORKDIR /php-sdk
