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

/**
 * @param \DDTrace\Span $span
 * @param string $tagName
 * @param mixed $info
 */
function addTagFromCurlInfo($span, &$info, $tagName, $curlInfoOpt)
{
    if (isset($info[$curlInfoOpt]) && !\trim($info[$curlInfoOpt]) !== '') {
        $span->setTag($tagName, $info[$curlInfoOpt]);
        unset($info[$curlInfoOpt]);
    }
}

/**
 * Integration for curl php client.
 */
class CurlIntegration extends Integration
{
    const NAME = 'curl';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Loads the integration.
     */
    public static function load()
    {
        if (!extension_loaded('curl')) {
            // `curl` extension is not loaded, if it does not exists we can return this integration as
            // not available.
            return Integration::NOT_AVAILABLE;
        }

        // Waiting for refactoring from static to singleton.
        $integration = new self();

        dd_trace('curl_exec', [
            'instrument_when_limited' => 1,
            'innerhook' => function ($ch) use ($integration) {
                $tracer = GlobalTracer::get();

                if ($tracer->limited()) {
                    CurlIntegration::injectDistributedTracingHeaders($ch);
                    return dd_trace_forward_call();
                }

                $scope = $tracer->startIntegrationScopeAndSpan($integration, 'curl_exec');
                $span = $scope->getSpan();
                $span->setTraceAnalyticsCandidate();
                $span->setTag(Tag::SPAN_TYPE, Type::HTTP_CLIENT);
                CurlIntegration::injectDistributedTracingHeaders($ch);

                $result = dd_trace_forward_call();
                if ($result === false && $span instanceof Span) {
                    $span->setRawError(curl_error($ch), 'curl error');
                }

                $info = curl_getinfo($ch);
                $sanitizedUrl = Urls::sanitize($info['url']);
                unset($info['url']);

                if (Configuration::get()->isHttpClientSplitByDomain()) {
                    $span->setTag(Tag::SERVICE_NAME, Urls::hostnameForTag($sanitizedUrl));
                } else {
                    $span->setTag(Tag::SERVICE_NAME, 'curl');
                }
                $span->setTag(Tag::RESOURCE_NAME, $sanitizedUrl);


                // Special case the Datadog Standard Attributes
                //  https://docs.datadoghq.com/logs/processing/attributes_naming_convention/

                $span->setTag(Tag::HTTP_URL, $sanitizedUrl);

                addTagFromCurlInfo($span, $info, Tag::HTTP_STATUS_CODE, 'http_code');

                // Datadog sets durations in nanoseconds - convert from seconds
                $span->setTag('duration', $info['total_time'] * 1000000000);
                unset($info['duration']);

                addTagFromCurlInfo($span, $info, 'network.client.ip', 'local_ip');
                addTagFromCurlInfo($span, $info, 'network.client.port', 'local_port');

                addTagFromCurlInfo($span, $info, 'network.destination.ip', 'primary_ip');
                addTagFromCurlInfo($span, $info, 'network.destination.port', 'primary_port');

                addTagFromCurlInfo($span, $info, 'network.bytes_read', 'size_download');
                addTagFromCurlInfo($span, $info, 'network.bytes_written', 'size_upload');


                // Add the rest to a 'curl.' object
                foreach ($info as $key => $val) {
                    // Datadog doesn't support arrays in tags
                    if (\is_scalar($val) && $val !== '') {
                        // Datadog sets durations in nanoseconds - convert from seconds
                        if (\substr_compare($key, '_time', -5) === 0) {
                            $val *= 1000000000;
                        }
                        $span->setTag("curl.{$key}", $val);
                    }
                }

                $scope->close();
                return $result;
            }
        ]);

        dd_trace('curl_setopt', [
            'instrument_when_limited' => 1,
            'innerhook' => function ($ch, $option, $value) {
                // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
                // multiple times on the same resource.
                if (
                    $option === CURLOPT_HTTPHEADER
                    && Configuration::get()->isDistributedTracingEnabled()
                    && is_array($value)
                ) {
                    // Storing data to be used during exec as it cannot be retrieved at then.
                    ArrayKVStore::putForResource($ch, Format::CURL_HTTP_HEADERS, $value);
                }

                return dd_trace_forward_call();
            }
        ]);

        dd_trace('curl_setopt_array', [
            'instrument_when_limited' => 1,
            'innerhook' => function ($ch, $options) {
                // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
                // multiple times on the same resource.
                if (
                    Configuration::get()->isDistributedTracingEnabled()
                    && array_key_exists(CURLOPT_HTTPHEADER, $options)
                ) {
                    // Storing data to be used during exec as it cannot be retrieved at then.
                    ArrayKVStore::putForResource($ch, Format::CURL_HTTP_HEADERS, $options[CURLOPT_HTTPHEADER]);
                }

                return dd_trace_forward_call();
            }
        ]);

        dd_trace('curl_copy_handle', [
            'instrument_when_limited' => 1,
            'innerhook' => function ($ch1) {
                $ch2 = dd_trace_forward_call();
                /* The store needs to copy the CURLOPT_HTTPHEADER value to the new handle;
                 * see https://github.com/DataDog/dd-trace-php/issues/502 */
                if (\is_resource($ch2) && Configuration::get()->isDistributedTracingEnabled()) {
                    $httpHeaders = ArrayKVStore::getForResource($ch1, Format::CURL_HTTP_HEADERS, []);
                    if (\is_array($httpHeaders)) {
                        ArrayKVStore::putForResource($ch2, Format::CURL_HTTP_HEADERS, $httpHeaders);
                    }
                }
                return $ch2;
            }
        ]);

        dd_trace('curl_close', [
            'instrument_when_limited' => 1,
            'innerhook' => function ($ch) {
                ArrayKVStore::deleteResource($ch);
                return dd_trace_forward_call();
            }
        ]);

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
            $activeSpan = $tracer->getActiveSpan();
            if ($activeSpan !== null) {
                $context = $activeSpan->getContext();
                $tracer->inject($context, Format::CURL_HTTP_HEADERS, $httpHeaders);

                curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
            }
        }
    }
}
