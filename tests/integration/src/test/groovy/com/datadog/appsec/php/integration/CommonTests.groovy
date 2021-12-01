package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import org.junit.jupiter.api.Test
import org.testcontainers.containers.Container

import static com.datadog.appsec.php.test.JsonMatcher.matchesJson
import static org.hamcrest.MatcherAssert.assertThat

trait CommonTests {

    AppSecContainer getContainer() {
        getClass().CONTAINER
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
    }


    @Test
    void 'trace with an attack'() {
        def trace = container.traceFromRequest('/hello.php') { HttpURLConnection conn ->
            conn.setRequestProperty('User-Agent', 'Arachni/v1')
            conn.inputStream.text == 'Hello world!'
        }

        assert trace.metrics._sampling_priority_v1 == 2.0d

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
