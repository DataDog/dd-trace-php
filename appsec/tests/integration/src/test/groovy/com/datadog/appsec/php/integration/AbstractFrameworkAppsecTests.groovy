package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.junit.jupiter.api.Assumptions.assumeTrue

/**
 * Common base for the AppSec suites that run against the shared framework
 * fixtures under {@code tests/Frameworks/}. It owns the endpoint-collection
 * tests (orders 1–3), which are identical for every framework; the
 * per-framework abstract classes add their own tests from order 4 onwards.
 *
 * Every fixture must expose {@code /} (200) and
 * {@code /outside_of_framework.php}, which prints
 * {@code are_endpoints_collected: <bool>}.
 *
 * Hooks follow the Groovy property convention ({@code is*} / {@code get*}) so
 * subclasses and test bodies can use property syntax.
 */
abstract class AbstractFrameworkAppsecTests {

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    /** Whether the framework integration emits endpoints telemetry. */
    boolean isEndpointsCollectionSupported() { true }

    /**
     * Whether the {@code are_endpoints_collected: true} check on a request
     * outside the framework is run after the endpoints have been sent.
     */
    boolean isEndpointsCollectedFlagChecked() { true }

    /**
     * When {@code true} (default), {@link #getExpectedEndpoints} may be a
     * strict subset of the endpoints emitted by the fixture (extras are
     * accepted). Set to {@code false} in subclasses that enumerate every
     * expected route to also assert the exact total count.
     */
    boolean isAllowUnenumeratedRoutes() { true }

    /**
     * Routes that must appear in the endpoints telemetry. Entries are
     * {@code [path, method, resourceName]} triples; operation name is always
     * {@code http.request}. Must be exhaustive when
     * {@link #isAllowUnenumeratedRoutes} is {@code false}.
     */
    List<List<String>> getExpectedEndpoints() { [] }

    /**
     * Expected {@code http.route} tag on the root span of {@code GET /}, or
     * {@code null} to skip the assertion.
     */
    String getExpectedRootHttpRoute() { null }

    @Test
    @Order(1)
    void 'Endpoints are not collected before the first request to framework'() {
        assumeTrue(endpointsCollectionSupported)
        assertEndpointsCollectedFlag(false)
    }

    @Test
    @Order(2)
    void 'Endpoints are sent'() {
        assumeTrue(endpointsCollectionSupported)
        Trace trace = container.traceFromRequest('/') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert trace.traceId != null
        if (expectedRootHttpRoute != null) {
            assert trace.first().meta.'http.route' == expectedRootHttpRoute
        }

        List<TelemetryHelpers.Endpoint> endpoints
        TelemetryHelpers.waitForAppEndpoints(container, 30, { List<TelemetryHelpers.Endpoint> messages ->
            endpoints = messages.collectMany { it.endpoints }
            endpoints.size() > 0
        })

        if (!allowUnenumeratedRoutes) {
            assert endpoints.size() == expectedEndpoints.size()
        }
        expectedEndpoints.each { List<String> entry ->
            String path = entry[0]
            String method = entry[1]
            String resource = entry[2]
            assert endpoints.find {
                it.path == path && it.method == method &&
                        it.operationName == 'http.request' && it.resourceName == resource
            } != null, "Missing endpoint ${method} ${path}"
        }
    }

    @Test
    @Order(3)
    void 'Endpoints are collected after the first request to framework'() {
        assumeTrue(endpointsCollectionSupported && endpointsCollectedFlagChecked)
        assertEndpointsCollectedFlag(true)
    }

    protected void assertEndpointsCollectedFlag(boolean expected) {
        HttpRequest req = container.buildReq('/outside_of_framework.php').GET().build()
        container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body().contains("are_endpoints_collected: ${expected}")
        }
    }
}
