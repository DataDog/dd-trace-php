<?php

namespace DDTrace\Integrations;

use DDTrace\Tag;

class HttpClientIntegrationHelper
{
    const PEER_SERVICE_SOURCES = [
        Tag::NETWORK_DESTINATION_NAME,
        Tag::TARGET_HOST,
    ];

    /**
     * Determines if a given status code should be considered an error
     * based on the DD_TRACE_HTTP_CLIENT_ERROR_STATUSES configuration.
     *
     * @param int $statusCode The HTTP status code to check
     * @return bool Whether the status code should be considered an error
     */
    public static function isClientError($statusCode) {
        // Get configured status codes from environment
        $errorStatusCodes = \dd_trace_env_config("DD_TRACE_HTTP_CLIENT_ERROR_STATUSES");

        // Specifically set configuration to empty
        if (empty($errorStatusCodes)) {
            return false;
        }

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
    }

    /**
     * Sets error tags on a span for HTTP client errors, if the status code
     * matches the configuration in DD_TRACE_HTTP_CLIENT_ERROR_STATUSES.
     *
     * @param \DDTrace\SpanData $span The span to mark as an error
     * @param int $statusCode The HTTP status code to check
     * @param string|null $reasonPhrase Optional reason phrase to include in the error message
     * @return bool Whether the span was marked as an error
     */
    public static function setClientError($span, $statusCode, $reasonPhrase = null) {
        // Only set error if it's not already set
        if (isset($span->meta[Tag::ERROR])) {
            return false;
        }

        if (self::isClientError($statusCode)) {
            $span->meta[Tag::ERROR_TYPE] = 'http_error';

            if ($reasonPhrase) {
                $span->meta[Tag::ERROR_MSG] = "HTTP $statusCode: $reasonPhrase";
            } else {
                $span->meta[Tag::ERROR_MSG] = "HTTP $statusCode Error";
            }

            return true;
        }

        return false;
    }
}
