<?php

namespace DDTrace\Tests\Integrations\Laravel\Octane;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class APIGWTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return REPOSITORY_ROOT_DIR . '/tests/Frameworks/Laravel/Octane/Latest/artisan';
    }

    protected static function isOctane()
    {
        return true;
    }

    public static function ddSetUpBeforeClass()
    {
        $swooleIni = file_get_contents(__DIR__ . '/swoole.ini');
        $swooleIni = str_replace('{{path}}', REPOSITORY_ROOT_DIR, $swooleIni);

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
            'DD_TRACE_AGENT_PROTOCOL_VERSION' => '0.4',
        ]);
    }

    public static function isApigwTrace($trace)
    {
        return \in_array('aws.apigateway', array_column($trace['spans'], 'name'), true);
    }

    public function testInferredProxy()
    {
        $until = function ($request) {
            $body = $request["body"] ?? [];
            $traces = empty($body) ? [] : $this->parseRawDumpedTraces(json_decode($body, true));

            foreach ($traces as $trace) {
                foreach ($trace['spans'] as $span) {
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

        $this->snapshotFromTraces(function () use ($until) {
            return $this->tracesFromWebRequest(function () {
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
        }, null, null, [self::class, 'isApigwTrace']);
    }

    public function testInferredProxyException()
    {
        $until = function ($request) {
            $body = $request["body"] ?? [];
            $traces = empty($body) ? [] : $this->parseRawDumpedTraces(json_decode($body, true));

            foreach ($traces as $trace) {
                foreach ($trace['spans'] as $span) {
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

        $this->snapshotFromTraces(function () use ($until) {
            return $this->tracesFromWebRequest(function () {
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
        }, null, null, [self::class, 'isApigwTrace']);
    }
}