package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.junit.jupiter.params.ParameterizedTest
import org.junit.jupiter.params.provider.CsvSource
import org.junit.jupiter.params.provider.ValueSource
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
string(4) "GET:"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with URI no block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=uri_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            assert resp.body().text == 'Return from curl_exec:\nstring(4) "GET:"\n'
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
string(4) "GET:"
'''
            assert resp.body().text == expected
        }

        assert_no_blocking trace
    }


    // Positive controls of the prevention tests of WafSubcontextTests (G10.10,
    // G10.11): with no rule that matches, each of these requests delivers its
    // body, and the downstream server's log has the line that the prevention
    // tests do not find. The columns: how the body is framed, and its content.
    @ParameterizedTest(name = '{0}')
    @CsvSource(delimiter = '|', value = [
            'simple_post_json             | length  | {"key":"blocked_request_body"}',
            'simple_post_multipart        | length  |',
            'infile_request               | length  | {"key": "blocked_request_body"}',
            'infile_request_chunked       | chunked | {"key": "blocked_request_body"}',
            'readfunction_request         | length  | {"key": "blocked_request_body"}',
            'readfunction_request_chunked | chunked | {"key": "blocked_request_body"}',
            'clone_with_stream_block      | chunked | {"key":"blocked_request_body"}',
    ])
    void 'request body is delivered when nothing blocks'(String variant, String framing, String content) {
        WafSubContextTestServer.reset(CONTAINER)
        Trace trace = CONTAINER.traceFromRequest("/curl_requests.php?variant=${variant}") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert_no_blocking trace

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        assert log.size() == 1 : "expected one request, got ${log*.uri}"
        def entry = log[0]
        assert entry.method == 'POST'
        assert entry.body.contains('blocked_request_body')
        if (content != null) {
            assert entry.body == content
        }
        if (framing == 'chunked') {
            assert entry.transfer_encoding == 'chunked'
            assert entry.content_length == null
        } else {
            assert entry.transfer_encoding == null
            assert entry.content_length == entry.body_length.toString()
        }
    }

    // Controls for the variants over the limit and just under it
    @ParameterizedTest(name = '{0}')
    @ValueSource(strings = [
            'request_body_under_limit_blocks',
            'request_body_infile_under_limit_blocks',
            'request_body_readfunction_under_limit_blocks',
            'request_body_array_under_limit_blocks',
    ])
    void 'request body just under the limit is delivered when nothing blocks'(String variant) {
        WafSubContextTestServer.reset(CONTAINER)
        Trace trace = CONTAINER.traceFromRequest("/curl_requests.php?variant=${variant}") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert_no_blocking trace

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        assert log.size() == 1 : "expected one request, got ${log*.uri}"
        assert log[0].method == 'POST'
        assert log[0].body.contains('blocked_request_body')
        assert log[0].body_length > 520000
    }

    // Positive controls of the W1 prevention tests of WafSubcontextTests (the
    // request is blocked before any byte is sent): with no rule that matches,
    // each of these requests reaches the downstream server. The columns: the
    // number of requests the variant sends, the method of the one that
    // carries the pattern, and the text of that request's URI or body.
    @ParameterizedTest(name = '{0}')
    @CsvSource(delimiter = '|', value = [
            'query_block                  | 1 | GET | param=blocked_query_param',
            'uri_block                    | 1 | GET | /blocked_uri_path',
            'header_block                 | 1 | GET | variant=echo',
            'cookie_block                 | 1 | GET | variant=echo',
            'method_put_block             | 1 | PUT | variant=echo',
            'method_put_block_curlopt_put | 1 | PUT | variant=echo',
            'multi_exec_request_block     | 2 | POST | blocked_request_body',
            'multi_exec_dynamic_add_block | 2 | POST | blocked_request_body',
            'clone_block_request_body     | 1 | POST | blocked_request_body',
            'clone_block_header           | 1 | GET | variant=echo',
            'clone_block_query            | 1 | GET | param=blocked_query_param',
    ])
    void 'request blocked at W1 is delivered when nothing blocks'(String variant, int count, String method,
                                                                 String text) {
        WafSubContextTestServer.reset(CONTAINER)
        CONTAINER.traceFromRequest("/curl_requests.php?variant=${variant}") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        assert log.size() == count : "expected ${count} requests, got ${log*.uri}"
        assert log.any { it.method == method && (it.uri.contains(text) || it.body.contains(text)) } :
                "no ${method} request with ${text} in ${log*.uri}"
    }

    // Positive control of the W1/W2 prevention tests: the header and the
    // redirect target are delivered
    @Test
    void 'request header and redirect target are delivered when nothing blocks'() {
        WafSubContextTestServer.reset(CONTAINER)
        CONTAINER.traceFromRequest('/curl_requests.php?variant=header_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&code=302&hops=0&path_pattern=query_only') {
            HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        assert log*.variant == ['echo', 'forward', 'ping'] : "got ${log*.uri}"
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
