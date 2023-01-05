<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tag;
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

        // Under Apache Symfony does redirection magic
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'symfony.request',
                'symfony',
                'web',
                'AppBundle\Controller\DefaultController testingRouteNameAction'
            )->withExactTags([
                'http.method' => 'GET',
                'http.url' => 'http://localhost:' . self::PORT . '/' . ($isApache ? '' : 'app.php'),
                'http.status_code' => '200',
                Tag::SPAN_KIND => 'server',
            ])->withChildren([
                SpanAssertion::exists('symfony.httpkernel.kernel.handle')->withChildren([
                    SpanAssertion::exists('symfony.httpkernel.kernel.boot'),
                ]),
            ]),
        ]);
    }
}
