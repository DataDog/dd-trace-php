<?php

error_reporting(E_ALL);

require __DIR__ . '/bootstrap_common.php';

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

// ensure the integration-specific autoloader is loaded
$hook = function ($object, $scope, $args) {
    $path = dirname($args[0]);
    if (strpos($path, "vendor")) {
        return; // No nested vendor
    }
    while (strlen($path) > strlen(__DIR__)) {
        if (file_exists("$path/vendor/autoload.php")) {
            putenv("COMPOSER_ROOT_VERSION=1.0.0"); // silence composer
            \DDTrace\Tests\Common\IntegrationTestCase::$autoloadPath = "$path/vendor/autoload.php";
            require_once \DDTrace\Tests\Common\IntegrationTestCase::$autoloadPath;
            return;
        } elseif (file_exists("$path/composer.json")) {
            \DDTrace\Testing\trigger_error("Found $path/composer.json, but seems not installed", E_USER_ERROR);
        }
        $path = dirname($path);
    }
};
if (class_exists('PHPUnit\Runner\StandardTestSuiteLoader')) {
    \DDTrace\hook_method('PHPUnit\Util\FileLoader', 'load', $hook);
    \DDTrace\hook_method('PHPUnit\Runner\StandardTestSuiteLoader', 'load', $hook);
} elseif (method_exists('PHPUnit\Runner\TestSuiteLoader', 'loadSuiteClassFile')) {
    \DDTrace\hook_method('PHPUnit\Runner\TestSuiteLoader', 'loadSuiteClassFile', $hook);
} else {
    \DDTrace\hook_method('PHPUnit\Runner\TestSuiteLoader', 'load', $hook);
}
