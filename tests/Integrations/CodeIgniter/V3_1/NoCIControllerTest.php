<?php

namespace Integrations\CodeIgniter\V3_1;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class NoCIControllerTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CodeIgniter/Version_3_1/ddshim.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'codeigniter_test_app',
        ]);
    }

    public function testScenarioHealthCheck()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A call to healthcheck',
                    '/health_check/ping'
                )
            );
        });
    }
}
