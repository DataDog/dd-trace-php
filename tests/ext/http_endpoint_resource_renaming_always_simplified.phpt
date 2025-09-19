--TEST--
HTTP endpoint resource renaming with DD_TRACE_RESOURCE_RENAMING_ALWAYS_SIMPLIFIED_ENDPOINT=true
--ENV--
DD_TRACE_RESOURCE_RENAMING_ENABLED=true
DD_TRACE_RESOURCE_RENAMING_ALWAYS_SIMPLIFIED_ENDPOINT=true
DD_TRACE_AUTO_FLUSH_ENABLED=false
--FILE--
<?php
use DDTrace\SpanData;

function test_endpoint_with_route($path, $route) {
    $root_span = DDTrace\start_trace_span();
    $root_span->name = 'web.request';
    $root_span->service = 'test-service';
    $root_span->resource = 'GET ' . $path;
    $root_span->type = 'web';
    $root_span->meta['http.url'] = 'http://localhost' . $path;
    $root_span->meta['http.method'] = 'GET';
    if ($route !== null) {
        $root_span->meta['http.route'] = $route;
    }

    DDTrace\close_span();

    $spans = dd_trace_serialize_closed_spans();
    if (count($spans) > 0) {
        $span_data = $spans[0];
        if ($route !== null) {
            echo "Path: ", $path, ", Route: ", $route, "\n";
        } else {
            echo "Path: ", $path, ", No Route\n";
        }
        if (isset($span_data['meta']['http.endpoint'])) {
            echo "Endpoint: ", $span_data['meta']['http.endpoint'], "\n";
        } else {
            echo "Endpoint: (not set)\n";
        }
        if (isset($span_data['meta']['http.route'])) {
            echo "Route: ", $span_data['meta']['http.route'], "\n";
        } else {
            echo "Route: (not set)\n";
        }
        echo "\n";
    } else {
        echo "Path: ", $path, " - No spans\n\n";
    }
}

// Test that with always_simplified_endpoint=true, http.endpoint is calculated even when http.route is set
test_endpoint_with_route("/users/123", "/users/{id}");

// Test that http.endpoint is still calculated when no route is set
test_endpoint_with_route("/users/456", null);

echo "Done.\n";
?>
--EXPECTF--
Path: /users/123, Route: /users/{id}
Endpoint: /users/{param:int}
Route: /users/{id}

Path: /users/456, No Route
Endpoint: /users/{param:int}
Route: (not set)

Done.