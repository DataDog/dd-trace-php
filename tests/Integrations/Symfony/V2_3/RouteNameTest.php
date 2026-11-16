<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class RouteNameTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_3/web/app.php';
    }

    public function testResourceToURIMapping()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $spec = GetSpec::create(
                'Resource name properly set to route',
                '/app.php?key=value&pwd=should_redact'
            );
            $this->call($spec);
        });
    }
}
