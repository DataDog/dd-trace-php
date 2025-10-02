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
    /** @var \DDTrace\SpanData */
    public $span;
    public $spans;

    public function __destruct() {
        // Explicitly check for duration to avoid closing already destroyed spans in garbage collection
        if (isset($this->span) && $this->span->getDuration() === 0) {
            $stack = \DDTrace\active_stack();
            \DDTrace\switch_stack($this->span);
            \DDTrace\close_span();
            \DDTrace\switch_stack($stack);
        }
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
                unset($spanInfo->span);
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

        if (function_exists('datadog\\appsec\\is_fully_disabled') &&
            !\datadog\appsec\is_fully_disabled()) {


            \DDtrace\install_hook('curl_setopt',
                static function (HookData $hook) {
                    if (count($hook->args) < 3) {
                        return;
                    }

                    /**
                     * @var resource|\CurlHandle $ch
                     * @var int $option
                     */
                    list($ch, $option, $value) = $hook->args;
                    $ctx = AppSecContext::get($ch);

                    static $STREAM_OPTIONS = array(
                        CURLOPT_INFILE => null,
                        CURLOPT_FILE => null,
                        CURLOPT_WRITEHEADER => null,
                    );

                    if (key_exists($option, $STREAM_OPTIONS)
                        && \is_resource($value) && \get_resource_type($value) === 'stream'
                        // the curl extension does a cast to FILE*; we need to filter the stream, so this requires
                        // fopencookie/funopen, not available on windows
                        && !self::isWindows()) {
                        $body = new CurlFilteredStreamBody(
                            $ctx,
                            $option === CURLOPT_WRITEHEADER ? 0 /* unlimited */ : null /* default */
                        );
                        $filter = $body->filterStream(
                            $value,
                            $option === CURLOPT_INFILE ? STREAM_FILTER_READ : STREAM_FILTER_WRITE
                        );
                        if ($filter) {
                            $cancel = static function () use ($filter) {
                                stream_filter_remove($filter);
                            };
                            if ($option === CURLOPT_INFILE) {
                                $hook->data = $ctx->tentativeSetRequestBody($body, $cancel);
                            } elseif ($option === CURLOPT_WRITEHEADER) {
                                $hook->data = $ctx->tentativeSetResponseHeaders($body);
                            }
                        }
                    } elseif (key_exists($option, $STREAM_OPTIONS) && $value === null) {
                        if ($option === CURLOPT_INFILE) {
                            $hook->data = $ctx->tentativeSetRequestBody(null);
                        } elseif ($option === CURLOPT_FILE) {
                            $hook->data = $ctx->tentativeSetResponseBody(null);
                        } elseif ($option === CURLOPT_WRITEHEADER) {
                            $hook->data = $ctx->tentativeSetResponseHeaders(null);
                        }
                    } elseif ($option === CURLOPT_READFUNCTION) {
                        if (\is_callable($value)) {
                            $body = new CurlCallableBody($ctx, $value);
                            $hook->overrideArguments(array($ch, $option, $body));
                            $hook->data = $ctx->tentativeSetRequestBody($body);
                        } else {
                            $hook->data = $ctx->tentativeSetRequestBody(null);
                        }
                    } elseif ($option === CURLOPT_WRITEFUNCTION) {
                        if (\is_callable($value)) {
                            $body = new CurlCallableBody($ctx, $value);
                            $hook->overrideArguments(array($ch, $option, $body));
                            $hook->data = $ctx->tentativeSetResponseBody($body);
                        } else {
                            $hook->data = $ctx->tentativeSetResponseBody(null);
                        }
                    } elseif ($option === CURLOPT_POSTFIELDS) {
                        if (is_array($value)) {
                            if (empty($value)) {
                                $hook->data = $ctx->tentativeSetRequestBody(new CurlEmptyBody($ctx));
                            } else {
                                $hook->data = $ctx->tentativeSetRequestBody(new CurlArrayBody($ctx, $value));
                            }
                        } else {
                            $strValue = (string)$value;
                            $body = new CurlStringBody($ctx, $strValue);
                            $hook->data = $ctx->tentativeSetRequestBody($body);
                        }
                    } elseif ($option === CURLOPT_HTTPHEADER && is_array($value)) {
                        $hook->data = $ctx->tentativeSetRequestHeaders($value);
                    }
                },
                static function (HookData $hook) {
                    if ($hook->data instanceof CommitableChange) {
                        if ($hook->returned === true) {
                            $hook->data->commit();
                        } else {
                            $hook->data->cancel();
                        }
                    }
                }
            );
        }

        return Integration::LOADED;
    }

    public static function isWindows() : bool
    {
        return \strncasecmp(PHP_OS, 'WIN', 3) === 0;
    }

    public static function setup_curl_span($span)
    {
        $span->name = $span->resource = 'curl_exec';
        $span->type = Type::HTTP_CLIENT;
        $span->service = 'curl';
        Integration::handleInternalSpanServiceName($span, self::NAME);
        self::addTraceAnalyticsIfEnabled($span);
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
    }

    public static function set_curl_attributes($span, $info)
    {
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


abstract class CurlBody {
    /** @var AppSecContext the context associated with this body */
    protected $appSecContext;

    public function __construct(AppSecContext $ctx)
    {
        $this->appSecContext = $ctx;
    }

    // body can be sent to appsec
    // 1. on execution
    // 2. on read callback, after all data has been read
    // we need to distinguish between these two cases
    abstract public function isReady() : bool;

    /**
     * @param callable $transform a function that takes the raw body string and content type,
     *                            and returns the processed body content
     * @param string $contentType the content type of the body
     * @return array|null the processed body content, if available
     */
    abstract public function getContent(callable $transform, string $contentType);

    protected static function defaultSizeLimit() : int
    {
        return (int)(\dd_trace_env_config('DD_APPSEC_MAX_BODY_BUFF_SIZE') ?: 524288);
    }
}

class CurlStreamBody extends CurlBody
{
    /**
     * @var resource the stream associated with this body
     */
    private $stream;

    public function __construct(AppSecContext $ctx)
    {
        parent::__construct($ctx);
        $this->stream = fopen('php://temp', 'r+');
    }

    public function getStream()
    {
        return $this->stream;
    }

    public function isReady(): bool
    {
        throw new \Exception("not implemented");
    }

    public function getContent(callable $transform, string $contentType)
    {
        fseek($this->stream, 0);
        $str = stream_get_contents($this->stream);
        if (!empty($str)) {
            return $transform($str);
        }
        return null;
    }
}

class CurlFilteredStreamBody extends CurlBody
{
    /**
     * @var string|null|false the buffered request body (null if no data; false if invalidated)
     */
    private $reqBodyStr = null;

    /**
     * @var bool if we've accumulated enough data already
     */
    private $finishedReceivingData = false;

    /**
     * @var int the maximum size of the body buffer, or 0 for none
     */
    private $maxBodyBuffSize;

    public function __construct(AppSecContext $ctx, int $maxBodyBuffSize = null)
    {
        parent::__construct($ctx);
        if ($maxBodyBuffSize === null) {
            $this->maxBodyBuffSize = parent::defaultSizeLimit();
        } else {
            $this->maxBodyBuffSize = $maxBodyBuffSize;
        }
    }

    /**
     * @param $stream
     * @param int $direction either STREAM_FILTER_READ or STREAM_FILTER_WRITE
     * @return false|resource
     */
    public function filterStream($stream, int $direction)
    {
        $res = stream_filter_append(
            $stream,
            'ddappsec.read_spy',
            $direction,
            array('curl_stream_body' => $this)
        );

        if ($res === false) {
            $this->reqBodyStr = false;
        }
        return $res;
    }

    public function isReady(): bool
    {
        return is_string($this->reqBodyStr) && $this->finishedReceivingData;
    }

    public function getContent(callable $transform, string $contentType)
    {
        if (!is_string($this->reqBodyStr)) {
            return null;
        }
        return $transform($this->reqBodyStr, $contentType);
    }

    public function reqBodyFilterAppend($data)
    {
        if ($this->bodyWasInvalidated()) {
            return;
        }

        if ($this->reqBodyStr === null) {
            $this->reqBodyStr = '';
        }

        $current_len = strlen($this->reqBodyStr);
        $max_len = $this->maxBodyBuffSize ?? PHP_INT_MAX;

        $left = $max_len - $current_len;
        if ($left <= 0) {
            return;
        }

        $data_len = strlen($data);
        if ($data_len > $left) {
            $data = substr($data, 0, $left);
        }

        $this->reqBodyStr .= $data;

        if (strlen($this->reqBodyStr) >= $max_len) {
            $this->markHasAllData();
        }
    }

    public function reqBodyInvalidate()
    {
        $this->reqBodyStr = false;
    }

    public function markHasAllData()
    {
        if ($this->finishedReceivingData) {
            return;
        }
        $this->finishedReceivingData = true;
        $this->appSecContext->notifyReqBody();
    }

    private function bodyWasInvalidated() : bool
    {
        return $this->reqBodyStr === false;
    }
}

class CurlCallableBody extends CurlBody
{
    /**
     * @var callable the downstream callable
     */
    private $downstream;

    /**
     * @var string|null the accumulated data, or null if nothing gotten
     */
    private $buffer;

    /**
     * @var bool if we've accumulated enough data already. More data will be ignored
     */
    private $finishedReceivingData = false;

    /**
     * @var int the maximum size of the body buffer, or 0 for none
     */
    private $maxBodyBuffSize;

    public function __construct(AppSecContext  $ctx, callable $downstream = null, int $maxBodyBuffSize = null)
    {
        parent::__construct($ctx);
        $this->downstream = $downstream ?? function ($ch, $data) {
            return strlen($data);
        };
        if ($maxBodyBuffSize === null) {
            $this->maxBodyBuffSize = self::defaultSizeLimit();
        } else {
            $this->maxBodyBuffSize = $maxBodyBuffSize;
        }
    }

    public function __invoke($ch, $data)
    {
        if ($this->finishedReceivingData) {
            return ($this->downstream)($ch, $data);
        }

        if ($this->buffer === null) {
            $this->buffer = '';
        }

        $current_len = strlen($this->buffer);
        $max_len = $this->maxBodyBuffSize ?? PHP_INT_MAX;

        $left = $max_len - $current_len;
        if ($left >= 0) {
            $data_len = strlen($data);
            if ($data_len > $left) {
                $data = substr($data, 0, $left);
            }

            $this->buffer .= $data;
        }

        if (strlen($this->buffer) >= $max_len) {
            $this->markHasAllData();
        }

        return ($this->downstream)($ch, $data);
    }

    public function markHasAllData()
    {
        if ($this->finishedReceivingData) {
            return;
        }
        $this->finishedReceivingData = true;
        $this->appSecContext->notifyBody($this);
    }

    public function isReady(): bool
    {
        return $this->finishedReceivingData;
    }

    public function getContent(callable $transform, string $contentType)
    {
        return $transform($this->buffer, $contentType);
    }
}

class CurlStringBody extends CurlBody
{
    /**
     * @var string the body content
     */
    private $body;

    public function __construct(AppSecContext $ctx, string $body, int $limit = null)
    {
        parent::__construct($ctx);
        if ($limit === null) {
            $limit = parent::defaultSizeLimit();
        }
        if ($limit > 0 && strlen($body) > $limit) {
            $body = substr($body, 0, $limit);
        }
        $this->body = $body;
    }

    public function isReady(): bool
    {
        return true;
    }

    public function getContent(callable $transform, string $contentType)
    {
        return $transform($this->body, $contentType);
    }
}

class CurlEmptyBody extends CurlBody
{
    public function __construct(AppSecContext $ctx)
    {
        parent::__construct($ctx);
    }

    public function isReady(): bool
    {
        return true;
    }

    public function getContent(callable $transform, string $contentType) : array
    {
        return array();
    }
}

class CurlArrayBody extends CurlBody
{
    /**
     * @var array the body content in the form
     *            array(key => array('content' => 'string'|array('string', ...), 'mime' => null|'string'), ...)
     */
    private $body;

    /**
     * @var int the maximum size of keys + data (0 for no limit)
     */
    private $limit;

    /**
     * @var int the current size of keys + data
     */
    private $curSize = 0;

    public function __construct(AppSecContext $ctx, array $body, int $limit = null)
    {
        parent::__construct($ctx);
        $this->body = $this->initialContextProcessing($body);
        $this->limit = $limit === null ? parent::defaultSizeLimit() : $limit;
    }

    private function initialContextProcessing(array $body) : array
    {
        $res = array();
        foreach ($body as $key => $value) {
            $key = (string) $key;
            $this->curSize += strlen($key);
            $resValue = array('content' => '', 'mime' => null);

            $left = $this->left();
            if ($left === 0) {
                $res[$key] = $resValue;
                break;
            }

            if ($value instanceof \CurlFile) {
                $file = $value->getFilename();
                $resValue['mime'] = $value->getMimeType();

                // this is slightly dangerous, because it may have side effects, depending on the stream wrapper
                $f = @fopen($file, 'rb');
                if ($f) {
                    $string = @stream_get_contents($f, $left);
                    if ($string !== false) {
                        $resValue['content'] = $string;
                    }
                }
                @fclose($f);
            } elseif (class_exists('CurlStringFile', false) && $value instanceof \CurlStringFile) {
                $resValue['mime'] = $value->mime;

                $str = $value->data;
                if (strlen($str) > $left) {
                    $str = substr($str, 0, $left);
                }
                $resValue['content'] = $str;
            } elseif (is_array($value)) {
                $arrVal = array();
                foreach ($value as $elem) {
                    $elemStr = (string)$elem;
                    $left = $this->left();
                    if ($left === 0) {
                        break;
                    }
                    if (strlen($elemStr) > $left) {
                        $elemStr = substr($elemStr, 0, $left);
                    }
                    $arrVal[] = $elemStr;
                    $this->curSize += strlen($elemStr);
                }
                $resValue['content'] = $arrVal;
            } else {
                $str = (string)$value;
                $resValue['content'] = $str;
            }

            $res[$key] = $resValue;
            if (is_string($resValue['content'])) {
                $this->curSize += strlen($resValue['content']);
            }
        }

        return $res;
    }

    private function left() : int
    {
        return max(0, $this->limit - $this->curSize);
    }

    public function isReady(): bool
    {
        return true;
    }

    public function getContent(callable $transform, string $contentType)
    {
        // $contentType should be multipart
        foreach ($this->body as $key => &$value) {
            if (is_array($value)) {
                // we leave as is
                continue;
            }

            if (!empty($value['mime'])) {
                $value = $transform($value['content'], $value['mime']); // could be null
            } elseif (is_string($value['content'])) {
                $value = $value['content'];
            } else {
                // should not happen
                $value = null;
            }
        }
    }
}

class AppSecContext
{
    /**
     * @var CurlBody|null the request body associated with this context
     */
    private $curlReqBody;

    /**
     * @var array the request headers, with lowercase keys, and values being arrays of strings
     */
    private $requestHeaders = array();

    /**
     * @var CurlBody|null the response body associated with this context
     */
    private $curlRespBody;

    /**
     * @var CurlBody|null the response headers associated with this context (if captured)
     */
    private $curlResponseHeaders;

    /**
     * @var array the parsed response headers (if captured)
     */
    private $parsedResponseHeaders = array();

    /**
     * @param $ch resource|\CurlHandle curl handle (resource on PHP 7; object on PHP 8)
     * @return AppSecContext
     */
    public static function get($ch)
    {
        if (\PHP_MAJOR_VERSION > 7) {
            $cur = ObjectKVStore::get($ch, 'appsec_ctx');
        } else {
            $cur = resource_weak_get($ch, 'appsec_ctx');
        }
        if ($cur === null) {
            $ctx = new AppSecContext();
            self::put($ch, $ctx);
            $cur = $ctx;
        }
        return $cur;
    }

    public static function put($ch, AppSecContext $ctx)
    {
        if (\PHP_MAJOR_VERSION > 7) {
            ObjectKVStore::put($ch, 'appsec_ctx', $ctx);
        } else {
            resource_weak_store($ch, 'appsec_ctx', $ctx);
        }
    }

    public function tentativeSetRequestBody(CurlBody $curlBody = null, $cancel = null) : CommitableChange
    {
        return new CommitableChange(function () use ($curlBody) {
            $this->curlReqBody = $curlBody;
        },
            $cancel);
    }

    public function tentativeSetResponseBody(CurlBody $curlBody = null, $cancel = null) : CommitableChange
    {
        return new CommitableChange(function () use ($curlBody) {
            $this->curlRespBody = $curlBody;
        },
            $cancel);
    }

    public function tentativeSetRequestHeaders(array $headerLines) : CommitableChange
    {
        return new CommitableChange(
            function () use ($headerLines) {
                $res = array();
                foreach ($headerLines as $line) {
                    $this->handleSingleHeaderLine($line, $res);
                }
                $this->requestHeaders = $res;
            }
        );
    }

    public function tentativeSetResponseHeaders(CurlBody $curlBody = null, $cancel = null) : CommitableChange
    {
        return new CommitableChange(function () use ($curlBody) {
            $this->curlResponseHeaders = $curlBody;
        },
            $cancel);
    }

    public function notifyBody(CurlBody $body)
    {
        if ($body === $this->curlReqBody) {
            $type = 'request';
            $headers = $this->requestHeaders;
        } elseif ($body === $this->curlRespBody) {
            $type = 'response';
            $headers = $this->parsedResponseHeaders;
        } elseif ($body === $this->curlResponseHeaders) {
            $headers = $body->getContent(array(self::class, 'parseHeaders'), '');
            if (is_array($headers)) {
                $this->parsedResponseHeaders = $headers;
            }
            return;
        } else {
            error_log("AppSecContext::notifyBody called with unknown body", E_USER_WARNING);
            return;
        }

        $parsedBody = null;
        if (isset($headers['content-type'])) {
            $contentType = end($headers['content-type']);
            $parsedBody = $body->getContent(array(self::class, 'parseContent'), $contentType);
        }

        $data = array();
        if (!empty($headers)) {
            if ($type === 'request' && key_exists('cookie', $headers)) {
                $cookies = $headers['cookie'];
                unset($headers['cookie']);
            }
            if ($type === 'response' && key_exists('set-cookie', $headers)) {
                $cookies = array_map(
                    // remove anything after ;
                    function ($setCookieHeader) {
                        $posColon = strpos($setCookieHeader, ';');
                        if ($posColon !== false) {
                            $setCookieHeader = substr($setCookieHeader, 0, $posColon);
                        }
                        return $setCookieHeader;
                    },
                    $headers['set-cookie']
                );
                unset($headers['set-cookie']);
            }

            if (!empty($headers)) {
                $data["client.{$type}.headers.no_cookies"] = $headers;
            }
            if (!empty($cookies)) {
                $cookies = array_map(array(self::class, 'parseCookieHeader'), $cookies);
                $data["client.{$type}.cookies"] = $cookies;
            }
        }

        if (!empty($parsedBody)) {
            $data["client.{$type}.body"] = $parsedBody;
        }

        \datadog\appsec\push_addresses();
    }

    /**
     * Fill in the options we need to capture headers and body
     * @param $ch resource|\CurlHandle the curl handle
     */
    public function onSubmission($ch)
    {
        if ($this->curlRespBody === null && !CurlIntegration::isWindows()) {
            $stream = fopen("php://output", "wb");
            $this->curlRespBody = new CurlFilteredStreamBody($this);
            $filter = $this->curlRespBody->filterStream($stream, STREAM_FILTER_READ);
            if ($filter) {
                curl_setopt($ch, CURLOPT_FILE, $stream);
            }
        }
        if ($this->curlResponseHeaders === null) {
            $this->curlResponseHeaders = new CurlStreamBody($this);
            curl_setopt($ch, CURLOPT_WRITEHEADER, $this->curlResponseHeaders->getStream());
        }
    }

    public static function parseContent(string $data, string $contentType = null)
    {
        if (empty($contentType)) {
            return null;
        }

        $contentType = trim(strtolower($contentType));
        $mime = trim(explode(';', $contentType, 2)[0]);
        if ($mime === 'text/plain') {
            // we could check if it's actually utf-8 and convert o/wise
            return $data;
        }

        if (strpos($mime, 'application/json') === 0) {
            return \datadog\appsec\convert_json($data);
        }

        if (strpos($mime, 'application/xml') === 0 ||
            strpos($mime, 'text/xml') === 0) {
            return \datadog\appsec\convert_xml($data);
        }

        if ($mime === 'application/x-www-form-urlencoded') {
            $res = array();
            parse_str($data, $res);
            return $res;
        }

        return null;
    }

    public static function parseHeaders(string $rawHeaders, string $unusedContentType = null) : array
    {
        $headers = array();
        foreach (explode("\r\n", $rawHeaders) as $line) {
            self::handleSingleHeaderLine($line, $headers);
        }
        return $headers;
    }

    public static function handleSingleHeaderLine(string $line, array &$headers)
    {
        if (strpos($line, ':') !== false) {
            list($name, $value) = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if (isset($headers[$name])) {
                $headers[$name][] = $value;
            } else {
                $headers[$name] = array($value);
            }
        }
    }

    public static function parseCookieHeader(string $cookieHeader)
    {
        $result = array();

        if (empty($cookieHeader)) {
            return $result;
        }

        $pairs = explode(';', $cookieHeader);

        foreach ($pairs as $pair) {
            $pair = trim($pair);

            if (empty($pair)) {
                continue;
            }

            $equalPos = strpos($pair, '=');

            if ($equalPos === false) {
                // no equals sign → treat as cookie with empty value
                $result[urldecode($pair)] = '';
                continue;
            }

            $name = substr($pair, 0, $equalPos);
            $value = substr($pair, $equalPos + 1);

            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            //  remove surrounding quotes on the value, if present
            if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                $value = substr($value, 1, -1);
            }

            // it is common, though by no means universal to urlencode the cookies
            $result[urldecode($name)] = urldecode($value);
        }

        return $result;
    }
}

class CommitableChange
{
    /**
     * @var callable
     */
    private $impl;

    /**
     * @var callable|null
     */
    private $cancel;

    public function __construct(callable $impl, callable $cancel = null)
    {
        $this->impl = $impl;
        $this->cancel = $cancel;
    }

    public function commit()
    {
        ($this->impl)();
    }

    public function cancel()
    {
        if (!empty($this->cancel)) {
            ($this->cancel)();
        }
    }
}

class BufferedReadFilter extends \php_user_filter
{
    /**
     * @var CurlFilteredStreamBody the body associated with this stream
     */
    private $curlStreamBody;
    /**
     * @var int? the last position we read from the stream
     */
    private $expected_position = null;

    public static function register()
    {
        stream_filter_register('ddappsec.read_spy', __CLASS__);
    }

    public function onCreate() : bool
    {
        if (!\key_exists('curl_stream_body', $this->params)) {
            return false;
        }

        $this->curlStreamBody = $this->params['curl_stream_body'];
        return true;
    }

    /**
     * Called when the filter is destroyed
     */
    public function onClose()
    {
        $this->curlStreamBody->markHasAllData();
    }

    /**
     * Filter the data
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        $position = ftell($this->stream);
        if ($this->expected_position !== null && $position !== $this->expected_position) {
            // stream was seeked; invalidate the body buffer
            $this->curlStreamBody->reqBodyInvalidate();
        }

        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $position += $bucket->datalen;

            $this->curlStreamBody->reqBodyFilterAppend($bucket->data);

            // pass the data through unchanged
            stream_bucket_append($out, $bucket);
        }

        if ($closing) {
            $this->curlStreamBody->markHasAllData();
        }

        $this->expected_position = $position;

        return PSFS_PASS_ON;
    }
}
