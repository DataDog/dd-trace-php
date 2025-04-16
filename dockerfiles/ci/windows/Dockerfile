ARG vsVersion
FROM datadog/dd-trace-ci:windows-$vsVersion AS base

ARG phpTarGzUrl
ARG phpVersion
ENV PHP_VERSION=${phpVersion}

RUN powershell "Invoke-WebRequest %phpTarGzUrl% -OutFile /php-sdk/php-src.tar.gz; tar xzf php-src.tar.gz; move php-%phpVersion% php-src; Remove-Item /php-sdk/php-src.tar.gz"

ADD setup_deps.bat /php-sdk/setup_deps.bat

# We want to persist the building env because everything we'll ever do in this container needs it
ADD store-env.bat /tmp/store-env.bat
ARG vsVersion
RUN phpsdk-%vsVersion%-x64.bat -t /tmp/store-env.bat

RUN setup_deps.bat

ADD build_php.bat /php-sdk/build_php.bat

FROM base AS php-nts
ENV THREAD_SAFE=0
RUN build_php.bat

FROM base AS php-zts
ENV THREAD_SAFE=1
RUN build_php.bat

FROM base as final
COPY --chown=circleci:circleci --from=php-nts /php /php-nts
COPY --chown=circleci:circleci --from=php-zts /php /php-zts

ADD php.ini /php-nts/php.ini
ADD php.ini /php-zts/php.ini

RUN mklink /J "/php" "/php-nts"

RUN powershell "[Environment]::SetEnvironmentVariable('PATH', $env:PATH + ';C:\php;C:\helpers;C:\php-sdk\deps\bin;C:\ProgramData\ComposerSetup\bin;C:\Users\ContainerAdministrator\AppData\Roaming\Composer\vendor\bin', 'Machine')"
RUN reg add HKLM\SOFTWARE\PHP\IniFilePath /v C:\php\php.ini

WORKDIR /Users/ContainerAdministrator

ADD switch-php.bat /helpers/switch-php.bat

ADD composer.bat /ProgramData/ComposerSetup/bin/composer.bat
ADD https://getcomposer.org/download/latest-stable/composer.phar /ProgramData/ComposerSetup/bin/composer.phar
