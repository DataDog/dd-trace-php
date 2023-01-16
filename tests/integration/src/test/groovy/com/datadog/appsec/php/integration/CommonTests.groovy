package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import org.junit.jupiter.api.Test
import org.testcontainers.containers.Container

import static com.datadog.appsec.php.test.JsonMatcher.matchesJson
import static org.hamcrest.MatcherAssert.assertThat
import static org.testcontainers.containers.Container.ExecResult

trait CommonTests {

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    @Test
    void 'user tracking'() {
        def trace = container.traceFromRequest('/user_id.php') { HttpURLConnection conn ->
            assert conn.responseCode == 200
        }

        assert trace.meta."usr.id" == '123456789'
        assert trace.meta."usr.name" == 'Jean Example'
        assert trace.meta."usr.email" == 'jean.example@example.com'
        assert trace.meta."usr.session_id" == '987654321'
        assert trace.meta."usr.role" == 'admin'
        assert trace.meta."usr.scope" == 'read:message, write:files'
    }

    @Test
    void 'user login success event'() {
        def trace = container.traceFromRequest('/user_login_success.php') { HttpURLConnection conn ->
            assert conn.responseCode == 200
        }

        assert trace.metrics._sampling_priority_v1 == 2.0d
        assert trace.meta."usr.id" == 'Admin'
        assert trace.meta."appsec.events.users.login.success.track" == 'true'
        assert trace.meta."appsec.events.users.login.success.email" == 'jean.example@example.com'
        assert trace.meta."appsec.events.users.login.success.session_id" == '987654321'
        assert trace.meta."appsec.events.users.login.success.role" == 'admin'
    }

    @Test
    void 'user login failure event'() {
        def trace = container.traceFromRequest('/user_login_failure.php') { HttpURLConnection conn ->
            assert conn.responseCode == 200
        }

        assert trace.metrics._sampling_priority_v1 == 2.0d
        assert trace.meta."appsec.events.users.login.failure.usr.id" == 'Admin'
        assert trace.meta."appsec.events.users.login.failure.usr.exists" == 'false'
        assert trace.meta."appsec.events.users.login.failure.track" == 'true'
        assert trace.meta."appsec.events.users.login.failure.email" == 'jean.example@example.com'
        assert trace.meta."appsec.events.users.login.failure.session_id" == '987654321'
        assert trace.meta."appsec.events.users.login.failure.role" == 'admin'
    }


    @Test
    void 'custom event'() {
        def trace = container.traceFromRequest('/custom_event.php') { HttpURLConnection conn ->
            assert conn.responseCode == 200
        }

        assert trace.metrics._sampling_priority_v1 == 2.0d
        assert trace.meta."appsec.events.custom_event.track" == 'true'
        assert trace.meta."appsec.events.custom_event.metadata0" == 'value0'
        assert trace.meta."appsec.events.custom_event.metadata1" == 'value1'
        assert trace.meta."appsec.events.custom_event.metadata2" == 'value2'
    }

    @Test
    void 'sanity check against non PHP endpoint'() {
        def conn = container.createRequest('/')
        conn.inputStream.withCloseable {
            assert conn.responseCode == 200
        }
    }

    @Test
    void 'trace without attack'() {
        def trace = container.traceFromRequest('/phpinfo.php') { HttpURLConnection conn ->
            assert conn.responseCode == 200
            def content = conn.inputStream.text
            assert content.contains('module_ddtrace')
            assert content.contains('module_ddappsec')
        }

        assert trace.metrics."_dd.appsec.enabled" == 1.0d
        assert trace.metrics."_dd.appsec.waf.duration" > 0.0d
        assert trace.meta."_dd.appsec.event_rules.version" != ''
    }


    @Test
    void 'trace with an attack'() {
        def trace = container.traceFromRequest('/hello.php') { HttpURLConnection conn ->
            conn.setRequestProperty('User-Agent', 'Arachni/v1')
            conn.inputStream.text == 'Hello world!'
        }

        assert trace.metrics._sampling_priority_v1 == 2.0d

        assert trace.metrics."_dd.appsec.waf.duration" > 0.0d
        assert trace.meta."_dd.runtime_family" == 'php'
        assert trace.meta."http.useragent" == 'Arachni/v1'
        def appsecJson = trace.meta."_dd.appsec.json"
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
    void 'module does not have STATIC_TLS flag'() {
        Container.ExecResult res = container.execInContainer(
                'bash', '-c',
                '''! { readelf -d "$(php -r 'echo ini_get("extension_dir");')"/ddappsec.so | grep STATIC_TLS; }''')
        if (res.exitCode != 0) {
            throw new AssertionError("Module has STATIC_TLS flag: $res.stdout")
        }
    }
}
