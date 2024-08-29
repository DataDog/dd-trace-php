package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Mapper
import com.datadog.appsec.php.model.Span
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.containers.wait.strategy.WaitStrategy
import org.testcontainers.containers.wait.strategy.WaitStrategyTarget
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.time.Duration

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static com.datadog.appsec.php.integration.TestParams.phpVersionAtLeast
import static com.datadog.appsec.php.test.JsonMatcher.matchesJson
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.hamcrest.MatcherAssert.assertThat

@Testcontainers
@EnabledIf('isExpectedVersion')
class RoadRunnerTests {
    static boolean expectedVersion = phpVersionAtLeast('7.4') && !variant.contains('zts')

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'roadrunner',
            ).with {
                // we only start listening on http after run.sh has finished,
                // so mark immediately the container as ready. We instead check for liveliness
                // in beforeAll()
                setWaitStrategy(new WaitStrategy() {
                    @Override
                    void waitUntilReady(WaitStrategyTarget waitStrategyTarget) {
                        // we're good. Allow run.sh to run
                    }

                    @Override
                    WaitStrategy withStartupTimeout(Duration startupTimeout) {
                        this
                    }
                })
                it
            }

    @BeforeAll
    static void beforeAll() {
        // wait until roadrunner is running
        long deadline = System.currentTimeMillis() + 300_000
        while (CONTAINER.execInContainer('grep', 'http server was started', '/tmp/logs/rr.log').exitCode != 0) {
            if (System.currentTimeMillis() > deadline) {
                throw new RuntimeException('Roadrunner did not start on time (see output of run.sh)')
            }
            Thread.sleep(500)
        }
    }

    @Test
    void 'produces two traces for two requests'() {
        def trace1 = CONTAINER.traceFromRequest('/') { HttpResponse<InputStream> it ->
            assert it.statusCode() == 200
            assert it.headers().firstValue('Content-type').get() == 'text/plain'
            assert it.body().text == 'Hello world!'
        }
        def trace2 = CONTAINER.traceFromRequest('/')
        assert trace1.size() == 1
        assert trace2.size() == 1

        assert trace1[0].meta['component'] == 'roadrunner'
        assert trace1[0].meta['http.client_ip'] instanceof String
        assert trace1[0].meta['_dd.appsec.event_rules.version'] =~ /\d+\.\d+\.\d+/
        assert trace1[0].metrics['_dd.appsec.enabled'] == 1.0d
        assert trace1[0].meta['http.status_code'] == '200'

        assert trace2[0].meta['http.status_code'] == '200'
    }

    @Test
    void 'blocking json on request start'() {
        HttpRequest req = CONTAINER.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.80').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.body().contains('"title": "You\'ve been blocked"')
            assert re.statusCode() == 403
            assert re.headers().firstValue('Content-type').get() == 'application/json'
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
        assert span.meta."_dd.appsec.json" != null
        assert span.meta.'http.status_code' == '403'
        def triggers = Mapper.INSTANCE.readerFor(Map).readValue(span.meta."_dd.appsec.json")
        assert triggers['triggers'][0]['rule']['name'] == 'Block IP Addresses'
    }

    @Test
    void 'blocking html on request start'() {
        HttpRequest req = CONTAINER.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.80')
                .header('Accept', 'text/html')
                .GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.body().contains('<title>You\'ve been blocked</title>')
            assert re.statusCode() == 403
            assert re.headers().firstValue('Content-type').get().contains('text/html')
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking forward on request start'() {
        HttpRequest req = CONTAINER.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.81').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 303
            assert re.headers().firstValue('Location').get() == 'datadoghq.com'
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking user with html response'() {
        HttpRequest req = CONTAINER.buildReq('/?user=user2020')
                .header('Accept', 'text/html').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.body().contains('<title>You\'ve been blocked</title>')
            assert it.statusCode() == 403
            assert it.headers().firstValue('Content-type').get().contains('text/html')
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking user with redirect'() {
        HttpRequest req = CONTAINER.buildReq('/?user=user2023').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.statusCode() == 303
            assert it.headers().firstValue('Location').get() == 'datadoghq.com'
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking on response with html'() {
        HttpRequest req = CONTAINER.buildReq('/?status=418')
                .header('Accept', 'text/html').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.body().contains('<title>You\'ve been blocked</title>')
            assert it.statusCode() == 403
            assert it.headers().firstValue('Content-type').get().contains('text/html')
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }

    @Test
    void 'match against json response body'() {
        HttpRequest req = CONTAINER.buildReq('/json').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == '{"message":["Hello world!",42,true,"poison"]}'
        }

        Span span = trace.first()

        def appsecJson = span.meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : {
                    "id" : "poison-in-json",
                    "name" : "poison-in-json",
                    "tags" : {
                       "category" : "attack_attempt",
                       "type" : "security_scanner"
                    }
                 },
                 "rule_matches" : [
                    {
                       "operator" : "match_regex",
                       "operator_value" : "(?i)poison",
                       "parameters" : [
                          {
                             "address" : "server.response.body",
                             "highlight" : [
                                "poison"
                             ],
                             "key_path" : [
                                "message",
                                "3"
                             ],
                             "value" : "poison"
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)
    }


    @Test
    void 'blocking against a json response body'() {
        HttpRequest req = CONTAINER.buildReq('/json?block=1').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body().containsIgnoreCase("You've been blocked")
            assert resp.headers().firstValue('content-type').get() == 'application/json'
        }

        Span span = trace.first()

        def appsecJson = span.meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : {
                    "id" : "poison-in-json-block",
                    "name" : "poison-in-json-block",
                    "on_match" : [
                       "block"
                    ],
                    "tags" : {
                       "category" : "attack_attempt",
                       "type" : "security_scanner"
                    }
                 },
                 "rule_matches" : [
                    {
                       "operator" : "match_regex",
                       "operator_value" : "(?i)block_this",
                       "parameters" : [
                          {
                             "address" : "server.response.body",
                             "highlight" : [
                                "block_this"
                             ],
                             "key_path" : [
                                "message",
                                "3"
                             ],
                             "value" : "block_this"
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)
    }

    @Test
    void 'match against xml response body'() {
        HttpRequest req = CONTAINER.buildReq('/xml').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body().contains('poison')
        }

        Span span = trace.first()

        def appsecJson = span.meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : {
                    "id" : "poison-in-xml",
                    "name" : "poison-in-xml",
                    "tags" : {
                       "category" : "attack_attempt",
                       "type" : "security_scanner"
                    }
                 },
                 "rule_matches" : [
                    {
                       "operator" : "match_regex",
                       "operator_value" : "(?i).*poison.*",
                       "parameters" : [
                          {
                             "address" : "server.response.body",
                             "highlight" : [
                                "  poison"
                             ],
                             "key_path" : [
                                "note",
                                "2"
                             ],
                             "value" : "\\n  poison\\n"
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)
    }


    @Test
    void 'match against json request body'() {
        def json = '{"message":["Hello world!",42,true,"poison"]}'
        HttpRequest req = CONTAINER.buildReq('/')
                .header('Content-type', 'application/json')
                .POST(HttpRequest.BodyPublishers.ofString(json)).build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }

        Span span = trace.first()
        assert span.meta['http.request.headers.content-type'] == 'application/json'
        assert span.meta['http.request.headers.content-length'] == '45'

        def appsecJson = span.meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : {
                    "id" : "poison-in-json",
                    "name" : "poison-in-json",
                    "tags" : {
                       "category" : "attack_attempt",
                       "type" : "security_scanner"
                    }
                 },
                 "rule_matches" : [
                    {
                       "operator" : "match_regex",
                       "operator_value" : "(?i)poison",
                       "parameters" : [
                          {
                             "address" : "server.request.body",
                             "highlight" : [
                                "poison"
                             ],
                             "key_path" : [
                                "message",
                                "3"
                             ],
                             "value" : "poison"
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)
    }

    @Test
    void 'match against xml request body'() {
        def xml = '''<?xml version="1.0" encoding="UTF-8"?>
            <note foo="bar">
              <from>Jean</from>poison</note>'''
        HttpRequest req = CONTAINER.buildReq('/')
                .header('Content-type', 'application/xml')
                .POST(HttpRequest.BodyPublishers.ofString(xml)).build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }

        Span span = trace.first()

        def appsecJson = span.meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : {
                    "id" : "poison-in-xml",
                    "name" : "poison-in-xml",
                    "tags" : {
                       "category" : "attack_attempt",
                       "type" : "security_scanner"
                    }
                 },
                 "rule_matches" : [
                    {
                       "operator" : "match_regex",
                       "operator_value" : "(?i).*poison.*",
                       "parameters" : [
                          {
                             "address" : "server.request.body",
                             "highlight" : [
                                "poison"
                             ],
                             "key_path" : [
                                "note",
                                "2"
                             ],
                             "value" : "poison"
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''

        assertThat appsecJson, matchesJson(expJson, false, true)
    }
}
