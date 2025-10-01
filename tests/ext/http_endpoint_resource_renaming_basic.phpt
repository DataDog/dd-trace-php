--TEST--
HTTP endpoint resource renaming with DD_TRACE_RESOURCE_RENAMING_ENABLED=true
--ENV--
DD_TRACE_RESOURCE_RENAMING_ENABLED=true
DD_TRACE_AUTO_FLUSH_ENABLED=false
--FILE--
<?php
use DDTrace\SpanData;

//sleep(10);

function test_endpoint($path) {
    // create a root span manually
    $root_span = DDTrace\start_trace_span();
    $root_span->name = 'web.request';
    $root_span->service = 'test-service';
    $root_span->resource = 'GET ' . $path;
    $root_span->type = 'web';
    $root_span->meta['http.url'] = 'http://localhost' . $path;
    $root_span->meta['http.method'] = 'GET';

    DDTrace\close_span();

    $spans = dd_trace_serialize_closed_spans();
    if (count($spans) > 0) {
        $span_data = $spans[0];
        echo "Path: $path\n";
        if (isset($span_data['meta']['http.endpoint'])) {
            echo "Endpoint: " . $span_data['meta']['http.endpoint'] . "\n";
        } else {
            echo "Endpoint: (not set)\n";
        }
        echo "\n";
    } else {
        echo "Path: $path - No spans\n\n";
    }
    dd_trace_reset();
}

// Test invalid inputs and root
test_endpoint("");
test_endpoint("abc");
test_endpoint("/");
test_endpoint("////");

// Test skips empty components and strips query
test_endpoint("/a//b");
test_endpoint("/a/b?x=y");

// Test int and int_id replacement
test_endpoint("/users/12");
test_endpoint("/v1/0-1_2.3");
test_endpoint("/x/09"); // leading zero not int
test_endpoint("/1"); // single digit not int/int_id

// Test hex and hex_id replacement
test_endpoint("/x/abcde9");
test_endpoint("/x/ab_cd-9");

// Test str replacement by special or length
test_endpoint("/x/a%z");
$long_path = "/x/" . str_repeat('a', 20);
test_endpoint($long_path);

// Test other specials yield str
$specials = ['&', "'", '(', ')', '*', '+', ',', ':', '=', '@'];
foreach ($specials as $special) {
    $path = "/x/a{$special}b";
    test_endpoint($path);
}

// Test max components limit
test_endpoint("/11/22/33/44/55/66/77/88/99/12");

// Test minimum length boundaries
test_endpoint("/x/0-"); // int_id requires length >= 3
test_endpoint("/x/0__");
test_endpoint("/x/abcd9"); // hex requires length >= 6
test_endpoint("/x/ab_c9"); // hex_id requires length >= 6
test_endpoint("/x/ab_cd9");
test_endpoint("/x/" . str_repeat('a', 19)); // str requires length >= 20

// Test that http.route suppresses http.endpoint calculation
function test_endpoint_with_route($path, $route) {
    $root_span = DDTrace\start_trace_span();
    $root_span->name = 'web.request';
    $root_span->service = 'test-service';
    $root_span->resource = 'GET ' . $path;
    $root_span->type = 'web';
    $root_span->meta['http.url'] = 'http://localhost' . $path;
    $root_span->meta['http.method'] = 'GET';
    $root_span->meta['http.route'] = $route;

    DDTrace\close_span();

    $spans = dd_trace_serialize_closed_spans();
    if (count($spans) > 0) {
        $span_data = $spans[0];
        echo "Path: $path, Route: $route\n";
        if (isset($span_data['meta']['http.endpoint'])) {
            echo "Endpoint: " . $span_data['meta']['http.endpoint'] . "\n";
        } else {
            echo "Endpoint: (not set)\n";
        }
        echo "\n";
    } else {
        echo "Path: $path, Route: $route - No spans\n\n";
    }
}

test_endpoint_with_route("/users/123", "/users/{id}");

echo "Done.\n";
?>
--EXPECTF--
Path: 
Endpoint: /

Path: abc
Endpoint: /

Path: /
Endpoint: /

Path: ////
Endpoint: /

Path: /a//b
Endpoint: /a/b

Path: /a/b?x=y
Endpoint: /a/b

Path: /users/12
Endpoint: /users/{param:int}

Path: /v1/0-1_2.3
Endpoint: /v1/{param:int_id}

Path: /x/09
Endpoint: /x/09

Path: /1
Endpoint: /1

Path: /x/abcde9
Endpoint: /x/{param:hex}

Path: /x/ab_cd-9
Endpoint: /x/{param:hex_id}

Path: /x/a%z
Endpoint: /x/{param:str}

Path: /x/aaaaaaaaaaaaaaaaaaaa
Endpoint: /x/{param:str}

Path: /x/a&b
Endpoint: /x/{param:str}

Path: /x/a'b
Endpoint: /x/{param:str}

Path: /x/a(b
Endpoint: /x/{param:str}

Path: /x/a)b
Endpoint: /x/{param:str}

Path: /x/a*b
Endpoint: /x/{param:str}

Path: /x/a+b
Endpoint: /x/{param:str}

Path: /x/a,b
Endpoint: /x/{param:str}

Path: /x/a:b
Endpoint: /x/{param:str}

Path: /x/a=b
Endpoint: /x/{param:str}

Path: /x/a@b
Endpoint: /x/{param:str}

Path: /11/22/33/44/55/66/77/88/99/12
Endpoint: /{param:int}/{param:int}/{param:int}/{param:int}/{param:int}/{param:int}/{param:int}/{param:int}

Path: /x/0-
Endpoint: /x/0-

Path: /x/0__
Endpoint: /x/{param:int_id}

Path: /x/abcd9
Endpoint: /x/abcd9

Path: /x/ab_c9
Endpoint: /x/ab_c9

Path: /x/ab_cd9
Endpoint: /x/{param:hex_id}

Path: /x/aaaaaaaaaaaaaaaaaaa
Endpoint: /x/aaaaaaaaaaaaaaaaaaa

Path: /users/123, Route: /users/{id}
Endpoint: (not set)

Done.
