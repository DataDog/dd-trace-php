<?php

namespace DDTrace\Tests\Integrations\CodeIgniter\V3_1;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class ExitTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CodeIgniter/Version_3_1/ddshim.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'codeigniter_test_app',
        ]);
    }

    public function testScenarioExit()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'Test that exit works',
                    '/exits'
                )
            );
        });
    }
}
