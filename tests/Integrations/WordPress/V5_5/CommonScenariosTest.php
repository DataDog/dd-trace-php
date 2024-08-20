<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_5;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use Exception;

class CommonScenariosTest extends WebFrameworkTestCase
{
    public static $database = "wp55";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_5_5/index.php';
    }

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        $pdo = new \PDO('mysql:host=mysql_integration;dbname=wp55', 'test', 'test');
        $pdo->exec(file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_5_5/wp_2020-10-21.sql'));
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'wordpress_55_test_app',
            'DD_TRACE_WORDPRESS_CALLBACKS' => '0',
            'DD_TRACE_MYSQLI_ENABLED' => '0',
        ]);
    }

    public function testScenarioGetReturnString()
    {
        if (\getenv('PHPUNIT_COVERAGE')) {
            $this->markTestSkipped('Test is too flaky under coverage mode');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/simple?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetWithView()
    {
        if (\getenv('PHPUNIT_COVERAGE')) {
            $this->markTestSkipped('Test is too flaky under coverage mode');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request with a view',
                    '/simple_view?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetWithException()
    {
        if (\getenv('PHPUNIT_COVERAGE')) {
            $this->markTestSkipped('Test is too flaky under coverage mode');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/error?key=value&pwd=should_redact'
                )->expectStatusCode(200)
            );
        });
    }

    public function testScenarioGetToMissingRoute()
    {
        if (\getenv('PHPUNIT_COVERAGE')) {
            $this->markTestSkipped('Test is too flaky under coverage mode');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request to a missing route',
                    '/does_not_exist?key=value&pwd=should_redact'
                )->expectStatusCode(404)
            );
        });
    }
}
