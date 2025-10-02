<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\HookData;
use DDTrace\Util\ObjectKVStore;
use function DDTrace\resource_weak_get;
use function DDTrace\resource_weak_store;

abstract class CurlBody {
    /** @var CurlHandleAppSecContext the context associated with this body */
    protected $appSecContext;

    public function __construct(CurlHandleAppSecContext $ctx)
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
     * @return mixed|null the processed body content, if available
     */
    abstract public function getContent(callable $transform, string $contentType);

    public function setKnownSize(int $knownSize)
    {
        // no-op by default
    }

    protected static function defaultSizeLimit() : int
    {
        return (int)(\dd_trace_env_config('DD_APPSEC_MAX_BODY_BUFF_SIZE') ?: 524288);
    }

    public function forClonedHandle(CurlHandleAppSecContext $newCtx, $newCh) : CurlBody
    {
        return new CurlNoopBody($newCtx);
    }
}

class CurlNoopBody extends CurlBody
{
    public function isReady(): bool
    {
        return true;
    }

    public function getContent(callable $transform, string $contentType)
    {
        return null;
    }
}

class CurlStreamBody extends CurlBody
{
    /**
     * @var resource the stream associated with this body
     */
    private $stream;

    public function __construct(CurlHandleAppSecContext $ctx)
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
            return $transform($str, $contentType);
        }
        return null;
    }

    public function forClonedHandle(CurlHandleAppSecContext $newCtx, $newCh) : CurlBody
    {
        $newBody = new static($newCtx);
        if ($this->appSecContext->isRequestBody($this)) {
            CurlIntegration::curl_setopt_internal($newCh, CURLOPT_INFILE, $newBody->getStream());
        } elseif ($this->appSecContext->isResponseBody($this)) {
            CurlIntegration::curl_setopt_internal($newCh, CURLOPT_FILE, $newBody->getStream());
        } elseif ($this->appSecContext->isResponseHeaders($this)) {
            CurlIntegration::curl_setopt_internal($newCh, CURLOPT_WRITEHEADER, $newBody->getStream());
        }
        return $newBody;
    }
}

class CurlFilteredStreamBody extends CurlBody
{
    /**
     * @var string|null the buffered request body (null if no data)
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

    /**
     * @var resource the stream associated with this body
     */
    private $stream;

    /**
     * @var resource the filter resource
     */
    private $filter;

    /**
     * @var bool whether to suppress notification to appsec context
     */
    private $suppressNotification = false;

    public function __construct(CurlHandleAppSecContext $ctx, $stream, /* ?int */ $maxBodyBuffSize)
    {
        parent::__construct($ctx);
        if ($maxBodyBuffSize === null) {
            $this->maxBodyBuffSize = parent::defaultSizeLimit();
        } else {
            $this->maxBodyBuffSize = $maxBodyBuffSize;
        }
        $this->stream = $stream;
    }

    /**
     * @param int $direction either STREAM_FILTER_READ or STREAM_FILTER_WRITE
     * @return false|resource
     */
    public function filterStream(int $direction)
    {
        $res = stream_filter_append(
            $this->stream,
            'ddappsec.read_spy',
            $direction,
            array('curl_stream_body' => $this)
        );

        if ($res === false) {
            $this->reqBodyStr = false;
        }

        $this->filter = $res;

        return $res;
    }

    public function isReady(): bool
    {
        return is_string($this->reqBodyStr) && $this->finishedReceivingData;
    }

    public function getContent(callable $transform, string $contentType)
    {
        if (!$this->finishedReceivingData) {
            /** @noinspection PhpFieldImmediatelyRewrittenInspection */
            $this->suppressNotification = true;
            // PHP's fflush() doesn't flush stdiocasts, which may have buffered data
            // not yet passed down to PHP through the fopencookie handlers
            \datadog\appsec\fflush_stdiocast($this->stream);
            $this->suppressNotification = false;
        }

        if (!is_string($this->reqBodyStr)) {
            return null;
        }
        return $transform($this->reqBodyStr, $contentType);
    }

    public function reqBodyFilterAppend($data)
    {
        if ($this->reqBodyStr === null) {
            $this->reqBodyStr = '';
        }

        if ($this->finishedReceivingData) {
            return;
        }

        $current_len = strlen($this->reqBodyStr);
        $max_len = $this->maxBodyBuffSize == 0 ? PHP_INT_MAX : $this->maxBodyBuffSize;

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

    public function markHasAllData()
    {
        if ($this->finishedReceivingData) {
            return;
        }
        $this->finishedReceivingData = true;

        if ($this->suppressNotification) {
            return;
        }

        $this->appSecContext->notifyBody($this);
    }

    public function setKnownSize(int $knownSize)
    {
        if (!$this->finishedReceivingData && $knownSize > 0 && $knownSize < $this->maxBodyBuffSize) {
            $this->maxBodyBuffSize = $knownSize;
        }
    }

    public function forClonedHandle(CurlHandleAppSecContext $newCtx, $newCh) : CurlBody
    {
        // if a stream is shared between two handles, when the filter is hit we don't know
        // to each handle the data pertains
        stream_filter_remove($this->filter);

        return new CurlNoopBody($newCtx);
    }
}

class CurlCallableInBody extends CurlBody
{
    /**
     * @var callable the upstream callable
     */
    private $upstream;

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

    public function __construct(CurlHandleAppSecContext  $ctx, callable $upstream, /*?int*/ $maxBodyBuffSize)
    {
        parent::__construct($ctx);
        $this->upstream = $upstream;
        if ($maxBodyBuffSize === null) {
            $this->maxBodyBuffSize = self::defaultSizeLimit();
        } else {
            $this->maxBodyBuffSize = $maxBodyBuffSize;
        }
    }

    public function __invoke($ch, $fp, $maxOut)
    {
        $data = ($this->upstream)($ch, $fp, $maxOut);
        if ($this->finishedReceivingData || !is_string($data)) {
            return $data;
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

        if (strlen($this->buffer) >= $max_len || $data === '') {
            $this->markHasAllData();
        }

        return $data;
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

    public function setKnownSize(int $knownSize)
    {
        if (!$this->finishedReceivingData && $knownSize > 0 && $knownSize < $this->maxBodyBuffSize) {
            $this->maxBodyBuffSize = $knownSize;
        }
    }

    public function forClonedHandle(CurlHandleAppSecContext $newCtx, $newCh) : CurlBody
    {
        $newBody = new static($newCtx, $this->upstream, $this->maxBodyBuffSize);
        if ($this->appSecContext->isRequestBody($this)) {
            CurlIntegration::curl_setopt_internal($newCh, CURLOPT_READFUNCTION, $newBody);
        }
        return $newBody;
    }
}
class CurlCallableOutBody extends CurlBody
{
    /**
     * @var callable the downstream callable
     */
    private $downstream;

    /**
     * @var ?callable a callback taking the CurlHandleAppSecContext and the current buffer
     */
    private $onDataCallback;

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

    public function __construct(CurlHandleAppSecContext $ctx
        /*, ?callable $downstream = null */ /* , ?int $maxBodyBuffSize */)
    {
        parent::__construct($ctx);
        if (func_num_args() > 1) {
            $downstream = func_get_arg(1);
        } else {
            $downstream = null;
        }
        $this->downstream = $downstream ?? function ($ch, $data) {
            return strlen($data);
        };
        if (func_num_args() > 2) {
            $maxBodyBuffSize = func_get_arg(2);
        } else {
            $maxBodyBuffSize = null;
        }
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
                $this->buffer = substr($data, 0, $left);
            } else {
                $this->buffer .= $data;
            }
        }

        if (strlen($this->buffer) >= $max_len) {
            $this->markHasAllData();
        }

        if (is_callable($this->onDataCallback)) {
            ($this->onDataCallback)($this->appSecContext, $this->buffer);
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

    public function setOnDataCallback(callable $callback)
    {
        $this->onDataCallback = $callback;
    }

    public function forClonedHandle(CurlHandleAppSecContext $newCtx, $newCh) : CurlBody
    {
        $newBody = new static($newCtx, $this->downstream, $this->maxBodyBuffSize);
        if ($this->appSecContext->isResponseBody($this)) {
            CurlIntegration::curl_setopt_internal($newCh, CURLOPT_WRITEFUNCTION, $newBody);
        } elseif ($this->appSecContext->isResponseHeaders($this)) {
            CurlIntegration::curl_setopt_internal($newCh, CURLOPT_HEADERFUNCTION, $newBody);
        }
        return $newBody;
    }
}

class CurlStringBody extends CurlBody
{
    /**
     * @var string the body content
     */
    private $body;

    public function __construct(CurlHandleAppSecContext $ctx, string $body /*, ?int $limit */)
    {
        parent::__construct($ctx);
        if (func_num_args() > 2) {
            $limit = func_get_arg(2);
        } else {
            $limit = null;
        }
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

    public function forClonedHandle(CurlHandleAppSecContext $newCtx, $newCh) : CurlBody
    {
        return $this;
    }
}

class CurlEmptyBody extends CurlBody
{
    public function __construct(CurlHandleAppSecContext $ctx)
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

    public function forClonedHandle(CurlHandleAppSecContext $newCtx, $newCh) : CurlBody
    {
        return $this;
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

    public function __construct(CurlHandleAppSecContext $ctx, array $body /*, ?int $limit */)
    {
        parent::__construct($ctx);
        if (func_num_args() > 2) {
            $limit = func_get_arg(2);
        } else {
            $limit = null;
        }
        $this->limit = $limit === null ? parent::defaultSizeLimit() : $limit;
        $this->body = $this->initialContextProcessing($body);
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
            if (!is_array($value)) {
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

        return $this->body;
    }

    public function forClonedHandle(CurlHandleAppSecContext $newCtx, $newCh) : CurlBody
    {
        return $this;
    }
}

class CurlHandleAppSecContext
{
    /**
     * @var string|null the URL set with CURLOPT_URL or curl_init
     */
    private $url;

    /**
     * @var array the methods set, with priority (higher number = higher priority)
     */
    private $method = array(0 => 'GET');

    /**
     * @var string|null the cookie string set with CURLOPT_COOKIE
     */
    private $cookie;

    /**
     * @var CurlBody|null the request body associated with this context
     */
    private $curlReqBody;

    /**
     * @var array the request headers, with lowercase keys, and values being arrays of strings
     */
    private $requestHeaders = array();

    /**
     * @var string|null the content type used if it's not set with CURLOPT_HTTPHEADER, if any
     */
    private $fallbackContentType;

    /**
     * @var int|null the size of the input file, if set with CURLOPT_INFILESIZE
     */
    private $infilesize;

    /**
     * @var CurlBody|null the response body associated with this context
     */
    private $curlRespBody;

    /**
     * @var bool whether CURLOPT_RETURNTRANSFER is set to true
     */
    private $returnTransfer = false;

    /**
     * @var CurlBody|null the response headers associated with this context (if captured)
     */
    private $curlResponseHeaders;

    /**
     * @var array the parsed response headers (if captured)
     */
    private $parsedResponseHeaders = array();

    /**
     * @var bool whether redirects are followed (CURLOPT_FOLLOWLOCATION)
     */
    private $followLocation;

    /**
     * @var bool whether unrestricted auth is set (CURLOPT_UNRESTRICTED_AUTH)
     */
    private $unrestrictedAuth;

    const CURL_REDIR_POST_301 = 1;
    const CURL_REDIR_POST_302 = 1 << 1;
    const CURL_REDIR_POST_303 = 1 << 2;

    /**
     * CURL_REDIR_POST_301 (bit 0), CURL_REDIR_POST_302 (bit 1), CURL_REDIR_POST_303 (bit 2)
     * @var int whether curl is configured to redo POST request after 301, 302 or 303 redirects
     */
    private $postRedir = 0;

    const BODY_REQUEST = 1;
    const BODY_RESPONSE_HEADERS = 2;
    const BODY_RESPONSE = 4;

    /**
     * @var int the set of bodies whose content has already been notified to AppSec
     */
    private $notifiedBodies = 0;

    /**
     * @var string the sub-context ID that will be passed to libddwaf
     */
    private $subctx_id;

    public function __construct()
    {
        $this->subctx_id = \spl_object_hash($this);
    }

    /**
     * @param $ch resource|\CurlHandle curl handle (resource on PHP 7; object on PHP 8)
     * @return ?CurlHandleAppSecContext
     */
    public static function get($ch)
    {
        if (\PHP_MAJOR_VERSION > 7) {
            return ObjectKVStore::get($ch, 'appsec_ctx');
        } else {
            return resource_weak_get($ch, 'appsec_ctx');
        }
    }


    /**
     * @param $ch resource|\CurlHandle curl handle (resource on PHP 7; object on PHP 8)
     * @return CurlHandleAppSecContext
     */
    public static function getOrCreate($ch)
    {
        $cur = self::get($ch);
        if ($cur === null) {
            $ctx = new CurlHandleAppSecContext();
            self::put($ch, $ctx);
            $cur = $ctx;
        }
        return $cur;
    }

    public static function put($ch, CurlHandleAppSecContext $ctx)
    {
        if (\PHP_MAJOR_VERSION > 7) {
            ObjectKVStore::put($ch, 'appsec_ctx', $ctx);
        } else {
            resource_weak_store($ch, 'appsec_ctx', $ctx);
        }
    }

    public static function delete($ch)
    {
        $blankCtx = new CurlHandleAppSecContext();
        if (\PHP_MAJOR_VERSION > 7) {
            ObjectKVStore::put($ch, 'appsec_ctx', $blankCtx);
        } else {
            resource_weak_store($ch, 'appsec_ctx', $blankCtx);
        }
    }

    public function copyForClonedHandle($newCh) : CurlHandleAppSecContext
    {
        $newCtx = new CurlHandleAppSecContext();
        $newCtx->url = $this->url;
        $newCtx->method = $this->method;
        $newCtx->cookie = $this->cookie;
        if ($this->curlReqBody !== null) {
            $newCtx->curlReqBody = $this->curlReqBody->forClonedHandle($newCtx, $newCh);
        }
        $newCtx->requestHeaders = $this->requestHeaders;
        $newCtx->fallbackContentType = $this->fallbackContentType;
        $newCtx->infilesize = $this->infilesize;
        if ($this->curlRespBody !== null) {
            $newCtx->curlRespBody = $this->curlRespBody->forClonedHandle($newCtx, $newCh);
        }
        $newCtx->returnTransfer = $this->returnTransfer;
        if ($this->curlResponseHeaders !== null) {
            $newCtx->curlResponseHeaders = $this->curlResponseHeaders->forClonedHandle($newCtx, $newCh);
        }
        return $newCtx;
    }

    public function tentativeSetMethod(string $method, int $priority) : CommitableChange
    {
        return new CommitableChange(function () use ($method, $priority) {
            $this->method[$priority] = strtoupper($method);
        });
    }

    public function tentativeSetInfileSize(int $infilesize) : CommitableChange
    {
        return new CommitableChange(function () use ($infilesize) {
            $this->infilesize = $infilesize;
        });
    }

    /**
     * @param CurlBody|null $curlBody
     * @return CommitableChange
     */
    public function tentativeSetRequestBody($curlBody /*, ?callable $cancel */) : CommitableChange
    {
        if (func_num_args() == 2) {
            $cancel = func_get_arg(1);
        } else {
            $cancel = null;
        }

        return new CommitableChange(function () use ($curlBody) {
            if ($this->curlReqBody instanceof CurlCallableInBody &&
                $curlBody instanceof CurlStreamBody) {
                // CURLOPT_READFUNCTION was set, then CURLOPT_INFILE was set
                // In this case, we care about only the callback; the file is passed to it
                // (the logic here should be more complex, because the options can be set
                // multiple times, and null can be set as well. But this should cover most
                // cases of this obscure behavior)
                return;
            }
            $this->curlReqBody = $curlBody;
        },
            $cancel);
    }

    /**
     * @param string|null $contentType
     * @return CommitableChange
     */
    public function tentativeSetFallbackContentType($contentType) : CommitableChange
    {
        return new CommitableChange(function () use ($contentType) {
            $this->fallbackContentType = $contentType;
        });
    }

    /**
     * @param ?CurlBody $curlBody
     * @return CommitableChange
     */
    public function tentativeSetResponseBody($curlBody /*, ?callable $cancel */) : CommitableChange
    {
        if (func_num_args() == 2) {
            $cancel = func_get_arg(1);
        } else {
            $cancel = null;
        }
        return new CommitableChange(function () use ($curlBody) {
            $this->curlRespBody = $curlBody;
            $this->returnTransfer = false;
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

                if (!empty($res['content-length']) && preg_match('/\A\d+\z/', $res['content-length'][0])) {
                    $this->infilesize = (int)$res['content-length'][0];
                }
            }
        );
    }

    public function tentativeSetResponseHeaders($curlBody /*, ?callable $cancel*/) : CommitableChange
    {
        if (func_num_args() == 2) {
            $cancel = func_get_arg(1);
        } else {
            $cancel = null;
        }

        return new CommitableChange(function () use ($curlBody) {
            $this->curlResponseHeaders = $curlBody;
        },
            $cancel);
    }

    public function tentativeSetCookie(string $cookie) : CommitableChange
    {
        return new CommitableChange(function () use ($cookie) {
            $this->cookie = $cookie;
        });
    }

    public function setUrl(string $url)
    {
        $this->url = $url;
    }

    public function setReturnTransfer(bool $returnTransfer)
    {
        $this->returnTransfer = $returnTransfer;
        $this->curlRespBody = null;
    }

    public function setReturnedBody(string $body)
    {
        $this->curlRespBody = new CurlStringBody($this, $body);
    }

    private function getMethod() : string
    {
        $pri = -1;
        $value = '';
        foreach ($this->method as $p => $m) {
            if ($p > $pri && !empty($m)) {
                $pri = $p;
                $value = $m;
            }
        }
        return $value;
    }

    public function setFollowLocation(bool $val) {
        $this->followLocation = $val;
    }

    public function setUnrestrictedAuth(bool $val) {
        $this->unrestrictedAuth = $val;
    }

    public function setPostRedir(int $val) {
        $this->postRedir = $val;
    }

    public function notifyBody(CurlBody $body)
    {
        if ($body === $this->curlReqBody) {
            if ($this->notifiedBodies & self::BODY_REQUEST) {
                return;
            }
            $this->notifiedBodies |= self::BODY_REQUEST;

            $type = 'request';
            $headers = $this->requestHeaders;
        } elseif ($body === $this->curlRespBody) {
            if ($this->notifiedBodies & self::BODY_RESPONSE) {
                return;
            }
            $this->notifiedBodies |= self::BODY_RESPONSE;

            $type = 'response';
            $headers = $this->parsedResponseHeaders;
        } elseif ($body === $this->curlResponseHeaders) {
            if ($this->notifiedBodies & self::BODY_RESPONSE_HEADERS) {
                return;
            }
            $this->notifiedBodies |= self::BODY_RESPONSE_HEADERS;

            $headers = $body->getContent(array(self::class, 'parseHeaders'), '');
            if (is_array($headers)) {
                $this->parsedResponseHeaders = $headers;
            }
            return;
        } else {
            error_log("CurlHandleAppSecContext::notifyBody called with unknown body", E_USER_WARNING);
            return;
        }

        $parsedBody = null;
        if (isset($headers['content-type'])) {
            $contentType = end($headers['content-type']);
        } elseif ($type === 'request' && !empty($this->fallbackContentType)) {
            $this->requestHeaders['content-type'] = array($this->fallbackContentType);
            $contentType = $this->fallbackContentType;
        }

        if (isset($contentType)) {
            $parsedBody = $body->getContent(array(self::class, 'parseContent'), $contentType);
        }

        $this->wafRun($type, $parsedBody);
    }

    private function wafRun($type /* request/ response */, $parsedBody)
    {
        $headers = $type == 'request' ? $this->requestHeaders : $this->parsedResponseHeaders;

        if ($this->url === null || strpos($this->url, 'http') !== 0) {
            // no URL or non-http URL
            return;
        }

        $data = array();

        if ($this->cookie !== null && $type === 'request') {
            // also include CURLOPT_COOKIE - merge into headers
            $headers['cookie'] = $headers['cookie'] ?? array();
            $headers['cookie'][] = $this->cookie;
        }

        if ($type == 'request') {
            $data['server.io.net.request.method'] = $this->getMethod();
        } else { // response
            if (!empty($headers['_status_code'])) {
                $data['server.io.net.response.status'] = (int)$headers['_status_code'];
                unset($headers['_status_code']);
            }
        }

        if ($type === 'request') {
            // fill in stuff based on url
            $purl = parse_url($this->url);
            if (empty($headers['host']) && !empty($purl['host'])) {
                $host = $purl['host'];
                if (!empty($purl['port'])) {
                    $host .= ':' . $purl['port'];
                }
                $headers['host'] = array($host);
            }

            // RFC-1062: Provide full URL in server.io.net.url
            // libddwaf may internally derive path and query
            $data['server.io.net.url'] = $this->url;
        }

        if (!empty($headers)) {
            $data["server.io.net.{$type}.headers"] = $headers;
        }

        if (!empty($parsedBody)) {
            if (function_exists('\datadog\appsec\should_send_downstream_bodies')
                && \datadog\appsec\should_send_downstream_bodies()) {
                $data["server.io.net.{$type}.body"] = $parsedBody;
            }
        }

        if (empty($data)) {
            return;
        }

        $origNotifiedBodies = $this->notifiedBodies;

        $lastCall = $this->notifiedBodies & self::BODY_RESPONSE;
        $this->notifiedBodies = -1;

        \datadog\appsec\push_addresses($data, [
            'subctx_id' => $this->subctx_id,
            'subctx_last_call' => (bool)$lastCall,
        ]);

        // if we block, we don't reach this, therefore we won't handle more notifications
        $this->notifiedBodies = $origNotifiedBodies;
    }

    /**
     * To be called on the curl request is to start. Fill in the options we
     * need to capture headers and body, then do the first waf run if ready.
     * @param $ch resource|\CurlHandle the curl handle
     */
    public function onSubmission($ch)
    {
        if (!$this->returnTransfer && $this->curlRespBody === null && !CurlIntegration::isWindows()) {
            // by default, curl writes to the php output
            // even in multi handles
            $stream = fopen("php://output", "wb");
            $this->curlRespBody = new CurlFilteredStreamBody($this, $stream, null);
            $filter = $this->curlRespBody->filterStream(STREAM_FILTER_WRITE);
            if ($filter) {
                CurlIntegration::curl_setopt_internal($ch, CURLOPT_FILE, $stream);
            }
        }
        if ($this->curlResponseHeaders === null) {
            $this->curlResponseHeaders = new CurlCallableOutBody($this);
            CurlIntegration::curl_setopt_internal($ch, CURLOPT_HEADERFUNCTION, $this->curlResponseHeaders);
        }
        if ($this->followLocation && $this->curlResponseHeaders instanceof CurlCallableOutBody) {
            $lastReqPos = 0;
            $this->curlResponseHeaders->setOnDataCallback(function (CurlHandleAppSecContext $ctx, $buffer)
                use (&$lastReqPos) {
                $ctx->handleRedirectInResponseHeaders($buffer, $lastReqPos);
            });
        } // else Redirect handling is not supported for other body types

        if ($this->curlReqBody !== null) {
            if ($this->curlReqBody->isReady()) {
                $this->notifyBody($this->curlReqBody);
            } elseif ($this->infilesize) {
                $this->curlReqBody->setKnownSize($this->infilesize);
            }
        } else {
            $this->wafRun('request', null);
        }
    }

    /**
     * Called when the respective curl request is completed
     * @param $ch resource|\CurlHandle the curl handle
     * @return void
     */
    public function onCompleted($ch)
    {
        if ($this->curlResponseHeaders && empty($this->parsedResponseHeaders)) {
            $this->notifyBody($this->curlResponseHeaders);
        }

        if ($this->curlRespBody !== null) {
            $this->notifyBody($this->curlRespBody);
        } elseif ($this->returnTransfer) {
            $responseBody = curl_multi_getcontent($ch);
            if ($responseBody !== null) {
                $this->setReturnedBody($responseBody);
                $this->notifyBody($this->curlRespBody);
            }
        }
    }

    public static function parseContent(string $data, string $contentType)
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

    public static function parseHeaders(string $rawHeaders, string $unusedContentType) : array
    {
        // first check where the status line is. If there were redirects,
        // the relevant one's not going to be in the beginning
        if (!preg_match_all('/^HTTP\/[0-9.]+ (\d+)/m', $rawHeaders, $matches, PREG_OFFSET_CAPTURE)) {
            return array();
        }

        $startPos = end($matches[0])[1];
        $statusCode = end($matches[1])[0];
        $rawHeaders = substr($rawHeaders, $startPos);

        $headers = array('_status_code' => $statusCode);
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

            if ($value === '') {
                return;
            }

            if (isset($headers[$name])) {
                $headers[$name][] = $value;
            } else {
                $headers[$name] = array($value);
            }
        }
    }

    public function isRequestBody(CurlBody $body) : bool
    {
        return $body === $this->curlReqBody;
    }

    public function isResponseBody(CurlBody $body) : bool
    {
        return $body === $this->curlRespBody;
    }

    public function isResponseHeaders(CurlBody $body) : bool
    {
        return $body === $this->curlResponseHeaders;
    }

    private function resolveRelativeUrl(string $location)
    {
        if (preg_match('@\A[a-z]+://@', $location)) {
            return $location;
        }

        $baseParts = parse_url($this->url);
        if (!$baseParts || !isset($baseParts['scheme']) || !isset($baseParts['host'])) {
            return false;
        }

        $locationParts = parse_url($location);
        if ($locationParts === false) {
            return false;
        }

        if (isset($locationParts['scheme'])) {
            // if it has a scheme, it's a completely new URL
            $finalParts = $locationParts;
        } elseif (isset($locationParts['host'])) {
            // we have no scheme but we have a host; only the scheme is inherited
            $finalParts = array_merge(array(
                'scheme' => $baseParts['scheme']
            ), $locationParts);
        } elseif (isset($locationParts['path'])) {
            path:
            // we have a path; inherit scheme, host, port, user, pass
            // not path or query though
            $finalParts = $baseParts;

            if (strpos($locationParts['path'], '/') === 0) {
                // absolute path
                $finalParts['path'] = $locationParts['path'];
            } else {
                // relative path
                $finalParts['path'] = self::resolveRelativePath(
                    $baseParts['path'] ?? '/',
                    $locationParts['path']
                );
            }

            $finalParts['query'] = $locationParts['query'] ?? null;
        } elseif (isset($locationParts['query'])) {
            // this is not really valid. Have the query deemed a relative path
            unset($locationParts['query']);
            $locationParts['path'] = '?' . $locationParts['query'];
            goto path;
        } else {
            // nothing
            return false;
        }

        $finalUrl = $finalParts['scheme'] . '://';
        if (isset($finalParts['user'])) {
            $finalUrl .= $finalParts['user'];
            $finalUrl .= ':' . $finalParts['pass'] ?? '';
            $finalUrl .= '@';
        }
        $finalUrl .= $finalParts['host'];
        if (isset($finalParts['port'])) {
            $finalUrl .= ':' . $finalParts['port'];
        }
        $finalUrl .= $finalParts['path'] ?? '/';
        if (isset($finalParts['query'])) {
            $finalUrl .= '?' . $finalParts['query'];
        }

        return $finalUrl;
    }

    private static function resolveRelativePath(string $baseDir, string $relPath): string
    {
        if ($relPath === '') {
            return $baseDir;
        }

        $parts = explode('/', ltrim($baseDir, '/'));
        $relParts = explode('/', $relPath);

        // always remove the last element (empty if it ends in /)
        array_pop($parts);

        foreach ($relParts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (count($parts) > 0) {
                    array_pop($parts);
                }
                // if at root, ignore the .. instead of adding it
            } else {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    private function handleRedirectInResponseHeaders(string $buffer, int &$lastReqPos)
    {
        if (($end = strpos($buffer, "\r\n\r\n", $lastReqPos)) !== false) {
            // we've seen a full set of headers
            // check if it's a redirect
            $reqData = substr($buffer, $lastReqPos, $end + 2 - $lastReqPos);
            $lastReqPos = $end + 4;
            if (!preg_match('@^HTTP/[0-9.]+ (\d+) @m', $reqData, $matches)) {
                return;
            }
            $statusCode = (int)$matches[1];
            if (!in_array($statusCode, array(301, 302, 303, 307, 308), true)) {
                return;
            }

            // don't support folding
            if (!preg_match('/^location:\s+(.*)$/im', $reqData, $matches)) {
                return;
            }
            $location = trim($matches[1]);
            $newUrl = $this->resolveRelativeUrl($location);
            if (!$newUrl) {
                return;
            }

            $curlResponseHeaders = $this->curlResponseHeaders;
            $this->curlResponseHeaders = new CurlStringBody($this, $reqData);
            $this->notifyBody($this->curlResponseHeaders);
            $this->curlResponseHeaders = $curlResponseHeaders;

            $respBody = $this->curlRespBody;
            $this->curlRespBody = new CurlEmptyBody($this);
            $this->notifyBody($this->curlRespBody);
            // if we reach here, we did not block
            // we do need to reset the state for the new request
            $this->curlRespBody = $respBody;

            $this->parsedResponseHeaders = array();

            if (!$this->unrestrictedAuth) {
                unset($this->requestHeaders['authorization']);
                unset($this->requestHeaders['cookie']);
                $this->cookie = null;
            }
            $this->url = $newUrl;

            /*
             * | Redirect | POST behavior                              | PUT/DELETE/etc | POSTREDIR effect    |
             * |----------|--------------------------------------------|----------------|---------------------|
             * | 301/302  | → GET (unless CURL_REDIR_POST_301/302 set) | Stays same     | Only affects POST   |
             * | 303      | → GET (unless CURL_REDIR_POST_303 set)     | → GET (always) | Only affects POST   |
             * | 307/308  | Stays POST                                 | Stays same     | N/A (no conversion) |
             */
            $method = $this->getMethod();
            if ($method === 'POST') {
                if ($statusCode === 301 && !($this->postRedir & self::CURL_REDIR_POST_301)) {
                    $method = 'GET';
                } elseif ($statusCode == 302 && !($this->postRedir & self::CURL_REDIR_POST_302)) {
                    $method = 'GET';
                } elseif ($statusCode == 303 && !($this->postRedir & self::CURL_REDIR_POST_303)) {
                    $method = 'GET';
                }
            } elseif ($method != 'GET' && $statusCode == 303) {
                // 303 always switches to GET for non-GET methods
                $method = 'GET';
            }

            if ($method !== $this->getMethod()) {
                $this->method[20] = $method;
            }

            $this->notifiedBodies = 0;
            $this->subctx_id = sha1($this->subctx_id);

            $this->wafRun('request', null);
        }
    }
}

class CurlMultiHandleAppSecContext {
    /**
     * @var \CurlHandle[] the unstarted contexts
     */
    private $unstarted = array();

    /**
     * @param $multi resource|\CurlMultiHandle the curl multi handle (resource on PHP 7; object on PHP 8)
     * @return ?CurlMultiHandleAppSecContext
     */
    public static function get($multi)
    {
        if (\PHP_MAJOR_VERSION > 7) {
            return ObjectKVStore::get($multi, 'appsec_multictx');
        } else {
            return resource_weak_get($multi, 'appsec_multictx');
        }
    }

    /**
     * @param $multi resource|\CurlMultiHandle curl multi handle (resource on PHP 7; object on PHP 8)
     */
    public static function getOrCreate($multi) : CurlMultiHandleAppSecContext
    {
        $cur = self::get($multi);
        if ($cur === null) {
            $ctx = new CurlMultiHandleAppSecContext();
            self::put($multi, $ctx);
            $cur = $ctx;
        }
        return $cur;
    }

    public static function put($ch, CurlMultiHandleAppSecContext $ctx)
    {
        if (\PHP_MAJOR_VERSION > 7) {
            ObjectKVStore::put($ch, 'appsec_multictx', $ctx);
        } else {
            resource_weak_store($ch, 'appsec_multictx', $ctx);
        }
    }

    public function addHandle($ch) : CommitableChange
    {
        return new CommitableChange(
            function () use ($ch) {
                $this->unstarted[] = $ch;
            }
        );
    }

    public function removeHandle($ch) : CommitableChange
    {
        return new CommitableChange(
            function () use ($ch) {
                $index = array_search($ch, $this->unstarted, true);
                if ($index !== false) {
                    unset($this->unstarted[$index]);
                }
            }
        );
    }

    public function onInfoRead($ch)
    {
        $ctx = CurlHandleAppSecContext::get($ch);
        if ($ctx !== null) {
            $ctx->onCompleted($ch);
        }
    }

    /**
     * To call on the hook of curl_multi_exec
     * @return void
     */
    public function onPerform()
    {
        foreach ($this->unstarted as $k => $ch) {
            $ctx = CurlHandleAppSecContext::getOrCreate($ch);
            $ctx->onSubmission($ch);
        }
        $this->unstarted = array();
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

    /**
     * @param callable $impl
     * @param callable|null $cancel (Can't annotate with ?callable due to PHP 7.0)
     */
    public function __construct(callable $impl, $cancel = null)
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

    public static function combine(CommitableChange ...$changes) : CommitableChange
    {
        return new CommitableChange(
            function () use ($changes) {
                foreach ($changes as $change) {
                    $change->commit();
                }
            },
            function () use ($changes) {
                foreach ($changes as $change) {
                    $change->cancel();
                }
            }
        );
    }
}

if (PHP_VERSION_ID >= 70100) {
    class BufferedReadFilter extends \php_user_filter
    {
        /**
         * @var CurlFilteredStreamBody the body associated with this stream
         */
        private $curlStreamBody;

        public static function register()
        {
            stream_filter_register('ddappsec.read_spy', __CLASS__);
        }

        public function onCreate(): bool
        {
            if (!\key_exists('curl_stream_body', $this->params)) {
                return false;
            }

            $this->curlStreamBody = $this->params['curl_stream_body'];
            return true;
        }

        /**
         * Called when the filter is destroyed
         * @return void
         */
        public function onClose() : void
        {
            $this->curlStreamBody->markHasAllData();
        }

        /**
         * Filter the data
         */
        public function filter($in, $out, &$consumed, $closing): int
        {
            while ($bucket = stream_bucket_make_writeable($in)) {
                $consumed += $bucket->datalen;

                $this->curlStreamBody->reqBodyFilterAppend($bucket->data);

                // pass the data through unchanged
                stream_bucket_append($out, $bucket);
            }

            if ($closing) {
                $this->curlStreamBody->markHasAllData();
            }

            return PSFS_PASS_ON;
        }
    }
} else {
    class BufferedReadFilter extends \php_user_filter
    {
        /**
         * @var CurlFilteredStreamBody the body associated with this stream
         */
        private $curlStreamBody;

        public static function register()
        {
            stream_filter_register('ddappsec.read_spy', __CLASS__);
        }

        public function onCreate(): bool
        {
            if (!\key_exists('curl_stream_body', $this->params)) {
                return false;
            }

            $this->curlStreamBody = $this->params['curl_stream_body'];
            return true;
        }

        /**
         * Called when the filter is destroyed
         * @return void
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
            while ($bucket = stream_bucket_make_writeable($in)) {
                $consumed += $bucket->datalen;

                $this->curlStreamBody->reqBodyFilterAppend($bucket->data);

                // pass the data through unchanged
                stream_bucket_append($out, $bucket);
            }

            if ($closing) {
                $this->curlStreamBody->markHasAllData();
            }

            return PSFS_PASS_ON;
        }
    }
}

function curlIntegrationAppSecInit()
{
    \DDtrace\install_hook('curl_setopt',
        static function (HookData $hook) {
            if (CurlIntegration::isInternalCall()) {
                return;
            }

            if (!\datadog\appsec\is_enabled()) {
                return;
            }

            if (count($hook->args) < 3) {
                return;
            }

            /**
             * @var resource|\CurlHandle $ch
             * @var int $option
             */
            list($ch, $option, $value) = $hook->args;
            $ctx = CurlHandleAppSecContext::getOrCreate($ch);

            static $STREAM_OPTIONS = array(
                CURLOPT_INFILE => null,
                CURLOPT_FILE => null,
                CURLOPT_WRITEHEADER => null,
            );

            if (key_exists($option, $STREAM_OPTIONS)
                && \is_resource($value) && \get_resource_type($value) === 'stream'
                && !CurlIntegration::isWindows()) {
                $body = new CurlFilteredStreamBody(
                    $ctx,
                    $value,
                    $option === CURLOPT_WRITEHEADER ? 0 /* unlimited */ : null /* default */
                );
                $filter = $body->filterStream(
                    $option === CURLOPT_INFILE ? STREAM_FILTER_READ : STREAM_FILTER_WRITE
                );
                if ($filter) {
                    $cancel = static function () use ($filter) {
                        stream_filter_remove($filter);
                    };
                    if ($option === CURLOPT_FILE) {
                        $hook->data = $ctx->tentativeSetResponseBody($body);
                    } elseif ($option === CURLOPT_INFILE) {
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
            } elseif ($option === CURLOPT_INFILESIZE) {
                $hook->data = $ctx->tentativeSetInfileSize((int)$value);
            } elseif ($option === CURLOPT_READFUNCTION) {
                if (\is_callable($value)) {
                    $body = new CurlCallableInBody($ctx, $value, null);
                    $hook->overrideArguments(array($ch, $option, $body));
                    $hook->data = $ctx->tentativeSetRequestBody($body);
                } else {
                    $hook->data = $ctx->tentativeSetRequestBody(null);
                }
            } elseif ($option === CURLOPT_WRITEFUNCTION) {
                if (\is_callable($value)) {
                    $body = new CurlCallableOutBody($ctx, $value);
                    $hook->overrideArguments(array($ch, $option, $body));
                    $hook->data = $ctx->tentativeSetResponseBody($body);
                } else {
                    $hook->data = $ctx->tentativeSetResponseBody(null);
                }
            } elseif ($option === CURLOPT_HEADERFUNCTION) {
                if (\is_callable($value)) {
                    $body = new CurlCallableOutBody($ctx, $value);
                    $hook->overrideArguments(array($ch, $option, $body));
                    $hook->data = $ctx->tentativeSetResponseHeaders($body);
                } else {
                    $hook->data = $ctx->tentativeSetResponseHeaders(null);
                }
            } elseif ($option === CURLOPT_POST) {
                $hook->data = $ctx->tentativeSetMethod($value ? 'POST' : 'GET', 5);
            } elseif ($option === CURLOPT_PUT || $option === CURLOPT_UPLOAD) {
                $hook->data = $ctx->tentativeSetMethod($value ? 'PUT' : 'GET', 5);
            } elseif ($option == CURLOPT_CUSTOMREQUEST) {
                if ($value === null) {
                    $hook->data = $ctx->tentativeSetMethod(null, 10);
                } else {
                    $hook->data = $ctx->tentativeSetMethod(strtoupper($value), 10);
                }
            } elseif ($option === CURLOPT_POSTFIELDS) {
                if (is_array($value)) {
                    if (empty($value)) {
                        $hook->data = CommitableChange::combine(
                            $ctx->tentativeSetRequestBody(new CurlEmptyBody($ctx)),
                            $ctx->tentativeSetFallbackContentType('application/x-www-form-urlencoded'),
                            $ctx->tentativeSetMethod('POST', 5)
                        );
                    } else {
                        $hook->data = CommitableChange::combine(
                            $ctx->tentativeSetRequestBody(new CurlArrayBody($ctx, $value)),
                            $ctx->tentativeSetFallbackContentType('multipart/form-data'),
                            $ctx->tentativeSetMethod('POST', 5)
                        );
                    }
                } else {
                    $strValue = (string)$value;
                    $body = new CurlStringBody($ctx, $strValue);
                    $hook->data = CommitableChange::combine(
                        $ctx->tentativeSetRequestBody($body),
                        $ctx->tentativeSetFallbackContentType('application/x-www-form-urlencoded'),
                        $ctx->tentativeSetMethod('POST', 5)
                    );
                }
            } elseif ($option === CURLOPT_HTTPHEADER && is_array($value)) {
                $hook->data = $ctx->tentativeSetRequestHeaders($value);
            } elseif ($option === CURLOPT_RETURNTRANSFER) {
                $ctx->setReturnTransfer((bool)$value);
            } elseif ($option === CURLOPT_URL) {
                $ctx->setUrl((string)$value);
            } elseif ($option === CURLOPT_COOKIE) {
                $hook->data = $ctx->tentativeSetCookie((string)$value);
            } elseif ($option === CURLOPT_FOLLOWLOCATION) {
                $ctx->setFollowLocation((bool)$value);
            } elseif ($option === CURLOPT_UNRESTRICTED_AUTH) {
                $ctx->setUnrestrictedAuth((bool)$value);
            } elseif ($option === CURLOPT_POSTREDIR) {
                $ctx->setPostRedir((int)$value);
            }
        },
        static function (HookData $hook) {
            if (CurlIntegration::isInternalCall()) {
                return;
            }

            if (isset($hook->data) && $hook->data instanceof CommitableChange) {
                if ($hook->returned === true) {
                    $hook->data->commit();
                } else {
                    $hook->data->cancel();
                }
            }
        }
    );

    \DDtrace\install_hook('curl_setopt_array',
        static function (HookData $hook) {
            $hook->disableJitInlining();

            if (!\datadog\appsec\is_enabled()) {
                return;
            }

            if (count($hook->args) < 2) {
                return;
            }

            $opts = $hook->args[1];
            if (!is_array($opts)) {
                return;
            }
            foreach ($opts as $k => $v) {
                if (!is_int($k)) {
                    return;
                }
            }

            // all ok, let's continue
            //curl_setopt_array just calls curl_setopt in a loop
            // until it either finishes or fails
            $hook->suppressCall();
            $hook->allowNestedHook();

            foreach ($opts as $option => $value) {
                if (!is_int($option)) {
                    $hook->data = array(
                        'exception' => new \Error(
                            'curl_setopt_array(): Argument #2 ($options) contains an invalid cURL option'
                        )
                    );
                    return;
                }
                $res = curl_setopt($hook->args[0], $option, $value);
                if ($res === false) {
                    $hook->data = array('return' => false);
                    return;
                }
            }

            $hook->data = array('return' => true);
        },
        static function (HookData $hook) {
            if (isset($hook->data['exception'])) {
                $hook->overrideException($hook->data['exception']);
            } elseif (isset($hook->data['return'])) {
                $hook->overrideReturnValue($hook->data['return']);
            }
        }
    );

    \DDtrace\install_hook(
        'curl_multi_add_handle',
        static function (HookData $hook) {
            if (!\datadog\appsec\is_enabled()) {
                return;
            }

            if (count($hook->args) < 2) {
                return;
            }

            list($multiHandle, $ch) = $hook->args;

            $hook->data = CurlMultiHandleAppSecContext::getOrCreate($multiHandle)->addHandle($ch);
        },
        static function (HookData $hook) {
            if (empty($hook->data)) {
                return;
            }

            $cc = $hook->data;
            if ($hook->returned === CURLM_OK) {
                $cc->commit();
            } else {
                $cc->cancel();
            }
        }
    );

    \DDTrace\install_hook(
        'curl_multi_remove_handle',
        static function (HookData $hook) {
            if (!\datadog\appsec\is_enabled()) {
                return;
            }

            if (count($hook->args) < 2) {
                return;
            }

            list($multiHandle, $ch) = $hook->args;

            $ctx = CurlMultiHandleAppSecContext::get($multiHandle);
            if ($ctx !== null) {
                $ctx->removeHandle($ch);
            }
        }
    );

    \DDTrace\install_hook(
        'curl_init',
        null,
        static function (HookData $hook) {
            $ch = $hook->returned;
            if ($ch === false) {
                return;
            }
            if (!\datadog\appsec\is_enabled()) {
                return;
            }

            $ctx = CurlHandleAppSecContext::getOrCreate($ch);
            if (count($hook->args) < 1) {
                return;
            }
            $ctx->setUrl((string)$hook->args[0]);
        }
    );

    \DDTrace\install_hook(
        'curl_copy_handle',
        null,
        static function (HookData $hook) {
            $newCh = $hook->returned;
            if ($newCh === false) {
                return;
            }
            if (!\datadog\appsec\is_enabled()) {
                return;
            }

            $oldCh = $hook->args[0];
            $oldCtx = CurlHandleAppSecContext::get($oldCh);
            if ($oldCtx === null) {
                return;
            }
            $newCtx = $oldCtx->copyForClonedHandle($newCh);
            CurlHandleAppSecContext::put($newCh, $newCtx);
        }
    );

    \DDTrace\install_hook(
        'curl_reset',
        null,
        static function (HookData $hook) {
            if (!\datadog\appsec\is_enabled()) {
                return;
            }
            if (count($hook->args) < 1) {
                return;
            }
            $ch = $hook->args[0];
            CurlHandleAppSecContext::delete($ch);
        }
    );

    \DDTrace\install_hook('curl_exec',
        function (HookData $hook) {
            if (!\datadog\appsec\is_enabled()) {
                return;
            }

            if (count($hook->args) < 1) {
                return;
            }
            $ch = $hook->args[0];

            $ctx = CurlHandleAppSecContext::getOrCreate($ch);
            $ctx->onSubmission($ch);
        },
        function (HookData $hook) {
            if (!\datadog\appsec\is_enabled()) {
                return;
            }

            if (!isset($hook->args[0])) {
                return;
            }

            $ch = $hook->args[0];
            $ctx = CurlHandleAppSecContext::get($ch);
            if ($ctx !== null) {
                if (is_string($hook->returned)) {
                    $ctx->setReturnedBody($hook->returned);
                }
                $ctx->onCompleted($ch);
            }
        }
    );

    \DDTrace\install_hook('curl_multi_exec', static function (HookData $hook) {
        if (!\datadog\appsec\is_enabled()) {
            return;
        }

        if (\count($hook->args) < 2) {
            return;
        }

        $ctx = CurlMultiHandleAppSecContext::get($hook->args[0]);
        if ($ctx !== null) {
            $ctx->onPerform();
        }
    });

    \DDTrace\install_hook('curl_multi_info_read', null, static function (HookData $hook) {
        if (!\datadog\appsec\is_enabled()) {
            return;
        }

        if (count($hook->args) < 1 || !isset($hook->returned["handle"])) {
            return;
        }

        $handle = $hook->returned["handle"];
        $ctx = CurlMultiHandleAppSecContext::get($hook->args[0]);
        if ($ctx !== null) {
            $ctx->onInfoRead($handle);
        }
    });

    BufferedReadFilter::register();
}
