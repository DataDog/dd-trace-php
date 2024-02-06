<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\HookData;
use DDTrace\Http\Urls;
use DDTrace\Integrations\HttpClientIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

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
                $integration->setup_curl_span($span);

                if (!isset($args[0])) {
                    return;
                }

                $ch = $args[0];
                if (isset($retval) && $retval === false) {
                    $span->meta[Tag::ERROR_MSG] = \curl_error($ch);
                    $span->meta[Tag::ERROR_TYPE] = 'curl error';
                    if (PHP_VERSION_ID >= 70000) {
                        $span->meta[Tag::ERROR_STACK] = \DDTrace\get_sanitized_exception_trace(new \Exception(), 1);
                    }
                }

                CurlIntegration::set_curl_attributes($span, \curl_getinfo($ch));
            },
        ]);

        if (\PHP_MAJOR_VERSION > 5) {
            $lastMh = [0, null];
            \DDTrace\install_hook('curl_multi_exec', function (HookData $hook) use ($integration, &$lastMh) {
                if (\count($hook->args) >= 2) {
                    $data = null;
                    if (\PHP_MAJOR_VERSION > 7) {
                        $data = ObjectKVStore::get($hook->args[0], "span");
                    } elseif ($lastMh[0] == (int)$hook->args[0]) {
                        $data = $lastMh[1];
                        $lastMh = [0, null];
                    }
                    if ($data) {
                        $hook->data = $data;
                        return;
                    }
                }

                $span = $hook->span();
                if (\count($hook->args) >= 2) {
                    \DDTrace\curl_multi_exec_get_request_spans($spans);
                    $hook->data = [$span, &$spans, true];
                    if (\PHP_MAJOR_VERSION > 7) {
                        ObjectKVStore::put($hook->args[0], "span", [$span, &$spans]);
                    }
                }

                $span->name = 'curl_multi_exec';
                $span->resource = 'curl_multi_exec';
                $span->service = "curl";
                $span->type = Type::HTTP_CLIENT;
                Integration::handleInternalSpanServiceName($span, CurlIntegration::NAME);
                $span->meta[Tag::COMPONENT] = CurlIntegration::NAME;
                $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;
            }, function (HookData $hook) use ($integration, &$lastMh) {
                if (empty($hook->data) || $hook->exception) {
                    return;
                }

                $span = $hook->data[0];
                $spans = &$hook->data[1];

                if (!$spans) {
                    // Drop the span if nothing was handled here
                    if (\PHP_MAJOR_VERSION == 8) {
                        ObjectKVStore::put($hook->args[0], "span", null);
                    }
                    return false;
                }

                if ($spans && $spans[0][1]->name != "curl_exec") {
                    foreach ($spans as $requestSpan) {
                        list(, $requestSpan) = $requestSpan;
                        $integration->setup_curl_span($requestSpan);
                    }
                }

                $saveSpans = $hook->args[1];

                if (!$hook->args[1]) {
                    // finished
                    foreach ($spans as $requestSpan) {
                        list($ch, $requestSpan) = $requestSpan;
                        $requestSpan->metrics["_dd.measured"] = 1;
                        $info = curl_getinfo($ch);
                        if (isset($requestSpan->meta['network.destination.name']) && $requestSpan->meta['network.destination.name'] !== 'unparsable-host') {
                            continue;
                        }

                        if (empty($info["http_code"])) {
                            $saveSpans = true;
                            if (!isset($error_trace)) {
                                $error_trace = \DDTrace\get_sanitized_exception_trace(new \Exception(), 1);
                            }
                            if (!isset($requestSpan->meta[Tag::ERROR_MSG])) {
                                $requestSpan->meta[Tag::ERROR_MSG] = "CURL request failure";
                            }
                            $requestSpan->meta[Tag::ERROR_TYPE] = 'curl error';
                            $requestSpan->meta[Tag::ERROR_STACK] = $error_trace;
                        }
                        CurlIntegration::set_curl_attributes($requestSpan, $info);
                        if (isset($info["total_time"])) {
                            $endTime = $info["total_time"] + $requestSpan->getStartTime() / 1e9;
                            \DDTrace\update_span_duration($requestSpan, $endTime);
                        }
                    }
                }

                // If there's an error we retain it for a possible future curl_multi_info_read
                if ($saveSpans) {
                    if (\PHP_MAJOR_VERSION == 7) {
                        $lastMh = [(int)$hook->args[0], [$span, &$spans]];
                    }
                } elseif (\PHP_MAJOR_VERSION == 8) {
                    ObjectKVStore::put($hook->args[0], "span", null);
                }

                if (!isset($hook->data[2])) {
                    \DDTrace\update_span_duration($span);
                }

                if ($hook->returned != CURLM_OK) {
                    if (!isset($error_trace)) {
                        $error_trace = \DDTrace\get_sanitized_exception_trace(new \Exception(), 1);
                    }
                    $requestSpan->meta[Tag::ERROR_MSG] = curl_multi_strerror($hook->returned);
                    $requestSpan->meta[Tag::ERROR_TYPE] = 'curl_multi error';
                    $requestSpan->meta[Tag::ERROR_STACK] = $error_trace;
                }
            });

            \DDTrace\install_hook('curl_multi_info_read', null, function (HookData $hook) use (&$lastMh) {
                if (count($hook->args) < 1 || !isset($hook->returned["handle"])) {
                    return;
                }

                $handle = $hook->returned["handle"];

                if (\PHP_MAJOR_VERSION > 7) {
                    $data = ObjectKVStore::get($hook->args[0], "span");
                } elseif ($lastMh[0] == (int)$hook->args[0]) {
                    $data = $lastMh[1];
                } else {
                    $data = null;
                }

                list(, $spans) = $data;

                if (!isset($hook->returned["result"]) || $hook->returned["result"] == CURLE_OK) {
                    if (empty($spans)) {
                        return;
                    }

                    foreach ($spans as $requestSpan) {
                        list($ch, $requestSpan) = $requestSpan;
                        if ($ch === $handle) {
                            if (isset($requestSpan->meta['network.destination.name']) && $requestSpan->meta['network.destination.name'] !== 'unparsable-host') {
                                continue;
                            }
                            $info = curl_getinfo($ch);
                            $errorMsg = curl_strerror($hook->returned["result"]);
                            if (empty($info['http_code']) && $errorMsg !== 'No error') {
                                $error_trace = \DDTrace\get_sanitized_exception_trace(new \Exception(), 1);
                                if (!isset($requestSpan->meta[Tag::ERROR_MSG])) {
                                    $requestSpan->meta[Tag::ERROR_MSG] = $errorMsg;
                                }
                                $requestSpan->meta[Tag::ERROR_TYPE] = 'curl error';
                                $requestSpan->meta[Tag::ERROR_STACK] = $error_trace;
                            }
                            CurlIntegration::set_curl_attributes($requestSpan, $info);
                            if (isset($info["total_time"])) {
                                $endTime = $info["total_time"] + $requestSpan->getStartTime() / 1e9;
                                \DDTrace\update_span_duration($requestSpan, $endTime);
                            }
                        }
                    }
                    return;
                }

                foreach ($spans as $requestSpan) {
                    list($ch, $requestSpan) = $requestSpan;
                    if ($ch === $handle) {
                        $requestSpan->meta[Tag::ERROR_MSG] = curl_strerror($hook->returned["result"]);
                        $info = curl_getinfo($ch);

                        if (isset($requestSpan->meta[Tag::NETWORK_DESTINATION_NAME])
                            && 'unparsable-host' !== $requestSpan->meta[Tag::NETWORK_DESTINATION_NAME]) {
                            continue;
                        }
                        $requestSpan->meta[Tag::ERROR_TYPE] = 'curl error';
                        $requestSpan->meta[Tag::ERROR_STACK] = \DDTrace\get_sanitized_exception_trace(new \Exception(), 1);
                        CurlIntegration::set_curl_attributes($requestSpan, $info);
                        if (isset($info["total_time"])) {
                            $endTime = $info["total_time"] + $requestSpan->getStartTime() / 1e9;
                            \DDTrace\update_span_duration($requestSpan, $endTime);
                        }
                    }
                }
            });
        }

        return Integration::LOADED;
    }

    public function setup_curl_span($span) {
        $span->name = $span->resource = 'curl_exec';
        $span->type = Type::HTTP_CLIENT;
        $span->service = 'curl';
        Integration::handleInternalSpanServiceName($span, CurlIntegration::NAME);
        $this->addTraceAnalyticsIfEnabled($span);
        $span->meta[Tag::COMPONENT] = CurlIntegration::NAME;
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
    }

    public static function set_curl_attributes($span, $info) {
        $sanitizedUrl = \DDTrace\Util\Normalizer::urlSanitize($info['url']);
        $normalizedPath = \DDTrace\Util\Normalizer::uriNormalizeOutgoingPath($info['url']);
        $host = Urls::hostname($sanitizedUrl);
        $span->meta[Tag::NETWORK_DESTINATION_NAME] = $host;
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

        if (\PHP_MAJOR_VERSION > 5) {
            $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;
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

        return $info;
    }
}
