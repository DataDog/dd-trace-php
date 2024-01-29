package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.Test
import org.testcontainers.containers.Container

import java.net.http.HttpRequest
import java.net.http.HttpResponse

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
    void 'user login success event'() {
        Trace trace = container.traceFromRequest('/user_login_success.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."usr.id" == 'Admin'
        assert span.meta."appsec.events.users.login.success.track" == 'true'
        assert span.meta."appsec.events.users.login.success.email" == 'jean.example@example.com'
        assert span.meta."appsec.events.users.login.success.session_id" == '987654321'
        assert span.meta."appsec.events.users.login.success.role" == 'admin'
    }

    @Test
    void 'user login failure event'() {
        def trace = container.traceFromRequest('/user_login_failure.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta."appsec.events.users.login.failure.usr.id" == 'Admin'
        assert span.meta."appsec.events.users.login.failure.usr.exists" == 'false'
        assert span.meta."appsec.events.users.login.failure.track" == 'true'
        assert span.meta."appsec.events.users.login.failure.email" == 'jean.example@example.com'
        assert span.meta."appsec.events.users.login.failure.session_id" == '987654321'
        assert span.meta."appsec.events.users.login.failure.role" == 'admin'
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

   @Test
   void 'test blocking'() {
       // Set ip which is blocked
       HttpRequest req = container.buildReq('/phpinfo.php')
               .header('X-Forwarded-For', '80.80.80.80').GET().build()
       def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
           assert re.statusCode() == 403
           assert re.body().contains('blocked')
       }

       Span span = trace.first()
       assert span.metrics."_dd.appsec.enabled" == 1.0d
       assert span.metrics."_dd.appsec.waf.duration" > 0.0d
       assert span.meta."_dd.appsec.event_rules.version" != ''
       assert span.meta."appsec.blocked" == "true"
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
      }

      Span span = trace.first()
      assert span.metrics."_dd.appsec.enabled" == 1.0d
      assert span.metrics."_dd.appsec.waf.duration" > 0.0d
      assert span.meta."_dd.appsec.event_rules.version" != ''
      assert span.meta."appsec.blocked" == "true"
    }

    @Test
    void 'module does not have STATIC_TLS flag'() {
        Container.ExecResult res = container.execInContainer(
                'bash', '-c',
                '''! { readelf -d "$(php -r 'echo ini_get("extension_dir");')"/ddappsec.so | grep STATIC_TLS; }''')
        if (res.exitCode != 0) {
            throw new AssertionError("Module has STATIC_TLS flag: $res.stdout")
        }
    }
}
