#!/bin/sh

errorf() {
    printf "$@"
    exit 1
}

case $(php-config --vernum | cut -c1-3) in
    504 ) xdebug_version="-2.4.1" ;;
    505 ) xdebug_version="-2.5.5" ;;
    506 ) xdebug_version="-2.5.5" ;;
    700 ) xdebug_version="-2.7.2" ;;
    701 ) xdebug_version="-2.8.1" ;;

    # Anything newer uses very under-development versions
    * )   xdebug_version="" ;;
esac

confdir="$(php -i | awk -F'=>' '/Scan this dir for additional .ini files/ { sub(/^[ \t]/, "", $2) ; print $2 }')"
if [ $? -eq 0 ]
then
    if [ "$confdir" = "(none)" ]
    then
        errorf "ERROR: php is not configured with a directory to scan for additional .ini files\n" >&2
    fi

    if [ ! -d "$confdir" ]
    then
        errorf "ERROR: the PHP configuration directory does not exist, or is not a directory\n" >&2
    fi


    php_ini="$(pecl config-get php_ini)"
    xdebug_ini="$confdir/xdebug.ini"
    echo "Xdebug ini file location: ${xdebug_ini?}"

    touch "$xdebug_ini"

    # use pear config-set, not pecl config-set, or else it doesn't get used
    # Yes, set it to empty. In older versions this puts a string like:
    #    zend_extension_debug=xdebug.so
    # Which doesn't actually work -- so do it ourselves
    pear config-set php_ini ""
    if pecl install "xdebug${xdebug_version?}"
    then
        pear config-set php_ini "$php_ini"
        extension_dir=$(php -r "echo ini_get('extension_dir');")
        tee -a "${xdebug_ini?}" <<eof
zend_extension=${extension_dir?}/xdebug.so
xdebug.idekey=phpstorm
xdebug.remote_autostart=1
xdebug.remote_connect_back=0
xdebug.remote_enable=1
xdebug.remote_host=host.docker.internal
eof
    else
        pear config-set php_ini "$php_ini"
    fi

else
    errorf "ERROR: unable to determine PHP's ini configuration directory.\nInvestigate php -i, look for 'Scan this dir'\n" >&2
fi

