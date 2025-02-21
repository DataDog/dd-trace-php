<?php

namespace DDTrace\Tests\Integrations\Roadrunner;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

// Nota Bene: This test HAS to run first. This is because other Roadrunner tests are setting persisting distributed tags.
class APIGWTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Roadrunner/Version_2/worker.php';
    }

    public static function getTestedLibrary()
    {
        return 'spiral/roadrunner';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return self::getRoadrunnerVersion();
    }

    protected static function getRoadrunnerVersion()
    {
        return "2.11.4";
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'roadrunner_test_app',
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_ENV' => 'local-test',
            'DD_VERSION' => '1.0',
            'DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED' => 'true',
            'DD_TRACE_HEADER_TAGS' => 'x-dd-proxy-domain-name,x-dd-proxy,x-dd-proxy-httpmethod,x-dd-proxy-path,x-dd-proxy-request-time-ms,x-dd-proxy-stage',
        ]);
    }

    public function testInferredProxy()
    {
        $traces = $this->tracesFromWebRequest(function () {
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

        $this->snapshotFromTraces($traces);
    }

    public function testInferredProxyException()
    {
        $traces = $this->tracesFromWebRequest(function () {
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

        $this->snapshotFromTraces($traces);
    }
}
