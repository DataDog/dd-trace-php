<?php

function isLoaded ($name, $zend_ext = false) {
    return in_array($name, get_loaded_extensions($zend_ext)) ? 'YES' : 'NO';
};

echo 'opcache: '.isLoaded('Zend OPcache', true)."\n";
echo 'ddtrace (Zend ext): '.isLoaded('ddtrace', true)."\n";
echo 'ddtrace (ext): '.isLoaded('ddtrace', false)."\n";
echo 'dd_library_loader: '.isLoaded('dd_library_loader', true)."\n";
