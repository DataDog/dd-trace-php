<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Autoload\AutoloaderRegistry;

require_once __DIR__ . '/../../../../app/bootstrap.php';
require_once __DIR__ . '/autoload.php';

error_reporting(E_ALL);
$testsBaseDir = dirname(__DIR__);
$integrationTestsDir = realpath("{$testsBaseDir}/../integration");
$fixtureBaseDir = $integrationTestsDir . '/testsuite';

if (!defined('TESTS_TEMP_DIR')) {
    define('TESTS_TEMP_DIR', $testsBaseDir . '/tmp');
}

if (!defined('INTEGRATION_TESTS_DIR')) {
    define('INTEGRATION_TESTS_DIR', $integrationTestsDir);
}

try {
    setCustomErrorHandler();

    /* Bootstrap the application */
    $settings = new \Magento\TestFramework\Bootstrap\Settings($testsBaseDir, get_defined_constants());

    if ($settings->get('TESTS_EXTRA_VERBOSE_LOG')) {
        $filesystem = new \Magento\Framework\Filesystem\Driver\File();
        $exceptionHandler = new \Magento\Framework\Logger\Handler\Exception($filesystem);
        $loggerHandlers = [
            'system'    => new \Magento\Framework\Logger\Handler\System($filesystem, $exceptionHandler),
            'debug'     => new \Magento\Framework\Logger\Handler\Debug($filesystem)
        ];
        $shell = new \Magento\Framework\Shell(
            new \Magento\Framework\Shell\CommandRenderer(),
            new \Monolog\Logger('main', $loggerHandlers)
        );
    } else {
        $shell = new \Magento\Framework\Shell(new \Magento\Framework\Shell\CommandRenderer());
    }

    $testFrameworkDir = __DIR__;
    require_once INTEGRATION_TESTS_DIR . '/framework/deployTestModules.php';

    $installConfigFile = $settings->getAsConfigFile('TESTS_INSTALL_CONFIG_FILE');
    if (!file_exists($installConfigFile)) {
        $installConfigFile = $installConfigFile . '.dist';
    }
    $postInstallConfigFile = $settings->getAsConfigFile('TESTS_POST_INSTALL_SETUP_COMMAND_CONFIG_FILE');
    if (!file_exists($postInstallConfigFile)) {
        $postInstallConfigFile = $postInstallConfigFile . '.dist';
    }
    $globalConfigFile = $settings->getAsConfigFile('TESTS_GLOBAL_CONFIG_FILE');
    if (!file_exists($globalConfigFile)) {
        $globalConfigFile = $globalConfigFile . '.dist';
    }
    $dirList     = new \Magento\Framework\App\Filesystem\DirectoryList(BP);
    $application = new \Magento\TestFramework\WebApiApplication(
        $shell,
        $dirList->getPath(DirectoryList::VAR_DIR),
        $installConfigFile,
        $globalConfigFile,
        BP . '/app/etc/',
        $settings->get('TESTS_MAGENTO_MODE'),
        AutoloaderRegistry::getAutoloader(),
        false,
        $postInstallConfigFile
    );

    if (defined('TESTS_MAGENTO_INSTALLATION') && TESTS_MAGENTO_INSTALLATION === 'enabled') {
        $cleanup = (defined('TESTS_CLEANUP') && TESTS_CLEANUP === 'enabled');
        $application->install($cleanup);
    }

    $bootstrap = new \Magento\TestFramework\Bootstrap(
        $settings,
        new \Magento\TestFramework\Bootstrap\Environment(),
        new \Magento\TestFramework\Bootstrap\WebapiDocBlock("{$integrationTestsDir}/testsuite"),
        new \Magento\TestFramework\Bootstrap\Profiler(new \Magento\Framework\Profiler\Driver\Standard()),
        $shell,
        $application,
        new \Magento\TestFramework\Bootstrap\MemoryFactory($shell)
    );
    $bootstrap->runBootstrap();
    $application->initialize();

    \Magento\TestFramework\Helper\Bootstrap::setInstance(new \Magento\TestFramework\Helper\Bootstrap($bootstrap));
    $dirSearch = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
       ->create(\Magento\Framework\Component\DirSearch::class);
    $themePackageList = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
       ->create(\Magento\Framework\View\Design\Theme\ThemePackageList::class);
    \Magento\Framework\App\Utility\Files::setInstance(
        new \Magento\Framework\App\Utility\Files(
            new \Magento\Framework\Component\ComponentRegistrar(),
            $dirSearch,
            $themePackageList
        )
    );
    $overrideConfig = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
        Magento\TestFramework\WebapiWorkaround\Override\Config::class
    );
    $overrideConfig->init();
    Magento\TestFramework\Workaround\Override\Fixture\Resolver::setInstance(
        new  \Magento\TestFramework\WebapiWorkaround\Override\Fixture\Resolver($overrideConfig)
    );
    Magento\TestFramework\Fixture\DataFixtureStorageManager::setStorage(
        new Magento\TestFramework\Fixture\DataFixtureStorage()
    );
    \Magento\TestFramework\Workaround\Override\Config::setInstance($overrideConfig);
    unset($bootstrap, $application, $settings, $shell, $overrideConfig);
} catch (\Exception $e) {
    // phpcs:ignore Magento2.Security.LanguageConstruct.DirectOutput
    echo $e . PHP_EOL;
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

/**
 * Set custom error handler
 */
function setCustomErrorHandler()
{
    set_error_handler(
        function ($errNo, $errStr, $errFile, $errLine) {
            $errLevel = error_reporting();
            if (($errLevel & $errNo) !== 0) {
                $errorNames = [
                    E_ERROR => 'Error',
                    E_WARNING => 'Warning',
                    E_PARSE => 'Parse',
                    E_NOTICE => 'Notice',
                    E_CORE_ERROR => 'Core Error',
                    E_CORE_WARNING => 'Core Warning',
                    E_COMPILE_ERROR => 'Compile Error',
                    E_COMPILE_WARNING => 'Compile Warning',
                    E_USER_ERROR => 'User Error',
                    E_USER_WARNING => 'User Warning',
                    E_USER_NOTICE => 'User Notice',
                    E_STRICT => 'Strict',
                    E_RECOVERABLE_ERROR => 'Recoverable Error',
                    E_DEPRECATED => 'Deprecated',
                    E_USER_DEPRECATED => 'User Deprecated',
                ];

                $errName = isset($errorNames[$errNo]) ? $errorNames[$errNo] : "";

                throw new \PHPUnit\Framework\Exception(
                    sprintf("%s: %s in %s:%s.", $errName, $errStr, $errFile, $errLine),
                    $errNo
                );
            }
        }
    );
}
