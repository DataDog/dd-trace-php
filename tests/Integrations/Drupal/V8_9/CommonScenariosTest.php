<?php

namespace DDTrace\Tests\Integrations\Drupal\V8_9;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    public static $database = "drupal89";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Drupal/Version_8_9/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'test_drupal_89',
            'DD_TRACE_PDO_ENABLED' => 'false'
        ]);
    }

    public function ddSetUp()
    {
        parent::ddSetUp();
        $pdo = new \PDO('mysql:host=mysql_integration;dbname=' . static::$database, 'test', 'test');
        $cacheTables = $pdo->query("SHOW TABLES LIKE 'cache%'");
        while ($table = $cacheTables->fetchColumn()) {
            //fwrite(STDERR, "Truncating table $table" . PHP_EOL);
            $pdo->query('TRUNCATE ' . $table);
        }
    }

    public function testScenarioGetWithView()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request with a view',
                    '/simple_view'
                )
            );
        });
    }

    public function testScenarioGetWithException()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/error?key=value&pwd=should_redact'
                )->expectStatusCode(500)
            );
        });
    }

    public function testScenarioGetToMissingRoute()
    {
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
