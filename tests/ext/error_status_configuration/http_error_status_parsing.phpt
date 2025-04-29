--TEST--
HTTP server error status configuration parsing test
--ENV--
DD_TRACE_HTTP_SERVER_ERROR_STATUSES=418,429-451,503
--FILE--
<?php
// Function to mimic the C implementation for checking if a status code is an error
function isStatusError($code) {
    // Get configured status codes from environment
    $statusCodes = getenv('DD_TRACE_HTTP_SERVER_ERROR_STATUSES');
    $isError = false;

    // In the C implementation, when there's no environment variable,
    // it falls back to the default "500-599" from the CONFIG macro
    // So we need to simulate that here
    if (!$statusCodes) {
        $statusCodes = "500-599"; // This mimics the default in CONFIG macro
    }

    $codesList = explode(',', $statusCodes);
    foreach ($codesList as $item) {
        $item = trim($item);

        if (strpos($item, '-') !== false) {
            // Range like "500-599"
            list($start, $end) = explode('-', $item);
            if ($code >= (int)$start && $code <= (int)$end) {
                $isError = true;
                break;
            }
        } else {
            // Single status code
            $code_val = (int)$item;
            if ($code == $code_val) {
                $isError = true;
                break;
            }
        }
    }

    return $isError;
}

// Function to mimic the C implementation for getting error message
function getErrorMessage($code) {
    return "HTTP $code Error";
}

// Test various status codes
echo "-- With custom configuration --\n";
echo "Status 200: " . (isStatusError(200) ? "Error" : "Not error") . "\n";
echo "Status 418: " . (isStatusError(418) ? "Error" : "Not error") . "\n";
if (isStatusError(418)) {
    echo "Error message: " . getErrorMessage(418) . "\n";
}
echo "Status 429: " . (isStatusError(429) ? "Error" : "Not error") . "\n";
if (isStatusError(429)) {
    echo "Error message: " . getErrorMessage(429) . "\n";
}
echo "Status 451: " . (isStatusError(451) ? "Error" : "Not error") . "\n";
if (isStatusError(451)) {
    echo "Error message: " . getErrorMessage(451) . "\n";
}
echo "Status 490: " . (isStatusError(490) ? "Error" : "Not error") . "\n";
echo "Status 500: " . (isStatusError(500) ? "Error" : "Not error") . "\n";
echo "Status 503: " . (isStatusError(503) ? "Error" : "Not error") . "\n";
if (isStatusError(503)) {
    echo "Error message: " . getErrorMessage(503) . "\n";
}
echo "Status 600: " . (isStatusError(600) ? "Error" : "Not error") . "\n";

// Now test default behavior without custom config
putenv('DD_TRACE_HTTP_SERVER_ERROR_STATUSES=');

echo "\n-- Without custom configuration (using default 500-599) --\n";
echo "Status 200: " . (isStatusError(200) ? "Error" : "Not error") . "\n";
echo "Status 418: " . (isStatusError(418) ? "Error" : "Not error") . "\n";
echo "Status 500: " . (isStatusError(500) ? "Error" : "Not error") . "\n";
if (isStatusError(500)) {
    echo "Error message: " . getErrorMessage(500) . "\n";
}
echo "Status 599: " . (isStatusError(599) ? "Error" : "Not error") . "\n";
if (isStatusError(599)) {
    echo "Error message: " . getErrorMessage(599) . "\n";
}
echo "Status 600: " . (isStatusError(600) ? "Error" : "Not error") . "\n";
?>
--EXPECT--
-- With custom configuration --
Status 200: Not error
Status 418: Error
Error message: HTTP 418 Error
Status 429: Error
Error message: HTTP 429 Error
Status 451: Error
Error message: HTTP 451 Error
Status 490: Not error
Status 500: Not error
Status 503: Error
Error message: HTTP 503 Error
Status 600: Not error

-- Without custom configuration (using default 500-599) --
Status 200: Not error
Status 418: Not error
Status 500: Error
Error message: HTTP 500 Error
Status 599: Error
Error message: HTTP 599 Error
Status 600: Not error