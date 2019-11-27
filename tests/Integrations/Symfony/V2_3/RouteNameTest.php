<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class RouteNameTest extends WebFrameworkTestCase
{
    const IS_SANDBOX = false;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_3/web/app.php';
    }

    /**
     * @throws \Exception
     */
    public function testResource2UriMapping()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Resource name properly set to route', '/');
            $this->call($spec);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'web.request',
                'web.request',
                'web',
                'AppBundle\Controller\DefaultController testingRouteNameAction'
            )->withExactTags([
                'http.method' => 'GET',
                'http.url' => '/',
                'http.status_code' => '200',
                'integration.name' => 'symfony',
            ]),
        ]);
    }
}
