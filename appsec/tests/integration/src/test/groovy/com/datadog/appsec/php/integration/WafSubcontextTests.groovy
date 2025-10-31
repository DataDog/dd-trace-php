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

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

@Testcontainers
@EnabledIf('isEnabled')
class WafSubcontextTests {
    static boolean enabled = variant.contains('zts') && phpVersion.contains('7.4') ||
            !variant.contains('zts') && phpVersion.contains('8.3')


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
        assert_triggering_snippet trace, 'blocked_query_param'
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
        assert_triggering_snippet trace, 'blocked_request_cookies'
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
        assert_triggering_snippet trace, 'blocked_response_cookies'
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
        assert_triggering_snippet trace, 'blocked_query_param'
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
}
