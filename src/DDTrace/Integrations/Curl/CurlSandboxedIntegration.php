<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Format;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ArrayKVStore;

class CurlSandboxedIntegration extends SandboxedIntegration
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
     * Add instrumentation to PDO requests
     */
    public function init()
    {
        if (!extension_loaded('curl')) {
            // `curl` extension is not loaded, if it does not exists we can return this integration as
            // not available.
            return Integration::NOT_AVAILABLE;
        }

        $globalConfig = Configuration::get();

        dd_trace_function('curl_exec', function (SpanData $span, $args, $result) use ($globalConfig) {
            list($ch) = $args;
            $span->name = 'curl_exec';
            $span->type = Type::HTTP_CLIENT;
            CurlCommon::injectDistributedTracingHeaders($ch);
            if ($result === false) {
                //$span->setRawError(curl_error($ch), 'curl error');
                $span->meta[Tag::ERROR_MSG] = curl_error($ch);
                $span->meta[Tag::ERROR_TYPE] = 'curl error';
            }
            $info = curl_getinfo($ch);
            $sanitizedUrl = Urls::sanitize($info['url']);
            if ($globalConfig->isHttpClientSplitByDomain()) {
                $span->service = Urls::hostnameForTag($sanitizedUrl);
            } else {
                $span->service ='curl';
            }



            $span->resource = $sanitizedUrl;
            $span->meta[Tag::HTTP_URL] = $sanitizedUrl;
            $span->meta[Tag::HTTP_STATUS_CODE] = (string)$info['http_code'];
        });

        dd_trace_function('curl_setopt', function (SpanData $span, $args) use ($globalConfig) {
            list($ch, $option, $value) = $args;
            // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
            // multiple times on the same resource.
            if ($option === CURLOPT_HTTPHEADER
                && $globalConfig->isDistributedTracingEnabled()
                && is_array($value)
            ) {
                // Storing data to be used during exec as it cannot be retrieved at then.
                ArrayKVStore::putForResource($ch, Format::CURL_HTTP_HEADERS, $value);
            }
        });

        dd_trace_function('curl_setopt_array', function (SpanData $span, $args) use ($globalConfig) {
            list($ch, $options) = $args;
            // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
            // multiple times on the same resource.
            if ($globalConfig->isDistributedTracingEnabled()
                && array_key_exists(CURLOPT_HTTPHEADER, $options)
            ) {
                // Storing data to be used during exec as it cannot be retrieved at then.
                ArrayKVStore::putForResource($ch, Format::CURL_HTTP_HEADERS, $options[CURLOPT_HTTPHEADER]);
            }
        });

        dd_trace_function('curl_copy_handle', function (SpanData $span, $args, $result) use ($globalConfig) {
            $ch1 = $args[0];
            $ch2 = $result;

            /* The store needs to copy the CURLOPT_HTTPHEADER value to the new handle;
             * see https://github.com/DataDog/dd-trace-php/issues/502 */
            if (\is_resource($ch2) && $globalConfig->isDistributedTracingEnabled()) {
                $httpHeaders = ArrayKVStore::getForResource($ch1, Format::CURL_HTTP_HEADERS, []);
                if (\is_array($httpHeaders)) {
                    ArrayKVStore::putForResource($ch2, Format::CURL_HTTP_HEADERS, $httpHeaders);
                }
            }
        });

        dd_trace_function('curl_close', function (SpanData $span, $args) use ($globalConfig) {
            list($ch) = $args;
            ArrayKVStore::deleteResource($ch);
        });

        return Integration::LOADED;
    }
}
