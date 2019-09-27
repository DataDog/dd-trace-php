<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class RouteNameUriResourceNamesTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_3/web/app.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'symfony_uri_resource_names',
            'DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED' => 'true',
        ]);
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

        $this->assertExpectedSpans($traces, [
            SpanAssertion::build(
                'web.request',
                'symfony_uri_resource_names',
                'web',
                'GET /'
            )->withExactTags([
                'http.method' => 'GET',
                'http.url' => '/',
                'http.status_code' => '200',
                'integration.name' => 'symfony',
            ]),
        ]);
    }
}
