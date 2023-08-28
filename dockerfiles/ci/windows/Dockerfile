ARG vsVersion
FROM datadog/dd-trace-ci:windows-$vsVersion AS base

ARG phpTarGzUrl
ARG phpVersion
ENV PHP_VERSION=${phpVersion}

ADD ${phpTarGzUrl} /php-sdk/php-src.tar.gz
RUN C:\php-sdk\msys2\usr\bin\tar.exe xzf php-src.tar.gz
RUN move php-%PHP_VERSION% php-src

ADD setup_deps.bat /php-sdk/setup_deps.bat

# We want to persist the building env because everything we'll ever do in this container needs it
ADD store-env.bat /tmp/store-env.bat
ARG vsVersion
RUN phpsdk-%vsVersion%-x64.bat -t /tmp/store-env.bat

RUN setup_deps.bat

ADD build_php.bat /php-sdk/build_php.bat
