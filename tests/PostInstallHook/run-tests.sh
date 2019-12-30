#!/bin/bash

IFS=", "
exitCode=0
pwd=$(dirname "$0")

# The version that installed libapache2-mod-php* in the Dockerfile
apachePhpVersion=7.3

phpVersion=$(php "$pwd/bootstrap.php" | grep "  --php-version")
if [ -z "$phpVersion" ]; then
    printf "No supported PHP versions found\n"
    exit 1
fi
phpVersions=${phpVersion#"  --php-version    "}

defaultPhpVersion=$(php-config --version | cut -d . -f -2)

function restartService() {
    supervisorctl restart "$1" > /dev/null 2>&1
    # Apache needs ~~a little~~ a lot of extra time to get its act together after restart
    if [ "apache2" = "$1" ]; then
        sleep 6
    fi
}

function reset() {
    for v in $phpVersions; do
        rm -f /etc/php/"$v"/cli/conf.d/*-ddtrace*.ini
        rm -f /etc/php/"$v"/fpm/conf.d/*-ddtrace*.ini
        restartService "php-fpm$v"
    done
    rm -f /opt/datadog-php/etc/ddtrace*.ini
    restartService "apache2"
}

function showStatus() {
    if [ "$1" -eq 0 ]; then
        printf "[OK]\n"
    else
        printf "[FAIL]\n"
        exitCode=1
    fi
}

function testInstalled() {
    printf "  %s; %s ddtrace installed " "$1" "$2"
    php "$pwd/test-installed.php" --php-version "$1" --sapi "$2"
    showStatus $?
}

function testNotInstalled() {
    printf "  %s; %s ddtrace NOT installed " "$1" "$2"
    php "$pwd/test-not-installed.php" --php-version "$1" --sapi "$2"
    showStatus $?
}

function checkIsInstalledCli() {
    if [ $# -eq 1 ]; then
        bin="/usr/bin/php$1"
    else
        bin="/usr/bin/php$defaultPhpVersion"
    fi
    printf "  %s; cli " "$bin"
    $bin --ri=ddtrace > /dev/null 2>&1
}

function testInstalledCli() {
    if [ $# -eq 1 ]; then
        checkIsInstalledCli "$1"
    else
        checkIsInstalledCli
    fi
    showStatus $?
}

function testNotInstalledCli() {
    if [ $# -eq 1 ]; then
        checkIsInstalledCli "$1"
    else
        checkIsInstalledCli
    fi
    case $? in
        0) showStatus 1;;
        *) showStatus 0;;
    esac
}

function enableExtension() {
    if [ $# -eq 1 ]; then
        (export DD_TRACE_PHP_BIN="$(command -v $1)"; /src/ddtrace-scripts/post-install.sh > /dev/null 2>&1)
    else
        /src/ddtrace-scripts/post-install.sh > /dev/null 2>&1
    fi
}

printf "> Test ddtrace is not installed yet...\n"
reset
for v in $phpVersions; do
    testNotInstalled "$v" fpm-fcgi
    testNotInstalledCli "$v"
done
testNotInstalled $apachePhpVersion apache2handler

printf "\n> Test ddtrace is installed for default PHP version only...\n"
enableExtension # Empty params enables default PHP version
restartService "php-fpm$defaultPhpVersion"
for v in $phpVersions; do
    if [ "$v" != "$defaultPhpVersion" ]; then
        testNotInstalled "$v" fpm-fcgi
        testNotInstalledCli "$v"
    else
        testInstalled "$v" fpm-fcgi
        testInstalledCli "$v"
    fi
done
restartService "apache2"
if [ "$apachePhpVersion" != "$defaultPhpVersion" ]; then
    testNotInstalled $apachePhpVersion apache2handler
else
    testInstalled $apachePhpVersion apache2handler
fi

printf "\n> Test ddtrace is installed for specific PHP-FPM versions...\n"
reset
for v in $phpVersions; do
    enableExtension "php-fpm$v"
    restartService "php-fpm$v"
    testInstalled "$v" fpm-fcgi
    testInstalledCli "$v"
    if [ "$v" = "$apachePhpVersion" ]; then
        restartService "apache2"
        testInstalled $apachePhpVersion apache2handler
    fi
done

printf "\n> Test ddtrace is installed for specific PHP-CLI versions...\n"
reset
for v in $phpVersions; do
    enableExtension "php$v"
    restartService "php-fpm$v"
    testInstalled "$v" fpm-fcgi
    testInstalledCli "$v"
    if [ "$v" = "$apachePhpVersion" ]; then
        restartService "apache2"
        testInstalled $apachePhpVersion apache2handler
    fi
done

# Not running reset here to leave in unclean state for debugging

if [ $exitCode -eq 1 ]; then
    exit 1
fi
