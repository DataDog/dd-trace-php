<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Http\Urls;
use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;


/**
 * Integration for curl php client.
 */
class CurlIntegration
{
    const URL = 'url';

    /**
     * Loads the integration.
     */
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('The ddtrace extension is required to instrument curl', E_USER_WARNING);
            return;
        }
        if (!function_exists('curl_exec')) {
            return;
        }

        dd_trace('curl_exec', function ($ch) {
            $tracer = GlobalTracer::get();
            $scope = $tracer->startActiveSpan('curl_exec');
            $span = $scope->getSpan();
            $span->setTag(Tags\SERVICE_NAME, 'curl');
            $span->setTag(Tags\SPAN_TYPE, Types\HTTP_CLIENT);

            $result = curl_exec($ch);
            if ($result === false && $span instanceof Span) {
                $span->setRawError(curl_error($ch), 'curl error');
            }

            $info = curl_getinfo($ch);
            $sanitizedUrl = Urls::sanitize($info['url']);
            $span->setTag(Tags\RESOURCE_NAME, $sanitizedUrl);
            $span->setTag(Tags\HTTP_URL, $sanitizedUrl);
            $span->setTag(Tags\HTTP_STATUS_CODE, $info['http_code']);

            $scope->close();
            return $result;
        });
    }
}
