<?php

namespace DDTrace\Bridge;

use DDTrace\Integrations\IntegrationsLoader;

\DDTrace\install_hook("", function($h) {
    var_dump($h->args[0]);
    //print(new \Exception(""));
});


var_dump("dd_init called");

if (\PHP_VERSION_ID < 70000) {
    \date_default_timezone_set(@\date_default_timezone_get());
}
// Required classes and functions
require __DIR__ . '/autoload.php';

// Optional classes and functions
require __DIR__ . '/dd_register_optional_deps_autoloader.php';

IntegrationsLoader::load();
