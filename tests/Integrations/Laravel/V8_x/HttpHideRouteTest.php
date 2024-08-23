<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class HttpHideRouteTest extends WebFrameworkTestCase
{
    public static $database = "laravel8";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'laravel_test_app',
            'DD_HTTP_SERVER_ROUTE_BASED_NAMING' => 'false',
            'DD_TRACE_PROPAGATION_STYLE' => '',
        ]);
    }

    public function testDefaultPath() {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('A simple GET request returning a string', '/simple'));
        });

        foreach ($traces[0] as $span) {
            if (empty($span["parent_id"])) {
                $this->assertEquals("GET /simple", $span["resource"]);
            }
        }
    }
}
