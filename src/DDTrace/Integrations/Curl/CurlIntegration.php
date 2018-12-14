<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Formats;
use DDTrace\Http\Urls;
use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Util\ArrayKVStore;
use DDTrace\GlobalTracer;

/**
 * Integration for curl php client.
 */
class CurlIntegration
{
    const NAME = 'curl';

    /**
     * Loads the integration.
     */
    public static function load()
    {
        if (!function_exists('curl_exec')) {
            return;
        }

        $globalConfig = Configuration::get();

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

        dd_trace('curl_setopt', function ($ch, $option, $value) use ($globalConfig) {
            // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
            // multiple times on the same resource.
            if ($option === CURLOPT_HTTPHEADER
                    && $globalConfig->isDistributedTracingEnabled()
                    && is_array($value)
            ) {
                // Storing data to be used during exec as it cannot be retrieved at then.
                ArrayKVStore::putForResource($ch, Formats\CURL_HTTP_HEADERS, $value);
            }

            return curl_setopt($ch, $option, $value);
        });

        dd_trace('curl_setopt_array', function ($ch, $options) use ($globalConfig) {
            // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
            // multiple times on the same resource.
            if ($globalConfig->isDistributedTracingEnabled()
                    && array_key_exists(CURLOPT_HTTPHEADER, $options)
            ) {
                // Storing data to be used during exec as it cannot be retrieved at then.
                ArrayKVStore::putForResource($ch, Formats\CURL_HTTP_HEADERS, $options[CURLOPT_HTTPHEADER]);
            }

            return curl_setopt_array($ch, $options);
        });

        dd_trace('curl_close', function ($ch) use ($globalConfig) {
            ArrayKVStore::deleteResource($ch);
            return curl_close($ch);
        });
    }

    /**
     * @param resource $ch
     */
    public static function injectDistributedTracingHeaders($ch)
    {
        if (!Configuration::get()->isDistributedTracingEnabled()) {
            return;
        }

        $httpHeaders = ArrayKVStore::getForResource($ch, Formats\CURL_HTTP_HEADERS, []);
        if (is_array($httpHeaders)) {
            $tracer = GlobalTracer::get();
            $context = $tracer->getActiveSpan()->getContext();
            $tracer->inject($context, Formats\CURL_HTTP_HEADERS, $httpHeaders);

            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        }
    }
}
