 --TEST--
 HTTP server error status code configuration
 --ENV--
 DD_TRACE_HTTP_SERVER_ERROR_STATUSES=403,418,429-451,503
 DD_TRACE_GENERATE_ROOT_SPAN=0
 --FILE--
 <?php
 // Function to create a test span with specific status code
 function checkSpanError($status) {
     // Create a span
     $span = \DDTrace\start_span();

     // Set HTTP metadata
     $span->meta['http.url'] = "http://example.com/test/$status";
     $span->meta['http.status_code'] = (string)$status;

     // Close the span
     \DDTrace\close_span();

     // Get spans data
     $spans_json = dd_trace_serialize_closed_spans();
     $spans = json_decode($spans_json, true);

     if (empty($spans)) {
         echo "Error: No spans were created\n";
         return;
     }

     // Check error flag and type
     $span_data = $spans[0];
     $is_error = isset($span_data['error']) && $span_data['error'] == 1;
     $error_type = isset($span_data['meta']['error.type']) ? $span_data['meta']['error.type'] : 'none';

     echo "Status $status: ";
     echo $is_error ? "Marked as error" : "Not marked as error";
     if ($is_error) {
         echo ", error.type=$error_type";
     }
     echo "\n";
 }

 // Test with custom configuration
 echo "-- Testing with custom HTTP status error configuration --\n";
 checkSpanError(200); // Should not be an error
 checkSpanError(403); // Should be an error
 checkSpanError(418); // Should be an error
 checkSpanError(429); // Should be an error (range)
 checkSpanError(451); // Should be an error (range boundary)
 checkSpanError(500); // Should not be an error with custom config
 checkSpanError(503); // Should be an error

 // Test default behavior
 putenv('DD_TRACE_HTTP_SERVER_ERROR_STATUSES=');
 echo "\n-- Testing with default configuration --\n";
 checkSpanError(200); // Should not be an error
 checkSpanError(403); // Should not be an error
 checkSpanError(500); // Should be an error
 checkSpanError(503); // Should be an error
 ?>
 --EXPECTF--
 -- Testing with custom HTTP status error configuration --
 Status 200: Not marked as error
 Status 403: Marked as error, error.type=http_error
 Status 418: Marked as error, error.type=http_error
 Status 429: Marked as error, error.type=http_error
 Status 451: Marked as error, error.type=http_error
 Status 500: Not marked as error
 Status 503: Marked as error, error.type=http_error

 -- Testing with default configuration --
 Status 200: Not marked as error
 Status 403: Not marked as error
 Status 500: Marked as error, error.type=http_error
 Status 503: Marked as error, error.type=http_error