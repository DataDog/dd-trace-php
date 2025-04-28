<?php
namespace DDTrace\Integrations\Psr18;

use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

// Note: we test this as part of the GuzzleIntegration, which uses the Psr18Integration as well
class Psr18Integration extends Integration
{
    const NAME = 'psr18';

    public function init(): int
    {
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
                $span->name = 'Psr\Http\Client\ClientInterface.sendRequest';
                $span->type = Type::HTTP_CLIENT;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $span->meta[Tag::COMPONENT] = Psr18Integration::NAME;

                if (isset($args[0])) {
                    $integration->addRequestInfo($span, $args[0]);
                }

                if (isset($retval)) {
                    /** @var \Psr\Http\Message\ResponseInterface $retval */
                    $statusCode = $retval->getStatusCode();
                    $span->meta[Tag::HTTP_STATUS_CODE] = $statusCode;

                    // Mark as error if status code matches configuration and no error is already set
                    if (self::isClientError($statusCode) && !isset($span->meta[Tag::ERROR])) {
                        $span->meta[Tag::ERROR] = 1;
                        $span->meta[Tag::ERROR_TYPE] = 'http_error';
                        $span->meta[Tag::ERROR_MSG] = "HTTP $statusCode: " . $retval->getReasonPhrase();
                    }
                }
            }
        );

        return Integration::LOADED;
    }

    public function addRequestInfo(SpanData $span, $request)
    {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $url = $request->getUri();
        $host = Urls::hostname($url);
        if ($host) {
            $span->meta[Tag::NETWORK_DESTINATION_NAME] = $host;
        }
        if (\dd_trace_env_config("DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN")) {
            $span->service = Urls::hostnameForTag($url);
        }
        $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
        if (!array_key_exists(Tag::HTTP_URL, $span->meta)) {
            $span->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($url);
        }
    }

    /**
     * Determines if a given status code should be considered an error
     * based on the DD_TRACE_HTTP_CLIENT_ERROR_STATUSES configuration.
     *
     * @param int $statusCode The HTTP status code to check
     * @return bool Whether the status code should be considered an error
     */
    private static function isClientError($statusCode) {
        // Get configured status codes from environment
        $errorStatusCodes = \dd_trace_env_config("DD_TRACE_HTTP_CLIENT_ERROR_STATUSES");

        if (!empty($errorStatusCodes)) {
            // Custom configuration exists, use it
            $codesList = explode(',', $errorStatusCodes);

            foreach ($codesList as $item) {
                $item = trim($item);

                if (strpos($item, '-') !== false) {
                    // Range ("400-499")
                    list($start, $end) = explode('-', $item);
                    if ($statusCode >= (int)$start && $statusCode <= (int)$end) {
                        return true;
                    }
                } else {
                    // Single code ("404")
                    if ($statusCode == (int)$item) {
                        return true;
                    }
                }
            }

            // The status code isn't in any defined error range
            return false;
        } else {
            // Default behavior
            return ($statusCode >= 400);
        }
    }
}