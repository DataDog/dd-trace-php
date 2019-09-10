<?php

namespace DDTrace\Integrations\Guzzle;

use DDTrace\Configuration;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class GuzzleSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'guzzle';

    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to PDO requests
     */
    public function init()
    {
        $integration = $this;

        dd_trace_method('GuzzleHttp\Client', 'send', function (SpanData $span, $args, $result) use ($integration) {
            list($request) = $args;
            $span->name = 'GuzzleHttp\Client.send';
            $span->service = GuzzleSandboxedIntegration::NAME;
            $span->type = Type::HTTP_CLIENT;
            $span->resource = 'send';
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
            $integration->addTraceAnalyticsIfEnabled($span);

            GuzzleCommon::setSpanDataStatusCodeTag($span, $result);

            $url = GuzzleCommon::getRequestUrl($request);
            if (null !== $url) {
                $span->meta[Tag::HTTP_URL] = Urls::sanitize($url);
                if (Configuration::get()->isHttpClientSplitByDomain()) {
                    $span->service = Urls::hostnameForTag($url);
                }
            }
        });

        dd_trace_method('GuzzleHttp\Client', 'transfer', function (SpanData $span, $args, $result) use ($integration) {
            list($request) = $args;
            $span->name = 'GuzzleHttp\Client.transfer';
            $span->service = GuzzleSandboxedIntegration::NAME;
            $span->type = Type::HTTP_CLIENT;
            $span->resource = 'transfer';
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
            $integration->addTraceAnalyticsIfEnabled($span);

            GuzzleCommon::setSpanDataStatusCodeTag($span, $result);

            $url = GuzzleCommon::getRequestUrl($request);
            if (null !== $url) {
                $span->meta[Tag::HTTP_URL] = Urls::sanitize($url);
                if (Configuration::get()->isHttpClientSplitByDomain()) {
                    $span->service = Urls::hostnameForTag($url);
                }
            }
        });

        // Guzzle v5 distributed tracing
        dd_trace('GuzzleHttp\Transaction', '__construct', function () {
            $args = func_get_args();
            if (count($args) >= 2) {
                $request = $args[1];
                GuzzleCommon::addRequestHeaders($request, GuzzleCommon::buildDistributedTracingHeaders());
            }

            return dd_trace_forward_call();
        });

        return Integration::LOADED;
    }
}
