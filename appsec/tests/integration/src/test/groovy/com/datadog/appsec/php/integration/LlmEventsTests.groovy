package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.mock_openai.MockOpenAIServer
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.io.InputStream

import static org.testcontainers.containers.Container.ExecResult
import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static com.datadog.appsec.php.integration.TestParams.phpVersionAtLeast
import com.datadog.appsec.php.TelemetryHelpers
import static java.net.http.HttpResponse.BodyHandlers.ofString

@Testcontainers
@EnabledIf('isExpectedVersion')
class LlmEventsTests {
    static final String MODEL = 'gpt-4.1'
    static boolean expectedVersion = phpVersionAtLeast('8.2') && !variant.contains('zts')

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    public static final MockOpenAIServer mockOpenAIServer = new MockOpenAIServer()

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'llm',
            ) {
                {
                    dependsOn mockOpenAIServer
                }

                @Override
                void configure() {
                    super.configure()
                    org.testcontainers.Testcontainers.exposeHostPorts(mockOpenAIServer.port)
                    withEnv('OPENAI_BASE_URL', "http://host.testcontainers.internal:${mockOpenAIServer.port}/v1")
                }
            }    

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    /** Common assertions for LLM endpoint spans. */
    static void assertLlmSpan(Trace trace, String model) {
        Span span = trace.first()
        assert span.meta.'appsec.events.llm.call.provider' == 'openai'
        assert span.meta.'appsec.events.llm.call.model' == model
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    void 'OpenAI latest responses create'() {
        def trace = container.traceFromRequest("/llm.php?model=${MODEL}&operation=openai-latest-responses.create") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assertLlmSpan(trace, MODEL)
    }

    @Test
    void 'OpenAI latest chat completions create'() {
        def trace = container.traceFromRequest("/llm.php?model=${MODEL}&operation=openai-latest-chat.completions.create") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assertLlmSpan(trace, MODEL)
    }

    @Test
    void 'OpenAI latest completions create'() {
        def trace = container.traceFromRequest("/llm.php?model=${MODEL}&operation=openai-latest-completions.create") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assertLlmSpan(trace, MODEL)
    }

    @Test
    void 'Root has no LLM tags'() {
        def trace = container.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        Span span = trace.first()
        assert !span.meta.containsKey('appsec.events.llm.call.provider')
        assert !span.meta.containsKey('appsec.events.llm.call.model')
    }
}
