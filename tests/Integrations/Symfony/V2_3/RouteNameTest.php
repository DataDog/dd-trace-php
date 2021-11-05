<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class RouteNameTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_3/web/app.php';
    }

    /**
     * @throws \Exception
     */
    public function testResource2UriMapping()
    {
        // memory leak exists in PHP 5.5-7.0, independently of ddtrace
        $this->checkWebserverErrors = false;

        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Resource name properly set to route', '/app.php');
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
                'http.url' => 'http://localhost:' . self::PORT . '/app.php',
                'http.status_code' => '200',
            ])->withChildren([
                SpanAssertion::exists('symfony.httpkernel.kernel.handle')->withChildren([
                    SpanAssertion::exists('symfony.httpkernel.kernel.boot'),
                ]),
            ]),
        ]);
    }
}
