<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

/**
 * @param \DDTrace\SpanData $span
 * @param array &$info
 * @param string $tagName
 * @param mixed $curlInfoOpt
 */
function addSpanDataTagFromCurlInfo($span, &$info, $tagName, $curlInfoOpt)
{
    if (isset($info[$curlInfoOpt]) && !\trim($info[$curlInfoOpt]) !== '') {
        $span->meta[$tagName] = $info[$curlInfoOpt];
        unset($info[$curlInfoOpt]);
    }
}

final class CurlIntegration extends Integration
{
    const NAME = 'curl';

    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!extension_loaded('curl')) {
            return Integration::NOT_AVAILABLE;
        }

        if (!Integration::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;

        \DDTrace\trace_function('curl_exec', [
            // the ddtrace extension will handle distributed headers
            'instrument_when_limited' => 0,
            'posthook' => function (SpanData $span, $args, $retval) use ($integration) {
                $span->name = $span->resource = 'curl_exec';
                $span->type = Type::HTTP_CLIENT;
                $span->service = 'curl';
                $integration->addTraceAnalyticsIfEnabled($span);
                $span->meta[Tag::COMPONENT] = $this->getName();

                if (!isset($args[0])) {
                    return;
                }

                $ch = $args[0];
                if (isset($retval) && $retval === false) {
                    $span->meta[Tag::ERROR_MSG] = \curl_error($ch);
                    $span->meta[Tag::ERROR_TYPE] = 'curl error';
                }

                $info = \curl_getinfo($ch);
                $sanitizedUrl = \DDTrace\Util\Normalizer::urlSanitize($info['url']);
                $normalizedPath = \DDTrace\Util\Normalizer::uriNormalizeOutgoingPath($info['url']);
                unset($info['url']);

                if (\DDTrace\Util\Runtime::getBoolIni("datadog.trace.http_client_split_by_domain")) {
                    $span->service = Urls::hostnameForTag($sanitizedUrl);
                }

                $span->resource = $normalizedPath;

                /* Special case the Datadog Standard Attributes
                 * See https://docs.datadoghq.com/logs/processing/attributes_naming_convention/
                 */
                if (!array_key_exists(Tag::HTTP_URL, $span->meta)) {
                    $span->meta[Tag::HTTP_URL] = $sanitizedUrl;
                }

                addSpanDataTagFromCurlInfo($span, $info, Tag::HTTP_STATUS_CODE, 'http_code');

                addSpanDataTagFromCurlInfo($span, $info, 'network.client.ip', 'local_ip');
                addSpanDataTagFromCurlInfo($span, $info, 'network.client.port', 'local_port');

                addSpanDataTagFromCurlInfo($span, $info, 'network.destination.ip', 'primary_ip');
                addSpanDataTagFromCurlInfo($span, $info, 'network.destination.port', 'primary_port');

                addSpanDataTagFromCurlInfo($span, $info, 'network.bytes_read', 'size_download');
                addSpanDataTagFromCurlInfo($span, $info, 'network.bytes_written', 'size_upload');

                // Add the rest to a 'curl.' object
                foreach ($info as $key => $val) {
                    // Datadog doesn't support arrays in tags
                    if (\is_scalar($val) && $val !== '') {
                        // Datadog sets durations in nanoseconds - convert from seconds
                        if (\substr_compare($key, '_time', -5) === 0) {
                            $val *= 1000000000;
                        }
                        $span->meta["curl.{$key}"] = $val;
                    }
                }
            },
        ]);

        return Integration::LOADED;
    }
}
