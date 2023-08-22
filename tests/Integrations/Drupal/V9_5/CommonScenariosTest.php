<?php

namespace DDTrace\Tests\Integrations\Drupal\V9_5;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Drupal/Version_9_5/web/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), ['DD_SERVICE' => 'test_drupal_95']);
    }

    public function testScenarioGetReturnString()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/simple?key=value&pwd=should_redact'
                )
            );
        });
    }
}
