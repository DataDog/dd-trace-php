<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Format;
use DDTrace\GlobalTracer;
use DDTrace\Util\ArrayKVStore;

class CurlCommon
{
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
