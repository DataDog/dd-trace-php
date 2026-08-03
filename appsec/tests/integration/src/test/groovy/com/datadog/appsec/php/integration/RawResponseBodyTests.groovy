package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.model.Span
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static com.datadog.appsec.php.test.JsonMatcher.matchesJson
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.hamcrest.MatcherAssert.assertThat

@Testcontainers
@Slf4j
@EnabledIf('isEnabled')
class RawResponseBodyTests {
    static boolean enabled = !variant.contains('zts') && phpVersion == '8.3'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'nginx-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            ).tap {
                withEnv('DD_APPSEC_RAW_RESPONSE_BODY_ENABLED', '1')
            }

    static boolean isEnabled() { enabled }

    @Test
    void 'WAF matches against raw JSON response body'() {
        def trace = CONTAINER.traceFromRequest('/raw_response_body.php') { HttpResponse<String> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()

        def appsecJson = span.meta.'_dd.appsec.json'
        assert appsecJson != null, 'Expected WAF to fire on server.response.body.raw'

        def expJson = '''{
           "triggers" : [
              {
                 "rule" : {
                    "id" : "poison-in-raw-response-body",
                    "name" : "poison-in-raw-response-body",
                    "tags" : {
                       "category" : "attack_attempt",
                       "type" : "security_scanner"
                    }
                 },
                 "rule_matches" : [
                    {
                       "operator" : "match_regex",
                       "operator_value" : "(?i)raw-body-poison",
                       "parameters" : [
                          {
                             "address" : "server.response.body.raw",
                             "highlight" : [
                                "raw-body-poison"
                             ],
                             "key_path" : [],
                             "value" : "{\\"marker\\": \\"raw-body-poison\\"}"
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
