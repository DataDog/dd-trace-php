package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.mock_agent.MsgpackHelper
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.Test
import org.junit.jupiter.params.ParameterizedTest
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;
import org.testcontainers.containers.Container

import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.util.stream.Stream;
import org.msgpack.core.MessageUnpacker
import org.msgpack.core.MessagePack

import static com.datadog.appsec.php.test.JsonMatcher.matchesJson
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.hamcrest.MatcherAssert.assertThat

trait CommonTests {

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    @Test
    void 'user tracking'() {
        def trace = container.traceFromRequest('/user_id.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."usr.id" == '123456789'
        assert span.meta."usr.name" == 'Jean Example'
        assert span.meta."usr.email" == 'jean.example@example.com'
        assert span.meta."usr.session_id" == '987654321'
        assert span.meta."usr.role" == 'admin'
        assert span.meta."usr.scope" == 'read:message, write:files'
    }

    @Test
    void 'user signup event'() {
        Trace trace = container.traceFromRequest('/user_signup.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."appsec.events.users.signup.usr.id" == 'Admin'
        assert span.meta."appsec.events.users.signup.usr.login" == 'Admin'
        assert span.meta."appsec.events.users.signup.track" == 'true'
        assert span.meta."appsec.events.users.signup.email" == 'jean.example@example.com'
        assert span.meta."appsec.events.users.signup.session_id" == '987654321'
        assert span.meta."appsec.events.users.signup.role" == 'admin'
    }

    @Test
    void 'user signup event automated'() {
        Trace trace = container.traceFromRequest('/user_signup_automated.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."appsec.events.users.signup.usr.id" == 'Admin'
        assert span.meta."appsec.events.users.signup.usr.login" == 'Login'
        assert span.meta."_dd.appsec.usr.id" == 'Admin'
        assert span.meta."_dd.appsec.usr.login" == 'Login'
        assert span.meta."appsec.events.users.signup.track" == 'true'
        assert span.meta."_dd.appsec.events.users.signup.auto.mode" == 'identification'
    }

    @Test
    void 'user login success event'() {
        Trace trace = container.traceFromRequest('/user_login_success.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."usr.id" == 'Admin'
        assert span.meta."appsec.events.users.login.success.usr.login" == 'Admin'
        assert span.meta."appsec.events.users.login.success.track" == 'true'
        assert span.meta."appsec.events.users.login.success.email" == 'jean.example@example.com'
        assert span.meta."appsec.events.users.login.success.session_id" == '987654321'
        assert span.meta."appsec.events.users.login.success.role" == 'admin'
    }

    @Test
    void 'user login success event automated'() {
        Trace trace = container.traceFromRequest('/user_login_success_automated.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."usr.id" == 'Admin'
        assert span.meta."appsec.events.users.login.success.usr.login" == 'Login'
        assert span.meta."_dd.appsec.usr.id" == 'Admin'
        assert span.meta."_dd.appsec.usr.login" == 'Login'
        assert span.meta."appsec.events.users.login.success.track" == 'true'
        assert span.meta."_dd.appsec.events.users.login.success.auto.mode" == 'identification'
    }

    @Test
    void 'sdk v2 user login success event'() {
        def trace = container.traceFromRequest('/user_login_success_v2.php?login=Admin&id=user_id') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        
        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."appsec.events.users.login.success.usr.login" == "Admin"
        assert span.meta."appsec.events.users.login.success.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.success.sdk" == "true"
        assert span.meta."_dd.appsec.user.collection_mode" == "sdk"
        assert span.meta."appsec.events.users.login.success.usr.id" == "user_id"
        assert span.meta."appsec.events.users.login.success.metakey1" == "metavalue"
        assert span.meta."appsec.events.users.login.success.metakey2" == "metavalue02"
        assert span.meta."usr.id" == "user_id"
        assert span.meta."usr.metakey1" == "metavalue"
        assert span.meta."usr.metakey2" == "metavalue02"
        assert span.meta."_dd.p.ts" == "02"
    }

    @Test
    void 'user login failure event'() {
        def trace = container.traceFromRequest('/user_login_failure.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."appsec.events.users.login.failure.usr.id" == 'Admin'
        assert span.meta."appsec.events.users.login.failure.usr.login" == 'Admin'
        assert span.meta."appsec.events.users.login.failure.usr.exists" == 'false'
        assert span.meta."appsec.events.users.login.failure.track" == 'true'
        assert span.meta."appsec.events.users.login.failure.email" == 'jean.example@example.com'
        assert span.meta."appsec.events.users.login.failure.session_id" == '987654321'
        assert span.meta."appsec.events.users.login.failure.role" == 'admin'
    }

    @Test
    void 'sdk v2 user login failure event'() {
        def trace = container.traceFromRequest('/user_login_failure_v2.php?login=login') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."appsec.events.users.login.failure.usr.login" == "login"
        assert span.meta."appsec.events.users.login.failure.usr.exists" == "true"
        assert span.meta."appsec.events.users.login.failure.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.failure.sdk" == "true"
        assert span.meta."appsec.events.users.login.failure.metakey1" == "metavalue"
        assert span.meta."appsec.events.users.login.failure.metakey2" == "metavalue02"
    }

    @Test
    void 'user login failure event automated'() {
        def trace = container.traceFromRequest('/user_login_failure_automated.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."appsec.events.users.login.failure.usr.id" == 'Admin'
        assert span.meta."_dd.appsec.usr.id" == 'Admin'
        assert span.meta."_dd.appsec.usr.login" == 'Login'
        assert span.meta."appsec.events.users.login.failure.usr.login" == 'Login'
        assert span.meta."appsec.events.users.login.failure.usr.exists" == 'false'
        assert span.meta."appsec.events.users.login.failure.track" == 'true'
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == 'identification'
    }

    @Test
    void 'authenticated user event automated'() {
        def trace = container.traceFromRequest('/behind_auth.php?id=userID') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 < 2.0d
        assert span.meta."usr.id" == 'userID'
        assert span.meta."_dd.appsec.usr.id" == 'userID'
        assert span.meta."_dd.appsec.user.collection_mode" == 'identification'
    }

    @Test
    void 'custom event'() {
        def trace = container.traceFromRequest('/custom_event.php') { resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."appsec.events.custom_event.track" == 'true'
        assert span.meta."appsec.events.custom_event.metadata0" == 'value0'
        assert span.meta."appsec.events.custom_event.metadata1" == 'value1'
        assert span.meta."appsec.events.custom_event.metadata2" == 'value2'
    }

    @Test
    void 'sanity check against non PHP endpoint'() {
        HttpRequest req = container.buildReq('/example.html').GET().build()
        HttpResponse<String> res = container.httpClient.send(req, ofString())
        assert res.statusCode() == 200
    }

    @Test
    void 'trace without attack'() {
        def trace = container.traceFromRequest('/phpinfo.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def content = resp.body().text
            assert content.contains('module_ddtrace')
            assert content.contains('module_ddappsec')
        }

        Span span = trace.first()
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
    }

    @Test
    void 'trace with an attack'() {
        HttpRequest req = container.buildReq('/hello.php')
                .header('User-Agent', 'Arachni/v1').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        Span span = trace.first()

        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.runtime_family" == 'php'
        assert span.meta."http.useragent" == 'Arachni/v1'
        def appsecJson = span.meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule_matches" : [
                    {
                       "parameters" : [
                          {
                             "highlight" : [
                                "Arachni/v"
                             ],
                             "value" : "Arachni/v1",
                             "address" : "server.request.headers.no_cookies",
                             "key_path" : [
                                "user-agent"
                             ]
                          }
                       ],
                       "operator" : "match_regex",
                       "operator_value" : "^Arachni\\\\/v"
                    }
                 ],
                 "rule" : {
                    "name" : "Arachni"
                 }
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)
    }

    Span assert_blocked_span(Span span) {
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
        assert span.meta."appsec.blocked" == "true"

        return span
    }

    @Test
    void 'test blocking json'() {
        // Set ip which is blocked
        HttpRequest req = container.buildReq('/phpinfo.php')
                .header('Content-type', 'application/json')
                .header('Accept', 'application/json')
                .header('X-Forwarded-For', '80.80.80.80').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            def body = new groovy.json.JsonSlurper().parseText(re.body())
            assert body.errors[0].title == "You've been blocked"
            assert body.errors[0].detail == "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."
            assert body.security_response_id ==~ /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/
        }

        Span span = trace.first()

        this.assert_blocked_span(span)
    }

    @Test
    void 'test blocking html'() {
        // Set ip which is blocked
        HttpRequest req = container.buildReq('/phpinfo.php')
                .header('Content-type', 'application/html')
                .header('Accept', 'text/html')
                .header('X-Forwarded-For', '80.80.80.80').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403

            assert re.body().contains('You\'ve been blocked')
            assert re.body().contains('Sorry, you cannot access this page. Please contact the customer service team.')
            assert re.body() =~ /Security Response ID: ([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/
        }

        Span span = trace.first()

        this.assert_blocked_span(span)
    }

    @Test
    void 'test blocking and stack generation'() {
        HttpRequest req = container.buildReq('/generate_stack.php?id=stack_user').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            assert re.body().contains('blocked')
        }

        Span span = trace.first()
        assert_blocked_span(span)

        InputStream stream = new ByteArrayInputStream( span.meta_struct."_dd.stack".decodeBase64() )
        MessageUnpacker unpacker = MessagePack.newDefaultUnpacker(stream)
        List<Object> stacks = []
        stacks << MsgpackHelper.unpackSingle(unpacker)
        Object exploit = stacks.first().exploit.first()

        assert exploit.language == "php"
        assert exploit.id ==~ /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/
        assert exploit.frames[0].file == "generate_stack.php"
        assert exploit.frames[0].function == "one"
        assert exploit.frames[0].id == 0
        assert exploit.frames[0].line == 8
        assert exploit.frames[1].file == "generate_stack.php"
        assert exploit.frames[1].function == "two"
        assert exploit.frames[1].id == 1
        assert exploit.frames[1].line == 12
        assert exploit.frames[2].file == "generate_stack.php"
        assert exploit.frames[2].function == "three"
        assert exploit.frames[2].id == 2
        assert exploit.frames[2].line == 15
    }

    @Test
    void 'test stack generation without blocking'() {
        HttpRequest req = container.buildReq('/generate_stack.php?id=stack_user_no_block').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
        }

        Span span = trace.first()

        assert span.meta."appsec.event" == 'true'

        InputStream stream = new ByteArrayInputStream( span.meta_struct."_dd.stack".decodeBase64() )
        MessageUnpacker unpacker = MessagePack.newDefaultUnpacker(stream)
        List<Object> stacks = []
        stacks << MsgpackHelper.unpackSingle(unpacker)
        Object exploit = stacks.first().exploit.first()

        assert exploit.language == "php"
        assert exploit.id ==~ /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/
        assert exploit.frames[0].file == "generate_stack.php"
        assert exploit.frames[0].function == "one"
        assert exploit.frames[0].id == 0
        assert exploit.frames[0].line == 8
        assert exploit.frames[1].file == "generate_stack.php"
        assert exploit.frames[1].function == "two"
        assert exploit.frames[1].id == 1
        assert exploit.frames[1].line == 12
        assert exploit.frames[2].file == "generate_stack.php"
        assert exploit.frames[2].function == "three"
        assert exploit.frames[2].id == 2
        assert exploit.frames[2].line == 15
    }

     static Stream<Arguments> getTestLfiData() {
            return Arrays.stream(new Arguments[]{
                    Arguments.of("file_put_contents", "/tmp/dummy", 9),
                    Arguments.of("readfile", "/tmp/dummy", 15),
                    Arguments.of("file_get_contents", "/tmp/dummy", 15),
                    Arguments.of("fopen", "/tmp/dummy", 12),
            });
     }

     @ParameterizedTest
     @MethodSource("getTestLfiData")
        void 'filesystem functions generate LFI signal'(String target_function, String path, Integer line) {
            HttpRequest req = container.buildReq('/filesystem.php?function='+target_function+"&path="+path).GET().build()
            def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 200
                assert re.body().contains('OK')
            }

            Span span = trace.first()

            assert span.metrics."_dd.appsec.enabled" == 1.0d
            assert span.metrics."_dd.appsec.waf.duration" > 0.0d
            assert span.meta."_dd.appsec.event_rules.version" != ''

            InputStream stream = new ByteArrayInputStream( span.meta_struct."_dd.stack".decodeBase64() )
            MessageUnpacker unpacker = MessagePack.newDefaultUnpacker(stream)
            List<Object> stacks = []
            stacks << MsgpackHelper.unpackSingle(unpacker)
            Object exploit = stacks.first().exploit.first()

            assert exploit.language == "php"
            assert exploit.id ==~ /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/
            assert exploit.frames[0].file == "filesystem.php"
            assert exploit.frames[0].function == target_function
            assert exploit.frames[0].id == 1
            assert exploit.frames[0].line == line
            assert exploit.frames[1].file == "filesystem.php"
            assert exploit.frames[1].function == "one"
            assert exploit.frames[1].id == 2
            assert exploit.frames[1].line == 21
            assert exploit.frames[2].file == "filesystem.php"
            assert exploit.frames[2].function == "two"
            assert exploit.frames[2].id == 3
            assert exploit.frames[2].line == 25
            assert exploit.frames[3].file == "filesystem.php"
            assert exploit.frames[3].function == "three"
            assert exploit.frames[3].id == 4
            assert exploit.frames[3].line == 28
        }

    @Test
    void 'multiple rasp'() {
        def trace = container.traceFromRequest(
            '/multiple_rasp.php?path=../somefile&other=../otherfile&domain=169.254.169.254') {HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics."_dd.appsec.rasp.rule.eval" == 5.0d
    }

    @Test
    void 'user blocking'() {
        def trace = container.traceFromRequest('/user_id.php?id=user2020') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403

            def content = resp.body().text
            assert content.contains('blocked')
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
    }

    @Test
    void 'user login success blocking'() {
        def trace = container.traceFromRequest('/user_login_success.php?id=user2020') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains('blocked')
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
    }

    @Test
    void 'sdk v2 user login failure blocking'() {
        def trace = container.traceFromRequest('/user_login_failure_v2.php?login=login2020') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains('blocked')
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
    }

    @Test
    void 'sdk v2 user login success blocking'() {
        def trace = container.traceFromRequest('/user_login_success_v2.php?login=login&id=user2020') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains('blocked')
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
    }

    @Test
    void 'user login fingerprint'() {
        def trace = container.traceFromRequest('/user_login_success.php?id=user2020') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
            assert resp.body().text.contains('blocked')
        }

        Span span = trace.first()
        assert span.meta."_dd.appsec.fp.http.endpoint" ==~ /^http-get(-[a-zA-Z0-9]*){3}$/
        assert span.meta."_dd.appsec.fp.http.header" ==~ /^hdr(-[0-9]*-[a-zA-Z0-9]*){2}$/
        assert span.meta."_dd.appsec.fp.http.network" ==~ /^net-[0-9]*-[a-zA-Z0-9]*$/
        assert span.meta."_dd.appsec.fp.session" ==~ /^ssn(-[a-zA-Z0-9]*){4}$/
    }

    @Test
    void 'user redirecting'() {
        def trace = container.traceFromRequest('/user_id.php?id=user2023') { HttpResponse<InputStream> conn ->
            assert conn.statusCode() == 303
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
    }

    @Test
    void 'user login success redirecting'() {
        def trace = container.traceFromRequest('/user_login_success.php?id=user2023') { HttpResponse<InputStream> conn ->
            assert conn.statusCode() == 303
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
    }

    @Test
    void 'test redirecting'() {
        // Set ip which is set to be redirected
        def req = container.buildReq('/phpinfo.php')
                .header('X-Forwarded-For', '80.80.80.81').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> conn ->
            assert conn.statusCode() == 303
            assert conn.body().size() == 0
        }

        Span span = trace.first()
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
        assert span.meta."appsec.blocked" == "true"
    }

    @Test
    void 'match against json response body'() {
        HttpRequest req = container.buildReq('/parseable_resp_entity.php?json=1').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
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
    void 'match against xml response body'() {
        HttpRequest req = container.buildReq('/parseable_resp_entity.php?xml=1').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
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
    void 'POST request sets content type and length'() {
        def json = '{"message":["Hello world!"]}'
        HttpRequest req = container.buildReq('/hello.php')
                .header('Content-type', 'application/json')
                .POST(HttpRequest.BodyPublishers.ofString(json)).build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }

        Span span = trace.first()
        assert span.meta['http.request.headers.content-type'] == 'application/json'
        assert span.meta['http.request.headers.content-length'] == '28'
    }

    @Test
    void 'module does not have STATIC_TLS flag'() {
        Container.ExecResult res = container.execInContainer(
                'bash', '-c',
                '''! { readelf -d "$(DD_TRACE_CLI_ENABLED=0 php -r 'echo ini_get("extension_dir");')"/ddappsec.so | grep STATIC_TLS; }''')
        if (res.exitCode != 0) {
            throw new AssertionError("Module has STATIC_TLS flag: $res.stdout")
        }
    }

    static Stream<Arguments> getTestSsrfData() {
            return Arrays.stream(new Arguments[]{
                    Arguments.of("file_get_contents", 19),
                    Arguments.of("fopen", 16),
            });
     }

    @ParameterizedTest
    @MethodSource("getTestSsrfData")
       void 'filesystem functions generate SSRF signal'(String target_function, Integer line) {
           HttpRequest req = container.buildReq('/ssrf.php?function='+target_function+"&domain=169.254.169.254").GET().build()
           def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 200
                assert re.body().contains('OK')
            }

            Span span = trace.first()

            assert span.metrics."_dd.appsec.enabled" == 1.0d
            assert span.metrics."_dd.appsec.waf.duration" > 0.0d
            assert span.meta."_dd.appsec.event_rules.version" != ''

            InputStream stream = new ByteArrayInputStream( span.meta_struct."_dd.stack".decodeBase64() )
            MessageUnpacker unpacker = MessagePack.newDefaultUnpacker(stream)
            List<Object> stacks = []
            stacks << MsgpackHelper.unpackSingle(unpacker)
            Object exploit = stacks.first().exploit.first()

            assert exploit.language == "php"
            assert exploit.id ==~ /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/
            assert exploit.frames[0].file == "ssrf.php"
            assert exploit.frames[0].function == target_function
            assert exploit.frames[0].id == 1
            assert exploit.frames[0].line == line
            assert exploit.frames[1].file == "ssrf.php"
            assert exploit.frames[1].function == "one"
            assert exploit.frames[1].id == 2
            assert exploit.frames[1].line == 29
            assert exploit.frames[2].file == "ssrf.php"
            assert exploit.frames[2].function == "two"
            assert exploit.frames[2].id == 3
            assert exploit.frames[2].line == 34
            assert exploit.frames[3].file == "ssrf.php"
            assert exploit.frames[3].function == "three"
            assert exploit.frames[3].id == 4
            assert exploit.frames[3].line == 37
    }

    @Test
    void 'tagging rule with attributes, no keep and no event'() {
        HttpRequest req = container.buildReq('/hello.php')
                .header('User-Agent', 'TraceTagging/v1').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        Span span = trace.first()

        assert span.metrics._sampling_priority_v1 < 2.0d
        assert span.meta."http.useragent" == "TraceTagging/v1"
        assert span.metrics."_dd.appsec.trace.integer" == 662607015
        assert span.metrics."_dd.appsec.trace.float" == 12.34d
        assert span.meta."_dd.appsec.trace.string" == "678"
        assert span.meta."_dd.appsec.trace.agent" == "TraceTagging/v1"
    }

    @Test
    void 'tagging rule with attributes, sampling priority user_keep and no event'() {
        HttpRequest req = container.buildReq('/hello.php')
                .header('User-Agent', 'TraceTagging/v2').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        Span span = trace.first()

        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."http.useragent" == "TraceTagging/v2"
        assert span.metrics."_dd.appsec.trace.integer" == 602214076
        assert span.meta."_dd.appsec.trace.agent" == "TraceTagging/v2"
    }

    @Test
    void 'tagging rule with attributes, sampling priority user_keep and an event'() {
        HttpRequest req = container.buildReq('/hello.php')
                .header('User-Agent', 'TraceTagging/v3').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        Span span = trace.first()

        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."http.useragent" == "TraceTagging/v3"
        assert span.metrics."_dd.appsec.trace.integer" == 299792458
        assert span.meta."_dd.appsec.trace.agent" == "TraceTagging/v3"
    }

    @Test
    void 'tagging rule with attributes and an event, but no sampling priority change'() {
        HttpRequest req = container.buildReq('/hello.php')
                .header('User-Agent', 'TraceTagging/v4').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        Span span = trace.first()

        assert span.metrics._sampling_priority_v1 < 2.0d
        assert span.meta."http.useragent" == "TraceTagging/v4"
        assert span.metrics."_dd.appsec.trace.integer" == 1729
        assert span.meta."_dd.appsec.trace.agent" == "TraceTagging/v4"
    }
}
