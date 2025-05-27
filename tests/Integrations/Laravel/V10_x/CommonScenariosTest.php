<?php

namespace DDTrace\Tests\Integrations\Laravel\V10_x;

use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Laravel\V9_x\CommonScenariosTest
{
    public static $database = "laravel10";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_10_x/public/index.php';
    }

    public function testScenarioGetWithIgnoredException()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an ignored exception',
                    '/ignored_exception?key=value&pwd=should_redact'
                )
            );
        });
    }
}
