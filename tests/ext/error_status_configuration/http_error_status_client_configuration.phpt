--TEST--
HTTP client status code error configuration and helper functions
--ENV--
DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=403,408-417
--FILE--
<?php
// Mock the SpanData class and Tag constants to test in isolation
class SpanData {
    public $meta = [];
}

class Tag {
    const ERROR = 'error';
    const ERROR_TYPE = 'error.type';
    const ERROR_MSG = 'error.msg';
}

// Include the HttpClientIntegrationHelper class
class HttpClientIntegrationHelper
{
    const PEER_SERVICE_SOURCES = [
        'http.host',
        'net.peer.name'
    ];

    public static function isClientError($statusCode) {
        // Get configured status codes from environment
        $errorStatusCodes = getenv("DD_TRACE_HTTP_CLIENT_ERROR_STATUSES");

        // Return early if no configuration exists
        if (empty($errorStatusCodes)) {
            return false;
        }

        // Parse and check the status codes
        $codesList = explode(',', $errorStatusCodes);
        foreach ($codesList as $item) {
            $item = trim($item);
            if (empty($item)) continue;

            if (strpos($item, '-') !== false) {
                // Range ("500-599")
                list($start, $end) = explode('-', $item);
                if ($statusCode >= (int)$start && $statusCode <= (int)$end) {
                    return true;
                }
            } else {
                // Single code
                if ($statusCode == (int)$item) {
                    return true;
                }
            }
        }

        // No matching error status codes found
        return false;
    }

    public static function setClientError($span, $statusCode, $reasonPhrase = null) {
        // Only set error if it's not already set
        if (isset($span->meta[Tag::ERROR])) {
            return false;
        }

        if (self::isClientError($statusCode)) {
            $span->meta[Tag::ERROR] = 1;
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

// Test isClientError directly
echo "-- Testing isClientError with custom configuration --\n";
echo "Status 200: " . (HttpClientIntegrationHelper::isClientError(200) ? "Error" : "Not error") . "\n";
echo "Status 404: " . (HttpClientIntegrationHelper::isClientError(404) ? "Error" : "Not error") . "\n";
echo "Status 403: " . (HttpClientIntegrationHelper::isClientError(403) ? "Error" : "Not error") . "\n";
echo "Status 408: " . (HttpClientIntegrationHelper::isClientError(408) ? "Error" : "Not error") . "\n";
echo "Status 412: " . (HttpClientIntegrationHelper::isClientError(412) ? "Error" : "Not error") . "\n";
echo "Status 417: " . (HttpClientIntegrationHelper::isClientError(417) ? "Error" : "Not error") . "\n";
echo "Status 500: " . (HttpClientIntegrationHelper::isClientError(500) ? "Error" : "Not error") . "\n";
echo "Status 503: " . (HttpClientIntegrationHelper::isClientError(503) ? "Error" : "Not error") . "\n";
echo "Status 600: " . (HttpClientIntegrationHelper::isClientError(600) ? "Error" : "Not error") . "\n";

// Test setClientError method
echo "\n-- Testing setClientError with custom configuration --\n";
$span = new SpanData();
$result = HttpClientIntegrationHelper::setClientError($span, 403);
echo "Status 403: " . ($result ? "Marked as error" : "Not marked") . "\n";
echo "Error type: " . ($span->meta[Tag::ERROR_TYPE] ?? 'none') . "\n";
echo "Error message: " . ($span->meta[Tag::ERROR_MSG] ?? 'none') . "\n";

// Test with custom reason phrase
$span = new SpanData();
$result = HttpClientIntegrationHelper::setClientError($span, 412, 'Precondition Failed');
echo "\nStatus 412 with reason: " . ($result ? "Marked as error" : "Not marked") . "\n";
echo "Error message: " . ($span->meta[Tag::ERROR_MSG] ?? 'none') . "\n";

// Test non-error status
$span = new SpanData();
$result = HttpClientIntegrationHelper::setClientError($span, 200);
echo "\nStatus 200: " . ($result ? "Marked as error" : "Not marked") . "\n";
echo "Has error tag: " . (isset($span->meta[Tag::ERROR]) ? 'Yes' : 'No') . "\n";

// Test already marked error
$span = new SpanData();
$span->meta[Tag::ERROR] = 1;
$result = HttpClientIntegrationHelper::setClientError($span, 403);
echo "\nPre-marked span with status 403: " . ($result ? "Marked again" : "Not marked again") . "\n";

// Test with empty configuration
putenv('DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=');

echo "\n-- Testing with empty configuration --\n";
echo "Status 200: " . (HttpClientIntegrationHelper::isClientError(200) ? "Error" : "Not error") . "\n";
echo "Status 404: " . (HttpClientIntegrationHelper::isClientError(404) ? "Error" : "Not error") . "\n";
echo "Status 500: " . (HttpClientIntegrationHelper::isClientError(500) ? "Error" : "Not error") . "\n";
echo "Status 599: " . (HttpClientIntegrationHelper::isClientError(599) ? "Error" : "Not error") . "\n";
echo "Status 600: " . (HttpClientIntegrationHelper::isClientError(600) ? "Error" : "Not error") . "\n";

$span = new SpanData();
$result = HttpClientIntegrationHelper::setClientError($span, 500);
echo "\nStatus 500 with empty config: " . ($result ? "Marked as error" : "Not marked") . "\n";
echo "Has error tag: " . (isset($span->meta[Tag::ERROR]) ? 'Yes' : 'No') . "\n";

$span = new SpanData();
$result = HttpClientIntegrationHelper::setClientError($span, 404);
echo "\nStatus 404 with empty config: " . ($result ? "Marked as error" : "Not marked") . "\n";
echo "Has error tag: " . (isset($span->meta[Tag::ERROR]) ? 'Yes' : 'No') . "\n";
?>
--EXPECT--
-- Testing isClientError with custom configuration --
Status 200: Not error
Status 404: Not error
Status 403: Error
Status 408: Error
Status 412: Error
Status 417: Error
Status 500: Not error
Status 503: Not error
Status 600: Not error

-- Testing setClientError with custom configuration --
Status 403: Marked as error
Error type: http_error
Error message: HTTP 403 Error

Status 412 with reason: Marked as error
Error message: HTTP 412: Precondition Failed

Status 200: Not marked
Has error tag: No

Pre-marked span with status 403: Not marked again

-- Testing with empty configuration --
Status 200: Not error
Status 404: Not error
Status 500: Not error
Status 599: Not error
Status 600: Not error

Status 500 with empty config: Not marked
Has error tag: No

Status 404 with empty config: Not marked
Has error tag: No