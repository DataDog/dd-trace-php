ARG vsVersion
FROM datadog/dd-trace-ci:windows-base-$vsVersion

RUN powershell.exe "Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; $Env:chocolateyVersion = '0.10.15'; $Env:chocolateyUseWindowsCompression = 'false'; iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1')); ''"

# I really need some sane file editing utilities
ADD https://ftp.nluug.nl/pub/vim/pc/vim90w32.zip /tmp/vim90w32.zip
RUN powershell.exe Expand-Archive /tmp/vim90w32.zip /tmp
RUN move C:\tmp\vim\vim90\tee.exe C:\Windows\tee.exe
RUN move C:\tmp\vim\vim90\vim.exe C:\Windows\vim.exe
RUN move C:\tmp\vim\vim90\xxd.exe C:\Windows\xxd.exe

ADD https://github.com/git-for-windows/git/releases/download/v2.41.0.windows.3/Git-2.41.0.3-64-bit.exe /tmp/git-setup.exe
RUN /tmp/git-setup.exe /VERYSILENT /NORESTART /NOCANCEL /SP- /CLOSEAPPLICATIONS /RESTARTAPPLICATIONS /COMPONENTS="icons,ext\reg\shellhere,assoc,assoc_sh"

RUN git clone https://github.com/php/php-sdk-binary-tools.git /php-sdk
# prevent permission confusion
RUN git config --global --add safe.directory C:/php-sdk

ADD https://static.rust-lang.org/rustup/dist/x86_64-pc-windows-msvc/rustup-init.exe /tmp/rustup-init.exe
RUN /tmp/rustup-init.exe -y --default-toolchain=1.71.0

RUN choco install -y cmake
RUN choco install -y nasm
RUN choco install -y llvm

RUN powershell "[Environment]::SetEnvironmentVariable('PATH', $env:PATH + ';C:\Program Files\NASM;C:\Program Files\CMake\bin', 'Machine')"

# initial setup

WORKDIR /php-sdk
ARG sdkVersion
RUN git checkout php-sdk-%sdkVersion%