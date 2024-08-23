<?php

namespace DDTrace\Tests;

if (getenv('DD_AUTOLOAD_NO_COMPILE') == 'true' && (false !== getenv('CI') || false !== getenv('CIRCLECI'))) {
    throw new Exception('Tests must run using the _generated.php script in CI');
}

// Setting an environment variable to signal we are in a tests run
putenv('DD_TEST_EXECUTION=1');

if (function_exists("dd_trace_env_config") && \dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
    // Only explicit flushes with sidecar
    putenv("DD_TRACE_AGENT_FLUSH_INTERVAL=3000000");
}

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


spl_autoload_register('\DDTrace\Tests\missing_ddtrace_class_fatal_autoloader', true);

