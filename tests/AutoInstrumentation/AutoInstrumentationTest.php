<?php

namespace DDTrace\Tests\AutoInstrumentation;

use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Tests\WebServer;
use DDTrace\Tracer;

class AutoInstrumentationTest extends BaseTestCase
{
    /**
     * @dataProvider autoInstrumentationScenarios
     * @param string $scenario
     * @param string $expectedVersion
     * @param bool $isComposer
     */
    public function testAutoInstrumentationScenarios($scenario, $expectedVersion, $isComposer)
    {
        if ($isComposer) {
            $this->composerUpdateScenario($scenario);
        }
        $loadedVersion = $this->runAndReadVersion($scenario);
        $this->assertSame($expectedVersion, $loadedVersion);
    }

    public function autoInstrumentationScenarios()
    {
        $currentTracerVersion = Tracer::version();
        return [
            // In a typical scenario, when the user does not declare a dependency on datadog/dd-trace in the composer
            // file we should pick the tracer from the installed bundle
            ['composer_without_ddtrace_dependency', $currentTracerVersion, true],

            // We want to make sure that the version declared in composer.json is picked up instead of the installed
            // version.
            ['composer_with_ddtrace_dependency', '0.31.0', true],

            // We want to make sure that users can safely declare a dependency in the latest version of our
            // tracer in composer even if not required.
            ['composer_with_local_code_dependency', $currentTracerVersion, true],

            // Symfony 3.3 has a loader Symfony\Component\Config\Resource\ClassExistenceResource which registers a
            // private method as the actual class loader. Because of https://github.com/DataDog/dd-trace-php/issues/224
            // we do not support this scenario, yet. As a result, we have to make sure that the workaround we applied
            // works.
            ['symfony_33_private_loader', $currentTracerVersion, true],

            // In some cases, e.g. Zend Framework 1.12's Zend_Loader, loaders are not lenient, e.g. returning null/false
            // if they do not recognize their namespace. Instead they try to load the file, no matter what. This causes
            // an issue when we run `class_exist` during the first phases of auto-instrumentation because the loader
            // would try to `include_once` a file (e.g. 'DDTrace/Tracer.php') that does not exists.
            ['autoloader_includes_even_non_existing', $currentTracerVersion, false],

            // In some cases, autoloaders exit() or die() if they don't find a class. This test makes sure that in our
            // auto-instrumentation code we never try to `class_exists()`, `method_exists()` or `defined()` outside of
            // DDTrace namespace.
            ['autoloader_die_on_not_found', $currentTracerVersion, false],
        ];
    }

    private function composerUpdateScenario($scenario)
    {
        $here = __DIR__;
        $scenarioFolder = $this->buildScenarioAbsPath($scenario);
        exec(
            "cd $scenarioFolder && composer config disable-tls true && composer update -q && cd $here",
            $output,
            $return
        );
        if (0 !== $return) {
            $this->fail('Error while preparing the env: ' . implode("\n", $output));
        }
    }

    private function buildScenarioAbsPath($scenario)
    {
        return __DIR__ . "/scenarios/$scenario";
    }

    private function runAndReadVersion($scenario)
    {
        $scenarioFolder = $this->buildScenarioAbsPath($scenario);
        $indexFile = "$scenarioFolder/index.php";
        $webServer = new WebServer($indexFile, $host = '0.0.0.0', $port = 9876);
        $webServer->setInis([
            'error_log' => __DIR__ . '/error.log',
            'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
        ]);
        $webServer->setEnvs([
            'DD_TRACE_DEBUG' => 'true',
        ]);
        $webServer->start();

        // Retrieving the version
        $client = curl_init("127.0.0.1:$port");
        curl_setopt($client, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($client);
        if (false === $response) {
            $this->fail(
                "An error occurred during request to retrieve the version number: " . print_r(curl_error($client), 1)
            );
            return 'error';
        }

        $webServer->stop();

        return $response;
    }
}
