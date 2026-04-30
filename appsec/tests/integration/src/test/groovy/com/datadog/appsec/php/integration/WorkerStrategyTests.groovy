package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.model.Mapper
import com.datadog.appsec.php.model.Span
import com.fasterxml.jackson.databind.JsonNode
import com.fasterxml.jackson.databind.ObjectMapper
import com.fasterxml.jackson.databind.node.ArrayNode
import com.fasterxml.jackson.databind.node.ObjectNode
import org.junit.jupiter.api.Test

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.test.JsonMatcher.matchesJson
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.hamcrest.MatcherAssert.assertThat
import static org.junit.jupiter.api.Assumptions.assumeTrue

trait WorkerStrategyTests {
    private static final ObjectMapper JSON_MAPPER = new ObjectMapper()

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    abstract boolean getCanBlockOnResponse()
    abstract CharSequence getComponent()

    /**
     * Normalizes key_path arrays in the actual JSON to use integers for array indices.
     * libddwaf v1.x returned strings like "3", v2.x returns integers like 3.
     * This normalizes to the v2.x format (integers) for consistent comparison.
     */
    private static String normalizeKeyPathInJson(String json) {
        JsonNode node = JSON_MAPPER.readTree(json)
        normalizeKeyPathNode(node)
        JSON_MAPPER.writeValueAsString(node)
    }

    private static void normalizeKeyPathNode(JsonNode node) {
        if (node.isObject()) {
            ObjectNode objNode = (ObjectNode) node
            node.fields().each { entry ->
                if (entry.key == 'key_path' && entry.value.isArray()) {
                    ArrayNode normalizedArray = JSON_MAPPER.createArrayNode()
                    entry.value.each { element ->
                        if (element.isTextual() && element.asText().matches(/\d+/)) {
                            normalizedArray.add(element.asText().toInteger())
                        } else {
                            normalizedArray.add(element)
                        }
                    }
                    objNode.set('key_path', normalizedArray)
                } else {
                    normalizeKeyPathNode(entry.value)
                }
            }
        } else if (node.isArray()) {
            node.each { element -> normalizeKeyPathNode(element) }
        }
    }

    @Test
    void 'produces two traces for two requests'() {
        def trace1 = container.traceFromRequest('/') { HttpResponse<InputStream> it ->
            assert it.statusCode() == 200
            assert it.headers().firstValue('Content-type').get().startsWith('text/plain')
            assert it.body().text == 'Hello world!'
        }
        def trace2 = container.traceFromRequest('/')
        assert trace1.size() == 1
        assert trace2.size() == 1

        assert trace1[0].meta['component'] == component
        assert trace1[0].meta['http.client_ip'] instanceof String
        assert trace1[0].meta['_dd.appsec.event_rules.version'] =~ /\d+\.\d+\.\d+/
        assert trace1[0].metrics['_dd.appsec.enabled'] == 1.0d
        assert trace1[0].meta['http.status_code'] == '200'

        assert trace2[0].meta['http.status_code'] == '200'
    }

    @Test
    void 'blocking json on request start'() {
        HttpRequest req = container.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.80').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.body().contains('"title":"You\'ve been blocked"')
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
        HttpRequest req = container.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.80')
                .header('Accept', 'text/html')
                .GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.body().contains('<title>You\'ve been blocked</title>')
            assert re.statusCode() == 403
            assert re.headers().firstValue('Content-type').get().contains('text/html')
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking forward on request start'() {
        HttpRequest req = container.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.81').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 303
            assert re.headers().firstValue('Location').get() == 'https://datadoghq.com'
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking user with html response'() {
        HttpRequest req = container.buildReq('/?user=user2020')
                .header('Accept', 'text/html').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.body().contains('<title>You\'ve been blocked</title>')
            assert it.statusCode() == 403
            assert it.headers().firstValue('Content-type').get().contains('text/html')
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking user with redirect'() {
        HttpRequest req = container.buildReq('/?user=user2023').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.statusCode() == 303
            assert it.headers().firstValue('Location').get() == 'https://datadoghq.com'
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking on response with html'() {
        assumeTrue(canBlockOnResponse)
        HttpRequest req = container.buildReq('/?status=418')
                .header('Accept', 'text/html').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.body().contains('<title>You\'ve been blocked</title>')
            assert it.statusCode() == 403
            assert it.headers().firstValue('Content-type').get().contains('text/html')
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }

    @Test
    void 'match against json response body'() {
        HttpRequest req = container.buildReq('/json').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == '{"message":["Hello world!",42,true,"poison"]}'
        }

        Span span = trace.first()

        def appsecJson = normalizeKeyPathInJson(span.meta."_dd.appsec.json")
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
                                3
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
        assumeTrue(canBlockOnResponse)
        HttpRequest req = container.buildReq('/json?block=1').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body().containsIgnoreCase("You've been blocked")
            assert resp.headers().firstValue('content-type').get() == 'application/json'
        }

        Span span = trace.first()

        def appsecJson = normalizeKeyPathInJson(span.meta."_dd.appsec.json")
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
                                3
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
        HttpRequest req = container.buildReq('/xml').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body().contains('poison')
        }

        Span span = trace.first()

        def appsecJson = normalizeKeyPathInJson(span.meta."_dd.appsec.json")
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
                                2
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
        HttpRequest req = container.buildReq('/')
                .header('Content-type', 'application/json')
                .POST(HttpRequest.BodyPublishers.ofString(json)).build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }

        Span span = trace.first()
        assert span.meta['http.request.headers.content-type'] == 'application/json'
        assert span.meta['http.request.headers.content-length'] == '45'

        def appsecJson = normalizeKeyPathInJson(span.meta."_dd.appsec.json")
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
                                3
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
        HttpRequest req = container.buildReq('/')
                .header('Content-type', 'application/xml')
                .POST(HttpRequest.BodyPublishers.ofString(xml)).build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }

        Span span = trace.first()

        def appsecJson = normalizeKeyPathInJson(span.meta."_dd.appsec.json")
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
                                2
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