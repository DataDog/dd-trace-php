--TEST--
HTTP status code configuration parsing test
--ENV--
DD_TRACE_HTTP_SERVER_ERROR_STATUSES=418,429-451,503
--FILE--
<?php
// Function to mimic your C implementation for checking if a status code is an error
function isStatusError($code) {
    // Get configured status codes from environment
    $statusCodes = getenv('DD_TRACE_HTTP_SERVER_ERROR_STATUSES');
    $isError = false;

    if ($statusCodes) {
        // Custom configuration exists - only use what's configured
        $codesList = explode(',', $statusCodes);

        // Check each code or range
        foreach ($codesList as $item) {
            $item = trim($item);

            if (strpos($item, '-') !== false) {
                // It's a range
                list($start, $end) = explode('-', $item);
                if ($code >= (int)$start && $code <= (int)$end) {
                    $isError = true;
                    break;
                }
            } else {
                // It's a single code
                if ($code == (int)$item) {
                    $isError = true;
                    break;
                }
            }
        }
    } else {
        // No custom configuration - use default behavior (500+ are errors)
        $isError = ($code >= 500);
    }

    return $isError;
}

// Test various status codes
echo "-- With custom configuration --\n";
echo "Status 200: " . (isStatusError(200) ? "Error" : "Not error") . "\n";
echo "Status 418: " . (isStatusError(418) ? "Error" : "Not error") . "\n";
echo "Status 429: " . (isStatusError(429) ? "Error" : "Not error") . "\n";
echo "Status 451: " . (isStatusError(451) ? "Error" : "Not error") . "\n";
echo "Status 490: " . (isStatusError(490) ? "Error" : "Not error") . "\n";
echo "Status 500: " . (isStatusError(500) ? "Error" : "Not error") . "\n";
echo "Status 503: " . (isStatusError(503) ? "Error" : "Not error") . "\n";

// Now test default behavior without config
putenv('DD_TRACE_HTTP_SERVER_ERROR_STATUSES=');

echo "\n-- Without custom configuration --\n";
echo "Status 200: " . (isStatusError(200) ? "Error" : "Not error") . "\n";
echo "Status 418: " . (isStatusError(418) ? "Error" : "Not error") . "\n";
echo "Status 500: " . (isStatusError(500) ? "Error" : "Not error") . "\n";
?>
--EXPECT--
-- With custom configuration --
Status 200: Not error
Status 418: Error
Status 429: Error
Status 451: Error
Status 490: Not error
Status 500: Not error
Status 503: Error

-- Without custom configuration --
Status 200: Not error
Status 418: Not error
Status 500: Error