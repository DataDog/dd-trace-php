<?php

namespace DDTrace\Tests\Integrations\Laminas\ApiTools\V1_9;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;

class RESTTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../../Frameworks/Laminas/ApiTools/Version_1_9/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), ['DD_SERVICE' => 'test_laminas_rest_19']);
    }

    public static function getTestedLibrary()
    {
        return 'laminas-api-tools/api-tools';
    }

    public function testScenarioRest4xx()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple request to a 405 REST endpoint',
                    '/datadog-rest-service/1'
                )
            );
        });
    }

    public function testScenarioRest2xx()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                PostSpec::create(
                    'A simple request to a 201 REST endpoint',
                    '/datadog-rest-service',
                    ['Content-Type: application/json'],
                    ['data' => 'dog']
                )
            );
        });
    }

    public function testScenarioRest5xx()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple request to a 500 REST endpoint',
                    '/datadog-rest-service/42'
                )
            );
        });
    }
}
