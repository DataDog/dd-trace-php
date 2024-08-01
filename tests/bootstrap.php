<?php

error_reporting(E_ALL);

if (getenv('DD_AUTOLOAD_NO_COMPILE') == 'true' && (false !== getenv('CI') || false !== getenv('CIRCLECI'))) {
    throw new Exception('Tests must run using the _generated.php script in CI');
}

// Setting an environment variable to signal we are in a tests run
putenv('DD_TEST_EXECUTION=1');

if (function_exists("dd_trace_env_config") && \dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
    // Only explicit flushes with sidecar
    putenv("DD_TRACE_AGENT_FLUSH_INTERVAL=3000000");
}

$phpunitVersionParts = class_exists('\PHPUnit\Runner\Version')
    ? explode('.', \PHPUnit\Runner\Version::id())
    : explode('.', PHPUnit_Runner_Version::id());
define('PHPUNIT_MAJOR', intval($phpunitVersionParts[0]));

if (PHPUNIT_MAJOR >= 8) {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_typed.php';
} else {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_untyped.php';
}

function update_test_agent_session_token($token) {
    if (defined('GLOBAL_AUTO_PREPEND_RSRC')) {
        ini_set("datadog.trace.agent_test_session_token", $token);
        ftruncate(GLOBAL_AUTO_PREPEND_RSRC, 0);
        fseek(GLOBAL_AUTO_PREPEND_RSRC, 0);
        fwrite(GLOBAL_AUTO_PREPEND_RSRC, "<?php " . (getenv('PHPUNIT_COVERAGE') ? " require '" . __DIR__ . "/save_code_coverage.php';" : ""). " ini_set('datadog.trace.agent_test_session_token', '$token');");
    }
}

@mkdir("/tmp/ddtrace-phpunit");
for ($i = 1; $i <= 50; ++$i) {
    $_global_portlock_file = fopen($path = "/tmp/ddtrace-phpunit/$i", "c+");
    if (flock($_global_portlock_file, LOCK_EX | LOCK_NB)) {
        define('GLOBAL_PORT_OFFSET', $i);
        define('GLOBAL_AUTO_PREPEND_RSRC', $_global_portlock_file);
        define('GLOBAL_AUTO_PREPEND_FILE', $path);
        break;
    }
}
if (!defined('GLOBAL_PORT_OFFSET')) {
    define('GLOBAL_PORT_OFFSET', 0);
    define('GLOBAL_AUTO_PREPEND_FILE', getenv('PHPUNIT_COVERAGE') ? __DIR__ . '/save_code_coverage.php' : "");
}
update_test_agent_session_token('phpunit-' . GLOBAL_PORT_OFFSET);
