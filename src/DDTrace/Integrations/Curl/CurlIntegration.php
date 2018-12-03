<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Http\Headers;
use DDTrace\Http\Urls;
use DDTrace\Propagators\TextMap;
use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Util\ArrayKVStore;
use const OpenTracing\Formats\HTTP_HEADERS;
use OpenTracing\GlobalTracer;


/**
 * Integration for curl php client.
 */
class CurlIntegration
{
    /**
     * Loads the integration.
     */
    public static function load()
    {
        $globalConfig = Configuration::instance();

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

            CurlIntegration::injectDistributedTracingHeaders($ch);

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

        dd_trace('curl_setopt', function($ch, $option, $value) use ($globalConfig) {
            // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
            // multiple times on the same resource.
            if ($option === CURLOPT_HTTPHEADER
                    && $globalConfig->isDistributedTracingEnabled()
                    && is_array($value)
            ) {
                // Storing data to be used during exec as it cannot be retrieved at then.
                ArrayKVStore::putForResource($ch, 'http_headers', $value);
            }

            return curl_setopt($ch, $option, $value);
        });

        dd_trace('curl_setopt_array', function($ch, $options) use ($globalConfig) {
            // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
            // multiple times on the same resource.
            if ($globalConfig->isDistributedTracingEnabled()
                    && array_key_exists(CURLOPT_HTTPHEADER, $options)
            ) {
                // Storing data to be used during exec as it cannot be retrieved at then.
                ArrayKVStore::putForResource($ch, 'http_headers', $options[CURLOPT_HTTPHEADER]);
            }

            return curl_setopt_array($ch, $options);
        });
    }

    /**
     * @param resource $ch
     */
    public static function injectDistributedTracingHeaders($ch)
    {
        if (!Configuration::instance()->isDistributedTracingEnabled()) {
            return;
        }

        $currentHttpHeaders = ArrayKVStore::getForResource($ch, 'http_headers', []);
        if (is_array($currentHttpHeaders)) {
            $tracer = GlobalTracer::get();
            $context = $tracer->getActiveSpan()->getContext();
            $carrier = Headers::colonSeparatedValuesToHeadersMap($currentHttpHeaders);
            $tracer->inject($context, HTTP_HEADERS, $carrier);

            curl_setopt($ch, CURLOPT_HTTPHEADER, Headers::headersMapToColonSeparatedValues($carrier));
        }
    }
}
