<?php

namespace DDTrace\Integrations\Psr18;

use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class Psr18Integration extends Integration
{
    const NAME = 'psr';

    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;

        /* Until we support both pre- and post- hooks on the same function, do
         * not send distributed tracing headers; curl will almost guarantee do
         * it for us anyway. Just do a post-hook to get the response.
         */
        \DDTrace\trace_method(
            'Psr\Http\Client\ClientInterface',
            'sendRequest',
            function (SpanData $span, $args, $retval) use ($integration) {
                $span->resource = 'sendRequest';
                $span->name = 'Psr\Http\Client\ClientInterface';
                $span->service = 'psr';
                $span->type = Type::HTTP_CLIENT;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $span->meta[Tag::COMPONENT] = PsrIntegration::NAME;

                if (isset($args[0])) {
                    $integration->addRequestInfo($span, $args[0]);
                }

                if (isset($retval)) {
                    /** @var \Psr\Http\Message\ResponseInterface $retval */
                    $span->meta[Tag::HTTP_STATUS_CODE] = $retval->getStatusCode();
                }
            }
        );
    }

    public function addRequestInfo(SpanData $span, $request)
    {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $url = $request->getUri();
        if (\DDTrace\Util\Runtime::getBoolIni("datadog.trace.http_client_split_by_domain")) {
            $span->service = Urls::hostnameForTag($url);
        }
        $span->meta[Tag::HTTP_METHOD] = $request->getMethod();

        if (!array_key_exists(Tag::HTTP_URL, $span->meta)) {
            $span->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($url);
        }
    }
}
