<?php

namespace DDTrace\Tests;

function missing_ddtrace_class_fatal_autoloader($class)
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
    throw new \Exception("add " . $class . " to bridge/_files.php or bridge/dd_optional_deps_autoloader.php");
}

function prepend_test_autoloaders()
{
    require_once __DIR__ . '/../bridge/functions.php';

    spl_autoload_register('\DDTrace\Tests\missing_ddtrace_class_fatal_autoloader', true, true);
    \DDTrace\Bridge\dd_register_autoloader();
}
