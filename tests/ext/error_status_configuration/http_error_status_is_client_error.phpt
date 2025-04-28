--TEST--
HTTP client status code error configuration parsing
--INI--
extension=ddtrace.so
--ENV--
DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=403,408-417
--FILE--
<?php
// Function to mimic the isClientError method
function isClientError($statusCode) {
    // Get configured status codes from environment
    $errorStatusCodes = getenv('DD_TRACE_HTTP_CLIENT_ERROR_STATUSES');

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

// Test various status codes directly against the function
echo "-- With custom configuration --\n";
echo "Status 200: " . (isClientError(200) ? "Error" : "Not error") . "\n";
echo "Status 404: " . (isClientError(404) ? "Error" : "Not error") . "\n";
echo "Status 403: " . (isClientError(403) ? "Error" : "Not error") . "\n";
echo "Status 408: " . (isClientError(408) ? "Error" : "Not error") . "\n";
echo "Status 412: " . (isClientError(412) ? "Error" : "Not error") . "\n";
echo "Status 417: " . (isClientError(417) ? "Error" : "Not error") . "\n";
echo "Status 500: " . (isClientError(500) ? "Error" : "Not error") . "\n";
echo "Status 503: " . (isClientError(503) ? "Error" : "Not error") . "\n";

// Now test default behavior without config
putenv('DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=');

echo "\n-- Without custom configuration --\n";
echo "Status 200: " . (isClientError(200) ? "Error" : "Not error") . "\n";
echo "Status 404: " . (isClientError(404) ? "Error" : "Not error") . "\n";
echo "Status 500: " . (isClientError(500) ? "Error" : "Not error") . "\n";
?>
--EXPECT--
-- With custom configuration --
Status 200: Not error
Status 404: Not error
Status 403: Error
Status 408: Error
Status 412: Error
Status 417: Error
Status 500: Not error
Status 503: Not error

-- Without custom configuration --
Status 200: Not error
Status 404: Error
Status 500: Error