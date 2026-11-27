package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.junit.jupiter.params.ParameterizedTest
import org.junit.jupiter.params.provider.ValueSource
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse
import java.security.MessageDigest

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

@Testcontainers
class WafSubcontextTests {
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

    // What the app's curl callbacks got in a request made with &marker=<marker>
    // (see app_saw() in curl_requests.php): "calls: <n> bytes: <n> blocked
    // data: yes|no"
    static String app_saw(String marker) {
        String saw = null
        CONTAINER.traceFromRequest("/curl_requests.php?variant=app_saw&marker=${marker}") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            saw = resp.body().text.trim()
        }
        saw
    }

    static void assert_no_blocking(Trace trace) {
        assert trace != null : "Trace is null"
        Span span = trace.first()
        assert !span.meta.containsKey('appsec.event') : "Unexpected appsec.event found in trace"
        assert !span.meta.containsKey('_dd.appsec.json') : "Unexpected _dd.appsec.json found in trace"
    }

    @Test
    void 'string-status rule matches the response status'() {
        // CUSTOM-013 accepts only strings (type: string): the status is sent
        // as the string "418", so it matches, and the app gets the whole
        // response (G10.22)
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_status') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains('Return from curl_exec:')
            assert body.contains('Response with a chosen status')
        }

        assert_tagged trace, 'CUSTOM-013'
    }

    @Test
    void 'response-stage tag-only rules give tags and the whole response, never 403'() {
        // CUSTOM-002 (body), CUSTOM-004 (header) and CUSTOM-004b (cookie) are
        // tag-only: each response matches and is tagged, the app's response
        // is a 200 with the whole downstream response, never a 403
        [
                ['/curl_requests.php', 'CUSTOM-002', 'blocked_response_body'],
                ['/curl_requests.php?variant=response_header_block', 'CUSTOM-004', 'Return from curl_exec:'],
                ['/curl_requests.php?variant=response_cookie_block', 'CUSTOM-004b', 'Return from curl_exec:'],
        ].each { String uri, String rule, String expectedInBody ->
            Trace trace = CONTAINER.traceFromRequest(uri) { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
                String body = resp.body().text
                assert body.contains(expectedInBody)
                assert !body.contains("You've been blocked")
            }

            assert_tagged trace, rule
        }
    }

    @Test
    void 'curl_exec with json response match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'

        // RFC-1062: Verify the downstream request metric is set
        Span span = trace.first()
        assert span.metrics.containsKey('_dd.appsec.downstream_request')
        assert span.metrics['_dd.appsec.downstream_request'] == 1.0
    }

    @Test
    void 'curl_exec with json request block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_json') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_exec with urlencoded request block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_urlencoded') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_exec with multipart request block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_multipart') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_exec with text plain request is not blocked'() {
        // text/plain provides no body, raw strings are never sent (G7.6, R-46)
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_text_plain') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_no_blocking trace
    }

    @Test
    void 'curl_exec with CURLOPT_INFILE'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=infile_request') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }


    @Test
    void 'curl_exec with CURLOPT_INFILE — chunked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=infile_request_chunked') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_exec with CURLOPT_READFUNCTION'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=readfunction_request') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_exec with CURLOPT_READFUNCTION — chunked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=readfunction_request_chunked') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_exec with CURLOPT_FILE and response match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=file_response') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }


        assert_tagged trace, 'CUSTOM-002'
    }

    @Test
    void 'curl_exec with CURLOPT_WRITEFUNCTION and response match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=writefunction_response&marker=writefunction_response') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }


        assert_tagged trace, 'CUSTOM-002'
        // the whole body reached the app's callback
        assert app_saw('writefunction_response') ==~ /calls: [1-9]\d* bytes: [1-9]\d* blocked data: yes/
    }

    @Test
    void 'curl_exec with CURLOPT_HEADERFUNCTION and response header match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_header_block_headerfunction&marker=response_header_block_headerfunction') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-004'
        // the app's HEADERFUNCTION and WRITEFUNCTION got every line and byte
        assert app_saw('response_header_block_headerfunction') ==~ /calls: [1-9]\d* bytes: [1-9]\d* blocked data: yes/
    }

    @Test
    void 'curl_exec with CURLOPT_WRITEHEADER and response match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=writeheader') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }


        assert_tagged trace, 'CUSTOM-002'
    }

    @Test
    void 'curl_exec with query parameter block'() {
        assert_blocked_at_w1 'query_block', 'CUSTOM-005', 'http://127.0.0.1:8899/curl_requests_endpoint.php?variant=echo&param=blocked_query_param'
    }

    @Test
    void 'curl_exec with URI block'() {
        Trace trace = assert_blocked_at_w1 'uri_block', 'CUSTOM-006'
        def snippet = trace.first().parsedAppsecJson.triggers[0].rule_matches[0].parameters[0].value
        assert snippet.contains('blocked_uri_path') : "Expected snippet to contain 'blocked_uri_path' but got '${snippet}'"
    }

    @Test
    void 'curl_exec with request header block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=header_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-003'
        assert_triggering_snippet trace, 'blocked_request_headers'
    }

    @Test
    void 'curl_exec with request cookie block'() {
        assert_blocked_at_w1 'cookie_block', 'CUSTOM-003b', 'session=blocked_request_cookies'
    }

    @Test
    void 'curl_exec with PUT method block'() {
        assert_blocked_at_w1 'method_put_block', 'CUSTOM-007', 'PUT'
    }

    @Test
    void 'curl_exec with PUT method block — variant with CURLOPT_PUT'() {
        assert_blocked_at_w1 'method_put_block_curlopt_put', 'CUSTOM-007', 'PUT'
    }

    @Test
    void 'curl_exec with response header match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_header_block') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }


        assert_tagged trace, 'CUSTOM-004'
    }

    @Test
    void 'curl_exec with response cookie match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_cookie_block') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }


        assert_tagged trace, 'CUSTOM-004b'
    }

    @Test
    void 'curl_multi_exec with request block'() {
        assert_blocked_at_w1 'multi_exec_request_block', 'CUSTOM-001', 'blocked_request_body', 'blocked_request_body'
    }

    @Test
    void 'curl_multi_exec with response match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_response_block') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'
    }

    @Test
    void 'curl_multi_exec with response match is not blocked — variant without returntransfer'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_response_block_noreturntransfer') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'
    }

    @Test
    void 'curl_multi_exec with dynamically added handle block'() {
        assert_blocked_at_w1 'multi_exec_dynamic_add_block', 'CUSTOM-001', 'blocked_request_body', 'blocked_request_body'
    }

    @Test
    void 'curl_copy_handle with blocking request body'() {
        assert_blocked_at_w1 'clone_block_request_body', 'CUSTOM-001', 'blocked_request_body', 'blocked_request_body'
    }

    @Test
    void 'curl_copy_handle with blocking header'() {
        assert_blocked_at_w1 'clone_block_header', 'CUSTOM-003', 'blocked_request_headers'
    }

    @Test
    void 'curl_copy_handle with blocking query parameter'() {
        assert_blocked_at_w1 'clone_block_query', 'CUSTOM-005', 'http://127.0.0.1:8899/curl_requests_endpoint.php?variant=echo&param=blocked_query_param'
    }

    @Test
    void 'curl_copy_handle with stream blocking request body'() {
        // the body the copy reads from the shared stream is analysed as sent
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=clone_with_stream_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    // The response-stage rules of the test ruleset are tag-only (output with
    // event and keep false and an attribute, no on_match): a match sets
    // _dd.appsec.test.tag.<rule> and nothing else (G6.9, G10.22)
    static void assert_tagged(Trace trace, String rule) {
        assert trace != null : "Trace is null"
        Span span = trace.first()
        assert span.meta["_dd.appsec.test.tag.${rule}".toString()] == 'matched' :
                "No tag of ${rule} in ${span.meta.keySet()}"
        assert !span.meta.containsKey('appsec.event') : "Unexpected appsec.event: a tag-only rule is no event"
        assert !span.meta.containsKey('_dd.appsec.json') : "Unexpected _dd.appsec.json"
    }

    static void assert_not_tagged(Trace trace, String rule) {
        assert trace != null : "Trace is null"
        assert !trace.first().meta.containsKey("_dd.appsec.test.tag.${rule}".toString()) :
                "Unexpected tag of ${rule}"
    }

    // Request-stage prevention (G10.11, R-35): the application gets the usual
    // block, and the downstream server never received the blocking pattern.
    // The check on the log runs after two sentinel pings (G10.10); its positive
    // controls, the same requests without a rule that matches, are in
    // WafSubcontextNegativeTests.
    static void assert_blocked_and_not_delivered(String query, String rule = 'CUSTOM-001') {
        WafSubContextTestServer.reset(CONTAINER)
        Trace trace = CONTAINER.traceFromRequest("/curl_requests.php?$query") { HttpResponse<InputStream> resp ->
            assert_blocked resp
        }
        assert_triggering_rule trace, rule

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        def leaked = log.findAll { it.body.contains('blocked_request_body') }
        assert leaked.isEmpty() : "the downstream server got the blocked body: ${leaked*.uri}"
    }

    // Request-stage prevention at W1 (G10.11): the application gets the usual
    // block, the rule and the matched value are those expected, and the
    // downstream server's log has no entry for the blocked transfer. When the
    // variant also sends transfers that are not blocked (multi handles), the
    // blocked one is told apart by the pattern in its body (bodyMarker).
    // The positive controls, with no rule that matches, are in
    // WafSubcontextNegativeTests.
    static Trace assert_blocked_at_w1(String variant, String rule, String snippet = null, String bodyMarker = null) {
        WafSubContextTestServer.reset(CONTAINER)
        Trace trace = CONTAINER.traceFromRequest("/curl_requests.php?variant=${variant}") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            // the body stream can be read only once
            String body = resp.body().text
            assert body.contains("You've been blocked")
            // the output buffered by the application is discarded
            assert !body.contains('Handle completed')
        }
        assert_triggering_rule trace, rule
        if (snippet != null) {
            assert_triggering_snippet trace, snippet
        }

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        def leaked = bodyMarker == null ? log.findAll { it.variant == 'echo' } :
                log.findAll { it.body.contains(bodyMarker) }
        assert leaked.isEmpty() : "the blocked request was delivered: ${leaked*.uri}"
        trace
    }

    // What the downstream server logged for the one transfer of the request,
    // when the request body is over the limit and does not block
    static Map assert_delivered_whole(String query) {
        WafSubContextTestServer.reset(CONTAINER)
        Trace trace = CONTAINER.traceFromRequest("/curl_requests.php?$query") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            assert resp.body().text.contains("Return from curl_exec:")
        }
        assert_no_blocking trace

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        assert log.size() == 1 : "expected one request, got ${log*.uri}"
        log[0]
    }

    static String sha1(String s) {
        MessageDigest.getInstance('SHA-1').digest(s.getBytes('UTF-8')).encodeHex().toString()
    }

    // generate_body_over_limit() of curl_requests.php
    static String over_limit_json_body() {
        '{"padding":"' + 'a' * (524288 + 5000) + '","key":"blocked_request_body"}'
    }

    static void assert_whole_json_body(Map entry) {
        String expected = over_limit_json_body()
        assert entry.method == 'POST'
        assert entry.body_length == expected.length()
        assert entry.body_sha1 == sha1(expected)
    }

    @ParameterizedTest(name = '{0}')
    @ValueSource(strings = [
            'simple_post_json',                   // string POSTFIELDS (control)
            'simple_post_multipart',              // array POSTFIELDS (control)
            'infile_request',                     // INFILE, content-length
            'infile_request_chunked',             // INFILE, chunked
            'readfunction_request',               // READFUNCTION, content-length
            'readfunction_request_chunked',       // READFUNCTION, chunked
            'clone_with_stream_block',            // cloned handle with a stream
            'request_body_under_limit_blocks',
            'request_body_infile_under_limit_blocks',
            'request_body_readfunction_under_limit_blocks',
            'request_body_array_under_limit_blocks',
    ])
    void 'request body is blocked before it is delivered'(String variant) {
        assert_blocked_and_not_delivered "variant=${variant}"
    }

    // The hop is blocked at W1: no HTTP byte of it is sent
    @Test
    void 'prevention — a request header blocked at W1 is not delivered'() {
        WafSubContextTestServer.reset(CONTAINER)
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=header_block') { HttpResponse<InputStream> resp ->
            assert_blocked resp
        }
        assert_triggering_rule trace, 'CUSTOM-003'

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        assert log.findAll { it.variant == 'echo' }.isEmpty() : "the request was delivered: ${log*.uri}"
    }

    // The redirect target is blocked at W2: hop 0 was delivered, hop 1 is not
    // sent at all, and the application gets the block, not a curl error. The
    // method and the body of the resent request (307, 308) are the controls.
    @ParameterizedTest(name = '{0}')
    @ValueSource(strings = ['302', '307', '308'])
    void 'prevention — a redirect target blocked at W2 is not delivered'(String code) {
        WafSubContextTestServer.reset(CONTAINER)
        String variant = code == '302' ? 'forward' : 'forward_post'
        Trace trace = CONTAINER.traceFromRequest(
                "/curl_requests.php?variant=${variant}&code=${code}&hops=0&path_pattern=query_only") {
            HttpResponse<InputStream> resp ->
            assert_blocked resp
        }
        assert_triggering_rule trace, 'CUSTOM-008'

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        // positive control: hop 0 is in the log
        assert log.size() == 1 && log[0].variant == 'forward' : "expected hop 0 only, got ${log*.uri}"
        assert log[0].method == (code == '302' ? 'GET' : 'POST')
        assert log.findAll { it.variant == 'ping' }.isEmpty() : "the blocked hop was delivered: ${log*.uri}"
    }

    // Positive control of the previous test: the same redirects to a target
    // that is not blocked deliver both hops, and 307 and 308 resend the body
    @ParameterizedTest(name = '{0}')
    @ValueSource(strings = ['307', '308'])
    void 'a redirect target that is not blocked is delivered with the body resent'(String code) {
        WafSubContextTestServer.reset(CONTAINER)
        Trace trace = CONTAINER.traceFromRequest(
                "/curl_requests.php?variant=forward_post&code=${code}&hops=0" +
                '&final_path=/curl_requests_endpoint.php%3Fvariant%3Decho') {
            HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert_no_blocking trace

        def log = WafSubContextTestServer.receivedAfterSentinels(CONTAINER)
        assert log*.variant == ['forward', 'echo'] : "got ${log*.uri}"
        assert log*.method == ['POST', 'POST']
        assert log*.body == ['test=data', 'test=data']
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

    @Test
    void 'curl_reset clears AppSec context and blocking content'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=reset_clears_blocking') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Return from curl_exec:")
        }

        assert_no_blocking trace
    }

    @Test
    void 'request body just under limit triggers blocking'() {
        // Test that a request body just under the 512KB limit (524288 bytes)
        // with the blocking pattern at the end can still trigger a match and block
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=request_body_under_limit_blocks') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'request body over limit does not trigger blocking'() {
        // the pattern after the body cap does not block, and the downstream
        // server got the length and content the application supplied
        assert_whole_json_body assert_delivered_whole('variant=request_body_over_limit_no_block')
    }

    @Test
    void 'response body just under limit is tagged, not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_under_limit_blocks') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'
    }

    @Test
    void 'response body over limit does not trigger blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_over_limit_no_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Return from curl_exec:")
        }

        assert_no_blocking trace
    }

    @Test
    void 'request body CURLOPT_INFILE just under limit triggers blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=request_body_infile_under_limit_blocks') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'request body CURLOPT_INFILE over limit does not trigger blocking'() {
        // the pattern after the body cap does not block, and the downstream
        // server got the length and content the application supplied
        assert_whole_json_body assert_delivered_whole('variant=request_body_infile_over_limit_no_block')
    }

    @Test
    void 'request body CURLOPT_READFUNCTION just under limit triggers blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=request_body_readfunction_under_limit_blocks') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'request body CURLOPT_READFUNCTION over limit does not trigger blocking'() {
        // the pattern after the body cap does not block, and the downstream
        // server got the length and content the application supplied
        assert_whole_json_body assert_delivered_whole('variant=request_body_readfunction_over_limit_no_block')
    }

    @Test
    void 'request body CURLOPT_POSTFIELDS array just under limit triggers blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=request_body_array_under_limit_blocks') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'request body CURLOPT_POSTFIELDS array over limit does not trigger blocking'() {
        // mime form built by libcurl: the boundary is random, the content is
        // the application's
        def entry = assert_delivered_whole('variant=request_body_array_over_limit_no_block')
        assert entry.method == 'POST'
        assert entry.content_length == entry.body_length.toString()
        assert entry.body_length > 524288 + 5000
        assert entry.body.contains('name="padding"')
        assert entry.body.contains('a' * (524288 + 5000))
        assert entry.body.contains('name="key"')
        assert entry.body.contains('blocked_request_body')
    }

    @Test
    void 'response body CURLOPT_FILE just under limit is tagged, not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_file_under_limit_blocks') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'
    }

    @Test
    void 'response body CURLOPT_FILE over limit does not trigger blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_file_over_limit_no_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Response written to file")
        }

        assert_no_blocking trace
    }

    @Test
    void 'response body CURLOPT_WRITEFUNCTION just under limit is tagged, not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_writefunction_under_limit_blocks&marker=writefunction_under_limit') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'
        // all the chunks of the body reached the app's callback
        assert app_saw('writefunction_under_limit') ==~ /calls: [1-9]\d* bytes: [1-9]\d* blocked data: (yes|no)/
    }

    @Test
    void 'response body CURLOPT_WRITEFUNCTION over limit does not trigger blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_writefunction_over_limit_no_block&marker=writefunction_over_limit') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Response captured via writefunction")
        }

        assert_no_blocking trace
        // the whole body reached the app's callback
        String saw = app_saw('writefunction_over_limit')
        assert saw ==~ /calls: [1-9]\d* bytes: 529332 blocked data: (yes|no)/
    }

    @Test
    void 'response body CURLOPT_FILE over limit is tagged on the analysed prefix, not blocked'() {
        // the body is complete when the limit is reached, before the transfer
        // is; the response headers (with the content type) must still be used
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_file_over_limit_prefix_blocks') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'
    }

    @Test
    void 'response body CURLOPT_WRITEFUNCTION over limit is tagged on the analysed prefix, not blocked'() {
        // the chunk crossing the limit must be appended to what was already
        // buffered, not replace it (which would lose the blocking pattern);
        // and the response headers must be used even though the transfer has
        // not finished when the limit is reached
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_writefunction_over_limit_prefix_blocks&marker=writefunction_prefix') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'
        // the transfer was not aborted: the callback got the whole body
        assert app_saw('writefunction_prefix') ==~ /calls: [1-9]\d* bytes: [1-9]\d* blocked data: (yes|no)/
    }

    @Test
    void 'curl_exec with xml response match is not blocked'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_xml_block') { HttpResponse<InputStream> resp ->
            // the response stage never blocks (G6.9, G10.14): the app gets
            // the whole response, and the match is only tagged
            assert resp.statusCode() == 200
            assert !resp.body().text.contains("You've been blocked")
        }

        assert_tagged trace, 'CUSTOM-002'
    }

    @Test
    void 'multiple downstream requests - only first body analyzed with default limit'() {
        // This test makes 2 downstream curl requests within one user request
        // First: safe body (analyzed, no block)
        // Second: blocking body (NOT analyzed due to limit=1, so no block)
        // If the second body were analyzed, this would return 403 instead of 200
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multiple_downstream_with_body_blocks') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace

        // RFC-1062: Verify the downstream request metric is set
        Span span = trace.first()
        assert span.metrics.containsKey('_dd.appsec.downstream_request')
        assert span.metrics['_dd.appsec.downstream_request'] == 1.0
    }

    // The redirect tests check what the WAF sees for the hops after the first
    // one through rules of the test ruleset that only match on the last hop
    // (their URL ends with a redirect_check=... marker): CUSTOM-008 (exact
    // URL), CUSTOM-009 (method), CUSTOM-010 (credentials), CUSTOM-011 and
    // CUSTOM-012 (subcontext isolation).

    static def rule_match_values(Trace trace, String rule) {
        Span span = trace.first()
        def appSecJson = span.parsedAppsecJson
        def triggers = appSecJson.triggers.findAll { it.rule.id == rule }
        assert triggers.size() == 1 : "Expected one trigger of ${rule}, got ${appSecJson.triggers*.rule.id}"
        triggers[0].rule_matches.collect { it.parameters[0].value }
    }

    static void assert_blocked(HttpResponse<InputStream> resp) {
        assert resp.statusCode() == 403
        assert resp.body().text.contains("You've been blocked")
    }

    static void assert_redirect_target(String query, String expectedUrl) {
        // that the blocked hop is not delivered is checked on the downstream
        // server's log, see 'prevention' below
        Trace trace = CONTAINER.traceFromRequest("/curl_requests.php?$query") { HttpResponse<InputStream> resp ->
            assert_blocked resp
        }

        assert_triggering_rule trace, 'CUSTOM-008'
        assert_triggering_snippet trace, expectedUrl
    }

    static void assert_last_hop_method(String query, String expectedMethod) {
        Trace trace = CONTAINER.traceFromRequest("/curl_requests.php?$query") { HttpResponse<InputStream> resp ->
            assert_blocked resp
        }

        assert_triggering_rule trace, 'CUSTOM-009'
        assert rule_match_values(trace, 'CUSTOM-009') ==
                ['http://127.0.0.1:8899/example.html?redirect_check=method', expectedMethod]
    }

    private static final String METHOD_CHECK = 'final_path=/example.html%3Fredirect_check%3Dmethod'

    @Test
    void 'redirect — get with 301'() {
        // two redirects with absolute locations, then the target
        assert_redirect_target(
                'variant=forward&code=301&final_path=/example.html%3Fredirect_check%3Durl',
                'http://127.0.0.1:8899/example.html?redirect_check=url')
    }

    @Test
    void 'redirect — hops are evaluated in separate subcontexts'() {
        // CUSTOM-011 would match if the Location header of an earlier hop
        // were evaluated together with the URL of the last one; CUSTOM-012
        // matches the last hop's own response
        Trace trace = CONTAINER.traceFromRequest(
                '/curl_requests.php?variant=forward&code=301&final_path=/example.html%3Fredirect_check%3Disolation') {
            HttpResponse<InputStream> resp ->
            // response stage: tags only, the app gets the final page
            assert resp.statusCode() == 200
            assert resp.body().text.contains("This is an html")
        }

        assert_tagged trace, 'CUSTOM-012'
        assert_not_tagged trace, 'CUSTOM-011'
    }

    @Test
    void 'redirect hops in own subcontext, no header or cookie match at hop 1 or more'() {
        // The app sets a header and a cookie. Hop 0 evaluates them
        // (CUSTOM-014, CUSTOM-015 are tagged). The last hop (reached after the redirects of hops=2)
        // is evaluated in its own subcontext, which has no request headers:
        // CUSTOM-014b and CUSTOM-015b, which need the header or the cookie
        // together with the URL of the last hop, do not match (G10.22)
        Trace trace = CONTAINER.traceFromRequest(
                '/curl_requests.php?variant=forward_hop_headers&code=301&hops=2&final_path=/example.html%3Fredirect_check%3Dhops') {
            HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            assert resp.body().text.contains("This is an html")
        }

        assert_tagged trace, 'CUSTOM-014'
        assert_tagged trace, 'CUSTOM-015'
        // the last hop was evaluated in its own subcontext: a rule on its
        // URL alone matches, so the absence of 014b and 015b is not vacuous
        assert_tagged trace, 'CUSTOM-014c'
        assert_not_tagged trace, 'CUSTOM-014b'
        assert_not_tagged trace, 'CUSTOM-015b'
    }

    @Test
    void 'redirect — POST with 301 converts to GET by default'() {
        assert_last_hop_method("variant=forward_post&code=301&$METHOD_CHECK", 'GET')
    }

    @Test
    void 'redirect — POST with 301 stays POST with POSTREDIR=1'() {
        assert_last_hop_method("variant=forward_post_postredir&code=301&postredir=1&$METHOD_CHECK", 'POST')
    }

    @Test
    void 'redirect — POST with 302 converts to GET by default'() {
        assert_last_hop_method("variant=forward_post&code=302&$METHOD_CHECK", 'GET')
    }

    @Test
    void 'redirect — POST with 302 stays POST with POSTREDIR=2'() {
        assert_last_hop_method("variant=forward_post_postredir&code=302&postredir=2&$METHOD_CHECK", 'POST')
    }

    @Test
    void 'redirect — POST with 303 converts to GET by default'() {
        assert_last_hop_method("variant=forward_post&code=303&$METHOD_CHECK", 'GET')
    }

    @Test
    void 'redirect — POST with 303 stays POST with POSTREDIR=4'() {
        assert_last_hop_method("variant=forward_post_postredir&code=303&postredir=4&$METHOD_CHECK", 'POST')
    }

    @Test
    void 'redirect — POST with 307 stays POST'() {
        assert_last_hop_method("variant=forward_post&code=307&$METHOD_CHECK", 'POST')
    }

    @Test
    void 'redirect — POST with 308 stays POST'() {
        assert_last_hop_method("variant=forward_post&code=308&$METHOD_CHECK", 'POST')
    }

    @Test
    void 'redirect — PATCH with 301 stays PATCH'() {
        assert_last_hop_method("variant=forward_patch&code=301&$METHOD_CHECK", 'PATCH')
    }

    @Test
    void 'redirect — PATCH with 302 stays PATCH'() {
        assert_last_hop_method("variant=forward_patch&code=302&$METHOD_CHECK", 'PATCH')
    }

    @Test
    void 'redirect — PATCH with 303 stays PATCH'() {
        // libcurl sends the CURLOPT_CUSTOMREQUEST method on every hop
        assert_last_hop_method("variant=forward_patch&code=303&$METHOD_CHECK", 'PATCH')
    }

    @Test
    void 'redirect — PATCH with 307 stays PATCH'() {
        assert_last_hop_method("variant=forward_patch&code=307&$METHOD_CHECK", 'PATCH')
    }

    @Test
    void 'redirect — PATCH with 308 stays PATCH'() {
        assert_last_hop_method("variant=forward_patch&code=308&$METHOD_CHECK", 'PATCH')
    }

    @Test
    void 'redirect — auth header and cookie dropped by default'() {
        // nothing sets credentials: none reach the last hop
        Trace trace = CONTAINER.traceFromRequest(
                '/curl_requests.php?variant=forward&code=302&final_path=/example.html%3Fredirect_check%3Dauth') {
            HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            assert resp.body().text.contains("This is an html")
        }

        assert_no_blocking trace
    }

    @Test
    void 'redirect — no header or cookie match at a hop of 1 or more'() {
        // UNRESTRICTED_AUTH keeps the credentials on the wire for the last
        // hop, but a hop of 1 or more is evaluated in its own subcontext with
        // only its URL and method (R-28, G10.22): CUSTOM-010, which needs the
        // URL and both headers, does not match
        Trace trace = CONTAINER.traceFromRequest(
                '/curl_requests.php?variant=forward_auth&code=302&final_path=/example.html%3Fredirect_check%3Dauth') {
            HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            assert resp.body().text.contains("This is an html")
        }

        assert_no_blocking trace
        // the last hop was evaluated in its own subcontext: a rule on its URL
        // alone matches, so the absence of a block is not vacuous
        assert_tagged trace, 'CUSTOM-010c'
    }

    @Test
    void 'redirect — relative path'() {
        assert_redirect_target(
                'variant=forward&hops=0&final_path=example.html%3Fredirect_check%3Durl',
                'http://127.0.0.1:8899/example.html?redirect_check=url')
    }

    @Test
    void 'redirect — relative path with dot normalization'() {
        // Relative path with . should be normalized to /example.html
        assert_redirect_target(
                'variant=forward&hops=0&final_path=./example.html%3Fredirect_check%3Durl',
                'http://127.0.0.1:8899/example.html?redirect_check=url')
    }

    @Test
    void 'redirect — relative path with parent directory'() {
        // Use path_pattern to construct path server-side to avoid WAF detection
        // Relative path a/../example.html should be normalized to /example.html
        assert_redirect_target(
                'variant=forward&hops=0&path_pattern=relative_parent',
                'http://127.0.0.1:8899/example.html?redirect_check=url')
    }

    @Test
    void 'redirect — absolute path with parent directory'() {
        // Use path_pattern to construct path server-side to avoid WAF detection
        // libcurl removes the dot segments of /a/../example.html too
        assert_redirect_target(
                'variant=forward&hops=0&path_pattern=absolute_parent',
                'http://127.0.0.1:8899/example.html?redirect_check=url')
    }

    @Test
    void 'redirect — query-only location keeps the path'() {
        // ?variant=ping... replaces only the query of the original URL (RFC 3986, 5.2.2)
        assert_redirect_target(
                'variant=forward&hops=0&path_pattern=query_only',
                'http://127.0.0.1:8899/curl_requests_endpoint.php?variant=ping&redirect_check=url')
    }

    @Test
    void 'redirect — relative path with double slash'() {
        // Use path_pattern to construct path server-side to avoid WAF detection
        // Relative path .//example.html resolves to //example.html (RFC 3986,
        // 5.2.4), which is what libcurl requests
        assert_redirect_target(
                'variant=forward&hops=0&path_pattern=double_slash',
                'http://127.0.0.1:8899//example.html?redirect_check=url')
    }

    @Test
    void 'redirect — protocol relative URL'() {
        assert_redirect_target(
                'variant=forward&hops=0&final_path=//127.0.0.1:8899/example.html%3Fredirect_check%3Durl',
                'http://127.0.0.1:8899/example.html?redirect_check=url')
    }
}
