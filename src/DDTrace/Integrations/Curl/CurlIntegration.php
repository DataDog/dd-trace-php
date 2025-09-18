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
use function DDTrace\resource_weak_get;
use function DDTrace\resource_weak_store;
use function DDTrace\start_span;

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

class CurlSpanInfo {
    public $span;
    public $spans;

    public function __destruct() {
        $stack = \DDTrace\active_stack();
        \DDTrace\switch_stack($this->span);
        \DDTrace\close_span();
        \DDTrace\switch_stack($stack);
    }
}

final class CurlIntegration extends Integration
{
    const NAME = 'curl';

    public static function init(): int
    {
        if (!extension_loaded('curl')) {
            return Integration::NOT_AVAILABLE;
        }

        \DDTrace\trace_function('curl_exec', [
            // the ddtrace extension will handle distributed headers
            'instrument_when_limited' => 0,
            'posthook' => static function (SpanData $span, $args, $retval) {
                self::setup_curl_span($span);

                if (!isset($args[0])) {
                    return;
                }

                $ch = $args[0];
                if (isset($retval) && $retval === false) {
                    $span->meta[Tag::ERROR_MSG] = \curl_error($ch);
                    $span->meta[Tag::ERROR_TYPE] = 'curl error';
                    $span->meta[Tag::ERROR_STACK] = \DDTrace\get_sanitized_exception_trace(new \Exception, 1);
                }

                self::set_curl_attributes($span, \curl_getinfo($ch));
            },
        ]);

        \DDTrace\install_hook('curl_multi_exec', static function (HookData $hook) {
            if (\count($hook->args) < 2) {
                return;
            }
            $data = null;
            if (\PHP_MAJOR_VERSION > 7) {
                $data = ObjectKVStore::get($hook->args[0], "span");
            } else {
                $data = resource_weak_get($hook->args[0], "span");
            }
            if ($data) {
                $hook->data = $data;
                return;
            }

            \DDTrace\create_stack();
            $span = start_span();

            $spanInfo = new CurlSpanInfo;
            $spanInfo->span = $span;
            $spanInfo->spans = &\DDTrace\curl_multi_exec_get_request_spans();
            $hook->data = $spanInfo;
            if (\PHP_MAJOR_VERSION > 7) {
                ObjectKVStore::put($hook->args[0], "span", $spanInfo);
            } else {
                resource_weak_store($hook->args[0], "span", $spanInfo);
            }

            $span->name = 'curl_multi_exec';
            $span->resource = 'curl_multi_exec';
            $span->service = "curl";
            $span->type = Type::HTTP_CLIENT;
            Integration::handleInternalSpanServiceName($span, self::NAME);
            $span->meta[Tag::COMPONENT] = self::NAME;
            $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;

            \DDTrace\collect_code_origins(1);
        }, static function (HookData $hook) {
            if (empty($hook->data) || $hook->exception) {
                return;
            }

            $spanInfo = $hook->data;
            $spans = $spanInfo->spans;

            if (\DDTrace\active_span() === $spanInfo->span) {
                \DDTrace\switch_stack();
            }

            if (!$spans) {
                // Drop the span if nothing was handled here
                \DDTrace\try_drop_span($spanInfo->span);
                if (\PHP_MAJOR_VERSION > 7) {
                    ObjectKVStore::put($hook->args[0], "span", null);
                } else {
                    resource_weak_store($hook->args[0], "span", null);
                }
                return;
            }

            if ($spans && $spans[0][1]->name != "curl_exec") {
                foreach ($spans as $requestSpan) {
                    list(, $requestSpan) = $requestSpan;
                    self::setup_curl_span($requestSpan);
                }
            }

            $saveSpans = $hook->args[1];

            if (!$hook->args[1]) {
                // finished
                foreach ($spans as $requestSpan) {
                    list($ch, $requestSpan) = $requestSpan;
                    $requestSpan->metrics["_dd.measured"] = 1;
                    $info = curl_getinfo($ch);
                    if (empty($info["http_code"])) {
                        $saveSpans = true;
                    }

                    if (isset($requestSpan->meta[Tag::NETWORK_DESTINATION_NAME]) && $requestSpan->meta[Tag::NETWORK_DESTINATION_NAME] !== 'unparsable-host') {
                        continue;
                    }

                    if (empty($info["http_code"])) {
                        if (!isset($error_trace)) {
                            $error_trace = \DDTrace\get_sanitized_exception_trace(new \Exception(), 1);
                        }
                        if (!isset($requestSpan->meta[Tag::ERROR_MSG])) {
                            $requestSpan->meta[Tag::ERROR_MSG] = "CURL request failure";
                        }
                        $requestSpan->meta[Tag::ERROR_TYPE] = 'curl error';
                        $requestSpan->meta[Tag::ERROR_STACK] = $error_trace;
                    }
                    self::set_curl_attributes($requestSpan, $info);
                    if (isset($info["total_time"])) {
                        $endTime = $info["total_time"] + $requestSpan->getStartTime() / 1e9;
                        \DDTrace\update_span_duration($requestSpan, $endTime);
                    }
                }
            }

            // If there's an error we retain it for a possible future curl_multi_info_read
            if (!$saveSpans) {
                if (\PHP_MAJOR_VERSION > 7) {
                    ObjectKVStore::put($hook->args[0], "span", null);
                } else {
                    resource_weak_store($hook->args[0], "span", null);
                }
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

        \DDTrace\install_hook('curl_multi_info_read', null, static function (HookData $hook) {
            if (count($hook->args) < 1 || !isset($hook->returned["handle"])) {
                return;
            }

            $handle = $hook->returned["handle"];

            if (\PHP_MAJOR_VERSION > 7) {
                $spanInfo = ObjectKVStore::get($hook->args[0], "span");
            } else {
                $spanInfo = resource_weak_get($hook->args[0], "span");
            }

            if (!$spanInfo || !$spanInfo->spans) {
                return;
            }
            $spans = $spanInfo->spans;

            if (!isset($hook->returned["result"]) || $hook->returned["result"] == CURLE_OK) {
                foreach ($spans as $requestSpan) {
                    list($ch, $requestSpan) = $requestSpan;
                    if ($ch === $handle) {
                        if (isset($requestSpan->meta[Tag::NETWORK_DESTINATION_NAME]) && $requestSpan->meta[Tag::NETWORK_DESTINATION_NAME] !== 'unparsable-host') {
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
                        self::set_curl_attributes($requestSpan, $info);
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
                    self::set_curl_attributes($requestSpan, $info);
                    if (isset($info["total_time"])) {
                        $endTime = $info["total_time"] + $requestSpan->getStartTime() / 1e9;
                        \DDTrace\update_span_duration($requestSpan, $endTime);
                    }
                }
            }
        });

        return Integration::LOADED;
    }

    public static function setup_curl_span($span) {
        $span->name = $span->resource = 'curl_exec';
        $span->type = Type::HTTP_CLIENT;
        $span->service = 'curl';
        Integration::handleInternalSpanServiceName($span, self::NAME);
        self::addTraceAnalyticsIfEnabled($span);
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
    }

    public static function set_curl_attributes($span, $info) {
        $sanitizedUrl = \DDTrace\Util\Normalizer::urlSanitize($info['url']);
        $normalizedPath = \DDTrace\Util\Normalizer::uriNormalizeOutgoingPath($info['url']);
        $host = Urls::hostname($sanitizedUrl);
        $span->meta[Tag::NETWORK_DESTINATION_NAME] = $host;
        unset($info['url']);

        if (\dd_trace_env_config("DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN")) {
            $span->service = Urls::hostnameForTag($sanitizedUrl);
        }

        $span->resource = $normalizedPath;

        /* Special case the Datadog Standard Attributes
         * See https://docs.datadoghq.com/logs/processing/attributes_naming_convention/
         */
        if (!array_key_exists(Tag::HTTP_URL, $span->meta)) {
            $span->meta[Tag::HTTP_URL] = $sanitizedUrl;
        }

        $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;

        addSpanDataTagFromCurlInfo($span, $info, Tag::HTTP_STATUS_CODE, 'http_code');

        // Mark as an error if needed based on configuration
        if (isset($info['http_code']) && !empty($info['http_code'])) {
            $statusCode = (int)$info['http_code'];
            HttpClientIntegrationHelper::setClientError($span, $statusCode);
        }

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
