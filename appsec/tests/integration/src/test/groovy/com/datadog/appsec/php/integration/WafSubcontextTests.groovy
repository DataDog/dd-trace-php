package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import groovy.json.JsonSlurper
import groovy.transform.Canonical
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

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

    static void assert_no_blocking(Trace trace) {
        assert trace != null : "Trace is null"
        Span span = trace.first()
        assert !span.meta.containsKey('appsec.event') : "Unexpected appsec.event found in trace"
        assert !span.meta.containsKey('_dd.appsec.json') : "Unexpected _dd.appsec.json found in trace"
    }

    @Test
    void 'curl_exec with json response block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'

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
    void 'curl_exec with text plain request block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=simple_post_text_plain') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
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
    void 'curl_exec with CURLOPT_FILE'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=file_response') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'
    }

    @Test
    void 'curl_exec with CURLOPT_WRITEFUNCTION'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=writefunction_response') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'
    }

    @Test
    void 'curl_exec with CURLOPT_WRITEHEADER'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=writeheader') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'
    }

    @Test
    void 'curl_exec with query parameter block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=query_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-005'
        assert_triggering_snippet trace, 'http://localhost/example.html?param=blocked_query_param'
    }

    @Test
    void 'curl_exec with URI block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=uri_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-006'
        Span span = trace.first(); def appSecJson = span.parsedAppsecJson; def snippet = appSecJson.triggers[0].rule_matches[0].parameters[0].value; assert snippet.contains('blocked_uri_path') : "Expected snippet to contain 'blocked_uri_path' but got '${snippet}'"
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
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=cookie_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-003b'
        assert_triggering_snippet trace, 'session=blocked_request_cookies'
    }

    @Test
    void 'curl_exec with PUT method block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=method_put_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-007'
        assert_triggering_snippet trace, 'PUT'
    }

    @Test
    void 'curl_exec with PUT method block — variant with CURLOPT_PUT'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=method_put_block_curlopt_put') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-007'
        assert_triggering_snippet trace, 'PUT'
    }

    @Test
    void 'curl_exec with response header block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_header_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-004'
        assert_triggering_snippet trace, 'blocked_response_headers'
    }

    @Test
    void 'curl_exec with response cookie block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_cookie_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }


        assert_triggering_rule trace, 'CUSTOM-004b'
        assert_triggering_snippet trace, 'session=blocked_response_cookies'
    }

    @Test
    void 'curl_multi_exec with request block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_request_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_multi_exec with response block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_response_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'
    }

    @Test
    void 'curl_multi_exec with response block — variant without returntransfer'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_response_block_noreturntransfer') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'
    }

    @Test
    void 'curl_multi_exec with dynamically added handle block'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=multi_exec_dynamic_add_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_copy_handle with blocking request body'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=clone_block_request_body') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-001'
        assert_triggering_snippet trace, 'blocked_request_body'
    }

    @Test
    void 'curl_copy_handle with blocking header'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=clone_block_header') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-003'
        assert_triggering_snippet trace, 'blocked_request_headers'
    }

    @Test
    void 'curl_copy_handle with blocking query parameter'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=clone_block_query') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-005'
        assert_triggering_snippet trace, 'http://localhost/example.html?param=blocked_query_param'
    }

    @Test
    void 'curl_copy_handle with stream does not block due to filter removal'() {
        // This test verifies the technical limitation documented in the code:
        // when a handle with a stream filter is cloned, the filter is removed
        // because shared streams cannot track which handle the data pertains to
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=clone_with_stream_no_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Return from curl_exec:")
        }


        assert_no_blocking trace
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
        // Test that a request body over the 512KB limit (524288 bytes)
        // does NOT trigger blocking, even with the blocking pattern at the end
        // because the body is truncated before the pattern is captured
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=request_body_over_limit_no_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Return from curl_exec:")
        }

        assert_no_blocking trace
    }

    @Test
    void 'response body just under limit triggers blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_under_limit_blocks') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'
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
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=request_body_infile_over_limit_no_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Return from curl_exec:")
        }

        assert_no_blocking trace
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
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=request_body_readfunction_over_limit_no_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Return from curl_exec:")
        }

        assert_no_blocking trace
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
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=request_body_array_over_limit_no_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Return from curl_exec:")
        }

        assert_no_blocking trace
    }

    @Test
    void 'response body CURLOPT_FILE just under limit triggers blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_file_under_limit_blocks') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'
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
    void 'response body CURLOPT_WRITEFUNCTION just under limit triggers blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_writefunction_under_limit_blocks') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains("You've been blocked")
        }

        assert_triggering_rule trace, 'CUSTOM-002'
        assert_triggering_snippet trace, 'blocked_response_body'
    }

    @Test
    void 'response body CURLOPT_WRITEFUNCTION over limit does not trigger blocking'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=response_body_writefunction_over_limit_no_block') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("Response captured via writefunction")
        }

        assert_no_blocking trace
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

    @Canonical
    static class PushAddressCall {
        static JsonSlurper SLURPER = new JsonSlurper()

        Map data
        String subcontextId
        Boolean subcontextLastCall

        static List<PushAddressCall> fromTrace(Trace t) {
            t.findAll { it.resource == 'push_addresses' }
            .sort { it.start }
            .collect {
                def options = SLURPER.parseText(it.meta.'push_call.options' ?: '{}')
                new PushAddressCall([
                        data: SLURPER.parseText(it.meta.'push_call.data' ?: '{}'),
                        subcontextId: options.subctx_id,
                        subcontextLastCall: options.subctx_last_call
                ])
            }
        }
    }

    @Test
    void 'redirect — get with 301'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&code=301&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("This is an html")
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6 // 3 requests

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'GET'
        assert firstReqReqData."server.io.net.url" ==
                'http://127.0.0.1:8899/curl_requests_endpoint.php?variant=forward&code=301&hops=1&final_path=/example.html'

        def firstReqRespData = pushCalls[1].data
        assert firstReqRespData."server.io.net.response.status" == 301
        assert firstReqRespData."server.io.net.response.headers".location[0] ==
                'http://127.0.0.1:8899/curl_requests_endpoint.php?code=301&hops=0&final_path=%2Fexample.html&variant=forward'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'GET'
        assert secondReqReqData."server.io.net.url" ==
                'http://127.0.0.1:8899/curl_requests_endpoint.php?code=301&hops=0&final_path=%2Fexample.html&variant=forward'

        def secondReqRespData = pushCalls[3].data
        assert secondReqRespData."server.io.net.response.status" == 301
        assert secondReqRespData."server.io.net.response.headers".location[0] == '/example.html'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'GET'
        assert thirdReqReqData."server.io.net.url" == 'http://127.0.0.1:8899/example.html'

        def thirdReqRespData = pushCalls[5].data
        assert thirdReqRespData."server.io.net.response.status" == 200

        assert !pushCalls[0].subcontextLastCall
        assert pushCalls[1].subcontextLastCall
        assert pushCalls[0].subcontextId == pushCalls[1].subcontextId

        assert !pushCalls[2].subcontextLastCall
        assert pushCalls[3].subcontextLastCall
        assert pushCalls[2].subcontextId == pushCalls[3].subcontextId
        assert pushCalls[2].subcontextId != pushCalls[0].subcontextId

        assert !pushCalls[4].subcontextLastCall
        assert pushCalls[5].subcontextLastCall
        assert pushCalls[4].subcontextId == pushCalls[5].subcontextId
        assert pushCalls[4].subcontextId != pushCalls[2].subcontextId
    }

    @Test
    void 'redirect — POST with 301 converts to GET by default'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_post&code=301&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'POST'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'GET'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'GET'
    }

    @Test
    void 'redirect — POST with 301 stays POST with POSTREDIR=1'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_post_postredir&code=301&postredir=1&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'POST'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'POST'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'POST'
    }

    @Test
    void 'redirect — POST with 302 converts to GET by default'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_post&code=302&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'POST'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'GET'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'GET'
    }

    @Test
    void 'redirect — POST with 302 stays POST with POSTREDIR=2'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_post_postredir&code=302&postredir=2&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'POST'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'POST'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'POST'
    }

    @Test
    void 'redirect — POST with 303 converts to GET by default'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_post&code=303&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'POST'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'GET'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'GET'
    }

    @Test
    void 'redirect — POST with 303 stays POST with POSTREDIR=4'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_post_postredir&code=303&postredir=4&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'POST'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'POST'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'POST'
    }

    @Test
    void 'redirect — POST with 307 stays POST'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_post&code=307&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'POST'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'POST'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'POST'
    }

    @Test
    void 'redirect — POST with 308 stays POST'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_post&code=308&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'POST'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'POST'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'POST'
    }

    @Test
    void 'redirect — PATCH with 301 stays PATCH'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_patch&code=301&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'PATCH'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'PATCH'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'PATCH'
    }

    @Test
    void 'redirect — PATCH with 302 stays PATCH'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_patch&code=302&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'PATCH'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'PATCH'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'PATCH'
    }

    @Test
    void 'redirect — PATCH with 303 converts to GET'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_patch&code=303&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'PATCH'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'GET'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'GET'
    }

    @Test
    void 'redirect — PATCH with 307 stays PATCH'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_patch&code=307&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'PATCH'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'PATCH'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'PATCH'
    }

    @Test
    void 'redirect — PATCH with 308 stays PATCH'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_patch&code=308&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.method" == 'PATCH'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.method" == 'PATCH'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.method" == 'PATCH'
    }

    @Test
    void 'redirect — auth header and cookie dropped by default'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&code=302&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.headers".authorization == null
        assert firstReqReqData."server.io.net.request.headers".cookie == null

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.headers".authorization == null
        assert secondReqReqData."server.io.net.request.headers".cookie == null
    }

    @Test
    void 'redirect — auth header and cookie kept with UNRESTRICTED_AUTH'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward_auth&code=302&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 6

        def firstReqReqData = pushCalls[0].data
        assert firstReqReqData."server.io.net.request.headers".authorization[0] == 'Bearer test-token'
        assert firstReqReqData."server.io.net.request.headers".cookie[0] == 'session=test-session'

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.request.headers".authorization[0] == 'Bearer test-token'
        assert secondReqReqData."server.io.net.request.headers".cookie[0] == 'session=test-session'

        def thirdReqReqData = pushCalls[4].data
        assert thirdReqReqData."server.io.net.request.headers".authorization[0] == 'Bearer test-token'
        assert thirdReqReqData."server.io.net.request.headers".cookie[0] == 'session=test-session'
    }

    @Test
    void 'redirect — relative path'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&hops=0&final_path=example.html&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("This is an html")
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 4

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.url" ==
                'http://127.0.0.1:8899/example.html'
    }

    @Test
    void 'redirect — relative path with dot normalization'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&hops=0&final_path=./example.html&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("This is an html")
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 4

        // Relative path with . should be normalized to /example.html
        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.url" ==
                'http://127.0.0.1:8899/example.html'
    }

    @Test
    void 'redirect — relative path with parent directory'() {
        // Use path_pattern to construct path server-side to avoid WAF detection
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&hops=0&path_pattern=relative_parent&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("This is an html")
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 4

        // Relative path a/../example.html should be normalized to /example.html
        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.url" ==
                'http://127.0.0.1:8899/example.html'
    }

    @Test
    void 'redirect — absolute path with parent directory'() {
        // Use path_pattern to construct path server-side to avoid WAF detection
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&hops=0&path_pattern=absolute_parent&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("This is an html")
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 4

        // Absolute path /a/../example.html should NOT be normalized - WAF sees raw path
        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.url" ==
                'http://127.0.0.1:8899/a/../example.html'
    }

    @Test
    void 'redirect — relative path with double slash'() {
        // Use path_pattern to construct path server-side to avoid WAF detection
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&hops=0&path_pattern=double_slash&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("This is an html")
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 4

        // Relative path .//example.html should be normalized to /example.html
        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.url" ==
                'http://127.0.0.1:8899/example.html'
    }

    @Test
    void 'redirect — protocol relative URL'() {
        Trace trace = CONTAINER.traceFromRequest('/curl_requests.php?variant=forward&hops=0&final_path=//127.0.0.1:8899/example.html&trace_waf_runs=1') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            String body = resp.body().text
            assert body.contains("This is an html")
        }

        assert_no_blocking trace
        def pushCalls = PushAddressCall.fromTrace(trace)
        assert pushCalls.size() == 4

        def secondReqReqData = pushCalls[2].data
        assert secondReqReqData."server.io.net.url" ==
                'http://127.0.0.1:8899/example.html'
    }
}
