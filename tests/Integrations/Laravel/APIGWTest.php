<?php

namespace DDTrace\Tests\Integrations\Laravel;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;


class APIGWTest extends WebFrameworkTestCase
{
    public static $database = "laravel11";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Laravel/Latest/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'laravel_test_app',
            'DD_SERVICE' => 'my_service',
            'DD_ENV' => 'local-test',
            'DD_VERSION' => '1.0',
            'DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED' => 'true',
            //'DD_TRACE_HEADER_TAGS' => 'x-dd-proxy-domain-name,x-dd-proxy,x-dd-proxy-httpmethod,x-dd-proxy-path,x-dd-proxy-request-time-ms,x-dd-proxy-stage',
        ]);
    }

    public function testInferredProxy()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/simple?key=value&pwd=should_redact',
                    [
                        'x-dd-proxy: aws-apigateway',
                        'x-dd-proxy-request-time-ms: 1739261376000',
                        'x-dd-proxy-path: /test',
                        'x-dd-proxy-httpmethod: GET',
                        'x-dd-proxy-domain-name: example.com',
                        'x-dd-proxy-stage: aws-prod',
                    ]
                )
            );
        });
    }

    public function testInferredProxyException()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET throwing an exception',
                    '/error?key=value&pwd=should_redact',
                    [
                        'x-dd-proxy: aws-apigateway',
                        'x-dd-proxy-request-time-ms: 1739261376000',
                        'x-dd-proxy-path: /test',
                        'x-dd-proxy-httpmethod: GET',
                        'x-dd-proxy-domain-name: example.com',
                        'x-dd-proxy-stage: aws-prod',
                    ]
                )
            );
        });
    }
}