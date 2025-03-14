package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import org.junit.jupiter.api.Test

import java.net.http.HttpResponse

trait SamplingTestsInFpm {

    @Test
    void 'default sampling behavior of extract-schema'() {
        def trace = container.traceFromRequest('/api_security.php') {
            HttpResponse<InputStream> resp ->
                assert resp.headers().firstValue('content-type').get() == 'application/json'
                assert resp.statusCode() == 200
        }
        assert trace != null
        assert trace.first().meta."_dd.appsec.s.res.body" == '[{"messages":[[[8]],{"len":2}],"status":[8]}]'

        // the second time we should not see it
        trace = container.traceFromRequest('/api_security.php') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }
        assert trace != null
        assert trace.first().meta."_dd.appsec.s.res.body" == null

        // however, if we change the endpoint, we should again see something
        trace = container.traceFromRequest('/api_security.php?route=/another/route') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }
        assert trace != null
        assert trace.first().meta."_dd.appsec.s.res.body" == '[{"messages":[[[8]],{"len":2}],"status":[8]}]'
    }

    @Test
    void 'sampling of extract-schema is disabled'() {
        samplingPeriod = '0.0'

        try {
            2.times {
                def trace = container.traceFromRequest('/api_security.php') {
                    HttpResponse<InputStream> resp ->
                        assert resp.headers().firstValue('content-type').get() == 'application/json'
                        assert resp.statusCode() == 200
                }
                assert trace != null
                assert trace.first().meta."_dd.appsec.s.res.body" != null
            }
        } finally {
            resetFpm()
        }
    }

    @Test
    void 'sampling period of extract-schema is set to 2'() {
        samplingPeriod = '2.0'

        try {
            // do not wait for trace in order not to introduce delay
            def req = container.buildReq('/api_security.php').build()
            def response = container.httpClient.send(req, HttpResponse.BodyHandlers.discarding())
            assert response.statusCode() == 200



            // the second time we should not see it
            def trace = container.traceFromRequest('/api_security.php',  {
                HttpResponse<InputStream> resp ->
                    assert resp.headers().firstValue('content-type').get() == 'application/json'
                    assert resp.statusCode() == 200
            }, true /* ignore other traces */)
            assert trace != null
            assert trace.first().meta."_dd.appsec.s.res.body" == null

            // now wait a bit and check that we see the trace again
            Thread.sleep(3500)
            trace = container.traceFromRequest('/api_security.php') {
                HttpResponse<InputStream> resp ->
                    assert resp.headers().firstValue('content-type').get() == 'application/json'
                    assert resp.statusCode() == 200
            }
            assert trace != null
            assert trace.first().meta."_dd.appsec.s.res.body" != null
        } finally {
            resetFpm()
        }
    }

    private AppSecContainer getContainer() {
        getClass().container
    }

    void setSamplingPeriod(String period) {
        def res = container.execInContainer(
                'bash', '-c',
                """kill -9 `pgrep php-fpm`;
               export DD_API_SECURITY_SAMPLE_DELAY=$period;
               php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini""")
        assert res.exitCode == 0
    }

    private void resetFpm() {
        container.execInContainer(
                'bash', '-c',
                '''kill -9 `pgrep php-fpm`;
               php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini''')
    }
}