<?php

namespace DDTrace\Tests\Integrations\Laravel\V11_x;

use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Laravel\V9_x\CommonScenariosTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_11_x/public/index.php';
    }

    public function testIgnoredExceptionAreNotReported()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'Custom Exception',
                    '/custom_exception?key=value&pwd=should_redact'
                )
            );
        });
    }
}
