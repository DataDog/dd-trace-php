<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

/**
 * Integration for curl php client.
 */
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
     * Loads the integration.
     */
    public function init()
    {
        if (!extension_loaded('curl')) {
            // `curl` extension is not loaded, if it does not exists we can return this integration as
            // not available.
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        dd_trace_function('curl_exec', function (SpanData $span, $args, $retval) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $span->name = $span->resource = 'curl_exec';
            $span->service = 'curl';
            $span->type = Type::HTTP_CLIENT;
            $integration->addTraceAnalyticsIfEnabled($span);

            if (!isset($args[0]) || !is_resource($args[0])) {
                return;
            }
            if ($retval === false) {
                $span->meta[Tag::ERROR_MSG] = curl_error($args[0]);
                $span->meta[Tag::ERROR_TYPE] = 'curl error';
            }

            $info = curl_getinfo($args[0]);
            $sanitizedUrl = Urls::sanitize($info['url']);
            $span->resource = $sanitizedUrl;
            $span->meta[Tag::HTTP_URL] = $sanitizedUrl;
            $span->meta[Tag::HTTP_STATUS_CODE] = $info['http_code'];
            if (Configuration::get()->isHttpClientSplitByDomain()) {
                $span->service = Urls::hostnameForTag($sanitizedUrl);
            }
        });

        return Integration::LOADED;
    }
}
