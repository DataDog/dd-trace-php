package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.mock_stripe.MockStripeServer
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
class PaymentEventsTests {
    static boolean expectedVersion = phpVersionAtLeast('7.3') && !variant.contains('zts')

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    public static final MockStripeServer mockStripeServer = new MockStripeServer()

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'payment',
            ) {
                {
                    dependsOn mockStripeServer
                }

                @Override
                void configure() {
                    super.configure()
                    org.testcontainers.Testcontainers.exposeHostPorts(mockStripeServer.port)
                    withEnv('STRIPE_API_BASE', "http://host.testcontainers.internal:${mockStripeServer.port}")
                }
            }

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    static void assertPaymentCreationSpan(Trace trace) {
        Span span = trace.first()
        assert span.meta.'appsec.events.payments.integration' == 'stripe'
    }

    static void assertPaymentWebhookSpan(Trace trace, String eventType) {
        Span span = trace.first()
        assert span.meta.'appsec.events.payments.integration' == 'stripe'
    }

    @Test
    void 'test checkout session creation with payment mode'() {
        def trace = container.traceFromRequest("/payment.php?action=checkout_session") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
            assert body.contains('session_id')
        }
        assertPaymentCreationSpan(trace)

        Span span = trace.first()
        assert span.meta.'appsec.events.payments.creation.id' ==~ /^cs_test_\d+$/
        assert span.metrics.'appsec.events.payments.creation.amount_total' == 1000
        assert span.meta.'appsec.events.payments.creation.currency' == 'usd'
    }

    @Test
    void 'test checkout session with non-payment mode should be ignored'() {
        def trace = container.traceFromRequest("/payment.php?action=checkout_session_subscription") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
        }

        Span span = trace.first()
        assert !span.meta.containsKey('appsec.events.payments.integration') ||
               span.meta.'appsec.events.payments.integration' == null
    }

    @Test
    void 'test payment intent creation'() {
        def trace = container.traceFromRequest("/payment.php?action=payment_intent") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
            assert body.contains('payment_intent_id')
        }
        assertPaymentCreationSpan(trace)

        Span span = trace.first()
        assert span.meta.'appsec.events.payments.creation.id' ==~ /^pi_test_\d+$/
        assert span.metrics.'appsec.events.payments.creation.amount' == 2000
        assert span.meta.'appsec.events.payments.creation.currency' == 'usd'
    }

    @Test
    void 'test payment success webhook'() {
        def trace = container.traceFromRequest("/payment.php?action=webhook_success") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
            assert body.contains('payment_intent.succeeded')
        }
        assertPaymentWebhookSpan(trace, 'success')

        Span span = trace.first()
        assert span.meta.'appsec.events.payments.success.id' == 'pi_test_success_123'
        assert span.metrics.'appsec.events.payments.success.amount' == 2000
        assert span.meta.'appsec.events.payments.success.currency' == 'usd'
        assert span.meta.'appsec.events.payments.success.payment_method' == 'pm_test_123'
    }

    @Test
    void 'test payment failure webhook'() {
        def trace = container.traceFromRequest("/payment.php?action=webhook_failure") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
            assert body.contains('payment_intent.payment_failed')
        }
        assertPaymentWebhookSpan(trace, 'failure')

        Span span = trace.first()
        assert span.meta.'appsec.events.payments.failure.id' == 'pi_test_failure_456'
        assert span.metrics.'appsec.events.payments.failure.amount' == 1500
        assert span.meta.'appsec.events.payments.failure.currency' == 'eur'
        assert span.meta.'appsec.events.payments.failure.last_payment_error.code' == 'card_declined'
        assert span.meta.'appsec.events.payments.failure.last_payment_error.decline_code' == 'insufficient_funds'
    }

    @Test
    void 'test payment cancellation webhook'() {
        def trace = container.traceFromRequest("/payment.php?action=webhook_cancellation") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
            assert body.contains('payment_intent.canceled')
        }
        assertPaymentWebhookSpan(trace, 'cancellation')

        Span span = trace.first()
        assert span.meta.'appsec.events.payments.cancellation.id' == 'pi_test_cancel_789'
        assert span.metrics.'appsec.events.payments.cancellation.amount' == 3000
        assert span.meta.'appsec.events.payments.cancellation.currency' == 'gbp'
        assert span.meta.'appsec.events.payments.cancellation.cancellation_reason' == 'requested_by_customer'
    }

    @Test
    void 'test unsupported event type is ignored'() {
        def trace = container.traceFromRequest("/payment.php?action=webhook_unsupported") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
            assert body.contains('customer.created')
        }

        Span span = trace.first()
        assert !span.meta.containsKey('appsec.events.payments.integration') ||
               span.meta.'appsec.events.payments.integration' == null
    }

    @Test
    void 'test checkout session creation with direct method call'() {
        def trace = container.traceFromRequest("/payment.php?action=checkout_session_direct") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
            assert body.contains('session_id')
        }
        assertPaymentCreationSpan(trace)

        Span span = trace.first()
        assert span.meta.'appsec.events.payments.creation.id' ==~ /^cs_test_\d+$/
        assert span.metrics.'appsec.events.payments.creation.amount_total' == 1000
        assert span.meta.'appsec.events.payments.creation.currency' == 'usd'
        assert span.meta.'appsec.events.payments.creation.client_reference_id' == 'test_ref_direct_456'
    }

    @Test
    void 'test payment intent creation with direct method call'() {
        def trace = container.traceFromRequest("/payment.php?action=payment_intent_direct") { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def body = new String(resp.body().readAllBytes())
            assert body.contains('success')
            assert body.contains('payment_intent_id')
        }
        assertPaymentCreationSpan(trace)

        Span span = trace.first()
        assert span.meta.'appsec.events.payments.creation.id' ==~ /^pi_test_\d+$/
        assert span.metrics.'appsec.events.payments.creation.amount' == 3500
        assert span.meta.'appsec.events.payments.creation.currency' == 'eur'
    }

    @Test
    void 'Root has no Payment tags'() {
        def trace = container.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        Span span = trace.first()
        assert !span.meta.containsKey('appsec.events.payments.integration')
        assert !span.meta.containsKey('appsec.events.payments.creation.id')
    }
}
