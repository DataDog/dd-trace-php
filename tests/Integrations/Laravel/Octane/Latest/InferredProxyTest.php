<?php

namespace DDTrace\Tests\Integrations\Laravel\Octane\Latest;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class InferredProxyTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../../Frameworks/Laravel/Octane/Latest/artisan';
    }

    protected static function isOctane()
    {
        return true;
    }

    public static function ddSetUpBeforeClass()
    {
        $swooleIni = file_get_contents(__DIR__ . '/../swoole.ini');

        $currentDir = getcwd();
        $isLocalDevEnv = strpos($currentDir, 'datadog') === false;
        $replacement = $isLocalDevEnv ? '/home/circleci/app' : '/home/circleci/datadog';
        $swooleIni = str_replace('{{path}}', $replacement, $swooleIni);

        $autoloadNoCompile = getenv('DD_AUTOLOAD_NO_COMPILE');
        if (!$autoloadNoCompile || !filter_var($autoloadNoCompile, FILTER_VALIDATE_BOOLEAN)) {
            $swooleIni = str_replace('datadog.autoload_no_compile=true', 'datadog.autoload_no_compile=false', $swooleIni);
        }

        file_put_contents(dirname(self::getAppIndexScript()) . "/swoole.ini", $swooleIni);

        parent::ddSetUpBeforeClass();
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'laravel_test_app',
            'DD_SERVICE' => 'swoole_test_app',
            'DD_TRACE_CLI_ENABLED' => 'true',
            'PHP_INI_SCAN_DIR' => ':' . dirname(self::getAppIndexScript()),
            'DD_ENV' => 'local-test',
            'DD_VERSION' => '1.0',
            'DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED' => 'true',
            'DD_TRACE_HEADER_TAGS' => 'x-dd-proxy-domain-name,x-dd-proxy,x-dd-proxy-httpmethod,x-dd-proxy-path,x-dd-proxy-request-time-ms,x-dd-proxy-stage',
        ]);
    }

    public function testInferredProxy()
    {
        $until = function ($request) {
            $body = $request["body"] ?? [];
            $traces = empty($body) ? [[]] : json_decode($body, true);

            foreach ($traces as $trace) {
                foreach ($trace as $span) {
                    if ($span
                        && isset($span["name"])
                        && $span["name"] === "laravel.request"
                        && (str_contains($span["resource"], 'App\\Http\\Controllers') || $span["resource"] === 'GET /does_not_exist')
                    ) {
                        return true;
                    }
                }
            }

            return false;
        };

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
        }, null, $until);

        $apigwTrace = null;
        foreach ($traces as $trace) {
            if ($trace[0]["name"] === "aws-apigateway") {
                $apigwTrace = $trace;
                break;
            }
        }

        $this->snapshotFromTraces([$apigwTrace]);
    }

    public function testInferredProxyException()
    {
        $until = function ($request) {
            $body = $request["body"] ?? [];
            $traces = empty($body) ? [[]] : json_decode($body, true);

            foreach ($traces as $trace) {
                foreach ($trace as $span) {
                    if ($span
                        && isset($span["name"])
                        && $span["name"] === "laravel.request"
                        && (str_contains($span["resource"], 'App\\Http\\Controllers') || $span["resource"] === 'GET /does_not_exist')
                    ) {
                        return true;
                    }
                }
            }

            return false;
        };

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
        }, null, $until);

        $apigwTrace = null;
        foreach ($traces as $trace) {
            if ($trace[0]["name"] === "aws-apigateway") {
                $apigwTrace = $trace;
                break;
            }
        }

        $this->snapshotFromTraces([$apigwTrace]);
    }
}