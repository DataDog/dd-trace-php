<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Format;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ArrayKVStore;
use DDTrace\GlobalTracer;
use DDTrace\Util\Environment;

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
        if (!function_exists('curl_exec') || Environment::matchesPhpVersion('5.4')) {
            // `curl_exec` doesn't come from an autoloader, if it does not exists we can return this integration as
            // not available.
            return Integration::NOT_AVAILABLE;
        }

        $globalConfig = Configuration::get();

        dd_trace('curl_exec', function ($ch) {
            $tracer = GlobalTracer::get();
            $scope = $tracer->startActiveSpan('curl_exec');
            $span = $scope->getSpan();
            $span->setTag(Tag::SERVICE_NAME, 'curl');
            $span->setTag(Tag::SPAN_TYPE, Type::HTTP_CLIENT);

            CurlIntegration::injectDistributedTracingHeaders($ch);

            $result = curl_exec($ch);
            if ($result === false && $span instanceof Span) {
                $span->setRawError(curl_error($ch), 'curl error');
            }

            $info = curl_getinfo($ch);
            $sanitizedUrl = Urls::sanitize($info['url']);
            $span->setTag(Tag::RESOURCE_NAME, $sanitizedUrl);
            $span->setTag(Tag::HTTP_URL, $sanitizedUrl);
            $span->setTag(Tag::HTTP_STATUS_CODE, $info['http_code']);

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
                ArrayKVStore::putForResource($ch, Format::CURL_HTTP_HEADERS, $value);
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
                ArrayKVStore::putForResource($ch, Format::CURL_HTTP_HEADERS, $options[CURLOPT_HTTPHEADER]);
            }

            return curl_setopt_array($ch, $options);
        });

        dd_trace('curl_close', function ($ch) use ($globalConfig) {
            ArrayKVStore::deleteResource($ch);
            return curl_close($ch);
        });

        return Integration::LOADED;
    }

    /**
     * @param resource $ch
     */
    public static function injectDistributedTracingHeaders($ch)
    {
        if (!Configuration::get()->isDistributedTracingEnabled()) {
            return;
        }

        $httpHeaders = ArrayKVStore::getForResource($ch, Format::CURL_HTTP_HEADERS, []);
        if (is_array($httpHeaders)) {
            $tracer = GlobalTracer::get();
            $context = $tracer->getActiveSpan()->getContext();
            $tracer->inject($context, Format::CURL_HTTP_HEADERS, $httpHeaders);

            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        }
    }
}
