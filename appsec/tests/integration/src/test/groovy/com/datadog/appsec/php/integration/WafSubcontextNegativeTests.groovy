package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers
import org.testcontainers.utility.MountableFile

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

@Testcontainers
@EnabledIf('isEnabled')
class WafSubcontextNegativeTests {
    static boolean enabled = !variant.contains('zts') && phpVersion.contains('8.4')

    private static final int TEST_SERVER_PORT = 8899

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    @BeforeAll
    static void startTestServer() {
        new WafSubContextTestServer(CONTAINER, TEST_SERVER_PORT).start()
    }

    @BeforeAll
    static void replaceRules() {
        // bind mount empty rule set
        CONTAINER.copyFileToContainer(MountableFile.forClasspathResource('empty_ruleset.json'),
                '/etc/empty_ruleset.json')
        CONTAINER.execInContainer('mount', '-o', 'bind', '/etc/empty_ruleset.json',
                '/etc/recommended.json')
        // without appsec we should have the same results
        // CONTAINER.execInContainer('sed', '-i', '/appsec/d', '/etc/php/php.ini')
    }


    @Test
    void 'curl_exec with json response no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(39) "{
    "key": "blocked_response_body"
}
"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with json request no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_json') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(35) "POST:{"key":"blocked_request_body"}"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with urlencoded request no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_urlencoded') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(29) "POST:key=blocked_request_body"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with multipart request no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_multipart') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def text = resp.body().text
            assert text.contains('POST:')
            assert text.contains('Content-Disposition: form-data; name="key"')
            assert text.contains('blocked_request_body')
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with text plain request no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_text_plain') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(25) "POST:blocked_request_body"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with CURLOPT_INFILE no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=infile_request') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(36) "POST:{"key": "blocked_request_body"}"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }


    @Test
    void 'curl_exec with CURLOPT_INFILE — chunked no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=infile_request_chunked') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(36) "POST:{"key": "blocked_request_body"}"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with CURLOPT_READFUNCTION no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=readfunction_request') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(36) "POST:{"key": "blocked_request_body"}"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with CURLOPT_READFUNCTION — chunked no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=readfunction_request_chunked') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(36) "POST:{"key": "blocked_request_body"}"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with CURLOPT_FILE no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=file_response') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Response written to /tmp/curl_outfile_response.json:
{
    "key": "blocked_response_body"
}
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with CURLOPT_WRITEFUNCTION no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=writefunction_response') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Response from writefunction curl request:
{
    "key": "blocked_response_body"
}
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with CURLOPT_WRITEHEADER no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=writeheader') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(39) "{
    "key": "blocked_response_body"
}
"
Headers:
HTTP/1.1 200 OK'''
            def body = resp.body().text
            assert body.startsWith(expected)
            assert body.contains('Content-Type: application/json')
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with query parameter no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=query_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(262) "<!DOCTYPE html>
<html lang="en">
<meta charset="UTF-8">
<title>Page Title</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="">
<style>
</style>
<script src=""></script>
<body>
This is an html
</body>
</html>"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with URI no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=uri_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            assert resp.body().text.contains('The requested URL was not found on this server.')
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with request header no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=header_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(45) "X-Custom-Header: blocked_request_headers
GET:"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with request cookie no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=cookie_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(44) "Cookie-session: blocked_request_cookies
GET:"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with PUT method no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=method_put_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(13) "PUT:test data"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with PUT method no block — variant with CURLOPT_PUT'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=method_put_block_curlopt_put') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(13) "PUT:test data"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with response header no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_header_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(29) "Response with blocking header"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with response cookie no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_cookie_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(29) "Response with blocking cookie"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_multi_exec with request no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_request_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = resp.body().text
            assert body.contains('Handle completed with response:')
            assert body.contains('POST:{"key":"blocked_request_body"}')
            assert body.contains('POST:safe content')
            assert body.contains('Multi-exec completed')
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_multi_exec with response no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_response_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = resp.body().text
            assert body.contains('Handle completed via info_read')
            assert body.contains('Response:')
            assert body.contains('GET:')
            assert body.contains('blocked_response_body')
            assert body.contains('Multi-exec info_read completed')
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_multi_exec with response no block — variant without returntransfer'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_response_block_noreturntransfer') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = resp.body().text
            assert body.contains('Handle completed via info_read')
            assert body.contains('Multi-exec info_read completed')
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_multi_exec with dynamically added handle no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_dynamic_add_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = resp.body().text
            assert body.contains('Handle completed with response:')
            assert body.contains('GET:')
            assert body.contains('POST:{"key":"blocked_request_body"}')
            assert body.contains('Multi-exec dynamic add completed')
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_copy_handle with request body no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=clone_block_request_body') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(35) "POST:{"key":"blocked_request_body"}"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_copy_handle with header no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=clone_block_header') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(45) "X-Custom-Header: blocked_request_headers
GET:"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_copy_handle with query parameter no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=clone_block_query') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def expected = '''\
Return from curl_exec:
string(262) "<!DOCTYPE html>
<html lang="en">
<meta charset="UTF-8">
<title>Page Title</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="">
<style>
</style>
<script src=""></script>
<body>
This is an html
</body>
</html>"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }


    static void assert_no_blocking(Trace trace) {
        assert trace != null : "Trace is null"
        Span span = trace.first()
        assert !span.meta.containsKey('appsec.event') : "Unexpected appsec.event found in trace"
        assert !span.meta.containsKey('_dd.appsec.json') : "Unexpected _dd.appsec.json found in trace"
    }

    static void assert_triggering_rule(Trace trace, String expectedRule) {
        assert trace != null : "Trace is null"
        Span span = trace.first()
        assert span.meta.containsKey('appsec.event') && span.meta.'appsec.event' == 'true' : "No appsec.event in trace"
        def appSecJson = span.parsedAppsecJson
        def actualRule = appSecJson.triggers[0].rule.id
        assert actualRule == expectedRule : "Expected rule ${expectedRule} but got ${actualRule}"
    }

    static void assert_triggering_snippet(Trace trace, String expectedSnippet) {
        assert trace != null : "Trace is null"
        Span span = trace.first()
        assert span.meta.containsKey('appsec.event') && span.meta.'appsec.event' == 'true' : "No appsec.event in trace"
        def appSecJson = span.parsedAppsecJson
        def actualSnippet = appSecJson.triggers[0].rule_matches[0].parameters[0].value
        assert actualSnippet == expectedSnippet : "Expected snippet '${expectedSnippet}' but got '${actualSnippet}'"
    }
}
