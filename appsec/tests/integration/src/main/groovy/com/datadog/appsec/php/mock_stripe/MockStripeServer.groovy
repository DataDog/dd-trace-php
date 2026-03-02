package com.datadog.appsec.php.mock_stripe

import groovy.transform.CompileStatic
import groovy.util.logging.Slf4j
import io.javalin.Javalin
import io.javalin.http.Context
import groovy.json.JsonOutput
import org.testcontainers.lifecycle.Startable

@Slf4j
@CompileStatic
class MockStripeServer implements Startable {
    Javalin httpServer

    @Override
    void start() {
        this.httpServer = Javalin.create(config -> {
            config.showJavalinBanner = false
        })

        httpServer.post("/v1/checkout/sessions") { ctx ->
            handleCheckoutSessionCreate(ctx)
        }

        httpServer.post("/v1/payment_intents") { ctx ->
            handlePaymentIntentCreate(ctx)
        }

        httpServer.error(404, ctx -> {
            log.info("Unmatched Stripe mock request: ${ctx.method()} ${ctx.path()}")
            ctx.status(404).json(['error': 'Not Found'])
        })

        httpServer.error(405, ctx -> {
            ctx.status(405).json(['error': 'Method Not Allowed'])
        })

        httpServer.start(0)
    }

    int getPort() {
        this.httpServer.port()
    }

    @Override
    void stop() {
        if (httpServer != null) {
            this.httpServer.stop()
            this.httpServer = null
        }
    }

    private void handleCheckoutSessionCreate(Context ctx) {
        def body = ctx.body()
        def mode = extractParam(body, "mode")

        def response = [
            id: "cs_test_${System.currentTimeMillis()}",
            object: "checkout.session",
            mode: mode ?: "payment",
            amount_total: 1000,
            currency: "usd",
            client_reference_id: extractParam(body, "client_reference_id") ?: "test_ref",
            livemode: false,
            payment_status: "unpaid",
            status: "open",
            discounts: [
                [
                    coupon: "SUMMER20",
                    promotion_code: "promo_123"
                ]
            ],
            total_details: [
                amount_discount: 200,
                amount_shipping: 500,
                amount_tax: 0
            ]
        ]

        ctx.status(200)
        ctx.contentType("application/json")
        ctx.result(JsonOutput.toJson(response))
    }

    private void handlePaymentIntentCreate(Context ctx) {
        def body = ctx.body()

        def response = [
            id: "pi_test_${System.currentTimeMillis()}",
            object: "payment_intent",
            amount: extractParam(body, "amount") ? Integer.parseInt(extractParam(body, "amount")) : 2000,
            currency: extractParam(body, "currency") ?: "usd",
            livemode: false,
            payment_method: "pm_test_123",
            status: "requires_payment_method"
        ]

        ctx.status(200)
        ctx.contentType("application/json")
        ctx.result(JsonOutput.toJson(response))
    }

    private String extractParam(String body, String param) {
        // Parse form-encoded body (Stripe SDK sends form data)
        def params = [:]
        body?.split('&')?.each { pair ->
            def kv = pair.split('=', 2)
            if (kv.length == 2) {
                params[URLDecoder.decode(kv[0], 'UTF-8')] = URLDecoder.decode(kv[1], 'UTF-8')
            }
        }
        return params[param]
    }

    static Map<String, Object> createWebhookSuccessEvent() {
        return [
            id: "evt_test_success_${System.currentTimeMillis()}",
            object: "event",
            type: "payment_intent.succeeded",
            data: [
                object: [
                    id: "pi_test_success_123",
                    object: "payment_intent",
                    amount: 2000,
                    currency: "usd",
                    livemode: false,
                    payment_method: "pm_test_123",
                    status: "succeeded"
                ]
            ]
        ]
    }

    static Map<String, Object> createWebhookFailureEvent() {
        return [
            id: "evt_test_failure_${System.currentTimeMillis()}",
            object: "event",
            type: "payment_intent.payment_failed",
            data: [
                object: [
                    id: "pi_test_failure_456",
                    object: "payment_intent",
                    amount: 1500,
                    currency: "eur",
                    livemode: false,
                    last_payment_error: [
                        code: "card_declined",
                        decline_code: "insufficient_funds",
                        payment_method: [
                            id: "pm_test_456",
                            type: "card"
                        ]
                    ],
                    status: "requires_payment_method"
                ]
            ]
        ]
    }

    static Map<String, Object> createWebhookCancellationEvent() {
        return [
            id: "evt_test_cancel_${System.currentTimeMillis()}",
            object: "event",
            type: "payment_intent.canceled",
            data: [
                object: [
                    id: "pi_test_cancel_789",
                    object: "payment_intent",
                    amount: 3000,
                    currency: "gbp",
                    livemode: false,
                    cancellation_reason: "requested_by_customer",
                    status: "canceled"
                ]
            ]
        ]
    }

    static Map<String, Object> createWebhookUnsupportedEvent() {
        return [
            id: "evt_test_unsupported_${System.currentTimeMillis()}",
            object: "event",
            type: "customer.created",
            data: [
                object: [
                    id: "cus_test_123",
                    object: "customer",
                    email: "test@example.com"
                ]
            ]
        ]
    }
}
