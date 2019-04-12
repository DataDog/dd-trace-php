<?php
namespace DDTrace\Tests;
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

function ddtrace_missing_class_fatal_autoloader($class)
{
    // project-specific namespace prefix
    $dataDogNamespaceRoot = 'DDTrace\\';

    // If it is not Datadog, let's exit soon
    $len = strlen($dataDogNamespaceRoot);
    if (strncmp($dataDogNamespaceRoot, $class, $len) !== 0) {
        return;
    }

    // If it is Datadog tests then don't throw exception
    $dataDogTestsNamespaceRoot = 'DDTrace\\Tests\\';

    if (strncmp($dataDogTestsNamespaceRoot, $class, strlen($dataDogTestsNamespaceRoot)) === 0) {
        return;
    }

    // Classes not loaded by any other autoloader or non test specific should raise exceptions in tests
    throw new RuntimeException("add " . $class . "to one of dd_*_deps_autoloader.php");
}

// Final autoloader order should be:
// DD optional, DD required, ddtrace_missing_class_fatal_autoloader, [ rest autoloaders ]

spl_autoload_register('DDTrace\Tests\ddtrace_missing_class_fatal_autoloader', true, true);

require __DIR__ . '/../bridge/functions.php';
\DDTrace\Bridge\dd_register_autoloader();
