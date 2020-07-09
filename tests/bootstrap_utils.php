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

    // Whitelist of classes that will not be available in our dd_*_deps_autoloader.php and will instead only
    // be available for backward compatibility reasons via composer
    $composerOnly = [
        'DDTrace\\Configuration',
        'DDTrace\\Configuration\\AbstractConfiguration',
        'DDTrace\\Configuration\\EnvVariableRegistry',
        'DDTrace\\Configuration\\Registry',
    ];
    if (\in_array($class, $composerOnly)) {
        return;
    }

    // Classes not loaded by any other autoloader or non test specific should raise exceptions in tests
    throw new \Exception("add " . $class . " to bridge/_files.php or bridge/dd_register_optional_deps_autoloader.php");
}

function prepend_test_autoloaders()
{
    \putenv('DD_PHPUNIT_BOOTSTRAP=true');
    require_once __DIR__ . '/../bridge/dd_init.php';
    \putenv('DD_PHPUNIT_BOOTSTRAP');
    spl_autoload_register('\DDTrace\Tests\missing_ddtrace_class_fatal_autoloader', true);
}
