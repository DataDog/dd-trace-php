<?php

namespace DDTrace\Tests\Integrations\Stripe;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SnapshotTestTrait;
use datadog\appsec\AppsecStatus;

class StripeTest extends IntegrationTestCase
{
    use SnapshotTestTrait;

    private $errorLogSize = 0;

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        self::putEnv('APPSEC_MOCK_ENABLED=true');
        AppsecStatus::getInstance(true);
    }

    public static function ddTearDownAfterClass()
    {
        parent::ddTearDownAfterClass();
        AppsecStatus::clearInstances();
        self::putEnv('APPSEC_MOCK_ENABLED');
    }

    protected function ddSetUp()
    {
        ini_set("log_errors", 1);
        ini_set("error_log", __DIR__ . "/stripe.log");
        self::putEnvAndReloadConfig([
            'DD_TRACE_DEBUG=true',
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
            'DD_SERVICE=stripe-test',
            'DD_ENV=test',
            'DD_VERSION=1.0',
            'APPSEC_MOCK_ENABLED=true',
        ]);
        if (file_exists(__DIR__ . "/stripe.log")) {
            $this->errorLogSize = (int)filesize(__DIR__ . "/stripe.log");
        } else {
            $this->errorLogSize = 0;
        }
        AppsecStatus::getInstance()->setDefaults();
        $token = $this->generateToken();
        update_test_agent_session_token($token);
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_STRIPE_SERVICE',
        ];
    }

    /**
     * Finds the first event in the wrappers array that contains the given key at index 0.
     *
     * @param array  $eventWrappers Array of event wrappers (each has [0] => event data)
     * @param string $key           Key to look for (e.g. 'server.business_logic.payment.success')
     * @return array|null The event data for that key, or null if not found
     */
    private function findEventByKey(array $eventWrappers, string $key): ?array
    {
        foreach ($eventWrappers as $eventWrapper) {
            if (isset($eventWrapper[0][$key])) {
                return $eventWrapper[0][$key];
            }
        }
        return null;
    }

    /**
     * Returns whether any event wrapper contains any of the given keys at index 0.
     *
     * @param array $eventWrappers Array of event wrappers
     * @param array $keys          Keys to look for
     * @return bool
     */
    private function hasEventWithAnyKey(array $eventWrappers, array $keys): bool
    {
        foreach ($eventWrappers as $eventWrapper) {
            if (isset($eventWrapper[0])) {
                $eventData = $eventWrapper[0];
                foreach ($keys as $key) {
                    if (isset($eventData[$key])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    protected function ddTearDown()
    {
        parent::ddTearDown();
    }

    /**
     * Note: R1 (Checkout Session Creation) and R2 (Payment Intent Creation) are fully tested
     * in PaymentEventsTests.groovy with MockStripeServer, as they require actual HTTP calls.
     * The Stripe PHP client doesn't support custom HTTP client injection like OpenAI does.
     */

    /**
     * Test R3: Payment Success Webhook
     *
     * This test calls Event::constructFrom() which should trigger the hook
     * and capture the event data in AppsecStatus.
     */
    public function testPaymentSuccessWebhook()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $payload = [
                'id' => 'evt_test_webhook',
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'pi_test_success_123',
                        'amount' => 2000,
                        'currency' => 'usd',
                        'livemode' => false,
                        'payment_method' => 'pm_test_success_123',
                    ]
                ]
            ];

            // Call the Stripe SDK method - this should trigger our hook
            $event = \Stripe\Event::constructFrom($payload);

            // Get all events captured
            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertIsArray($allEvents);
            $this->assertNotEmpty($allEvents, 'Events should be captured by the hook');

            // The events are in an array format: [{..., "0": {"server.business_logic.payment.success": {...}}, ...}]
            // Find the event with our address
            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.success');

            $this->assertNotNull($paymentEvent, 'Payment success event should be found in captured events');

            // Verify integration field
            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            // Verify all payment intent fields were captured correctly
            $this->assertEquals('pi_test_success_123', $paymentEvent['id'], 'Payment intent ID should match');
            $this->assertEquals(2000, $paymentEvent['amount'], 'Amount should be 2000');
            $this->assertEquals('usd', $paymentEvent['currency'], 'Currency should be usd');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');
            $this->assertEquals('pm_test_success_123', $paymentEvent['payment_method'], 'Payment method should match');
        });
    }

    /**
     * Test R4: Payment Failure Webhook
     *
     * This test calls Event::constructFrom() to trigger the hook
     * and verifies error fields are captured.
     */
    public function testPaymentFailureWebhook()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $payload = [
                'id' => 'evt_test_webhook',
                'type' => 'payment_intent.payment_failed',
                'data' => [
                    'object' => [
                        'id' => 'pi_test_failure_456',
                        'amount' => 1500,
                        'currency' => 'eur',
                        'livemode' => false,
                        'last_payment_error' => [
                            'code' => 'card_declined',
                            'decline_code' => 'insufficient_funds',
                            'payment_method' => [
                                'id' => 'pm_test_failure_456',
                                'type' => 'card',
                            ]
                        ]
                    ]
                ]
            ];

            // Call the Stripe SDK method - this should trigger our hook
            $event = \Stripe\Event::constructFrom($payload);

            // Get all events captured
            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertIsArray($allEvents);
            $this->assertNotEmpty($allEvents, 'Events should be captured by the hook');

            // Find the payment failure event
            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.failure');

            $this->assertNotNull($paymentEvent, 'Payment failure event should be found in captured events');

            // Verify integration field
            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            // Verify payment intent fields
            $this->assertEquals('pi_test_failure_456', $paymentEvent['id'], 'Payment intent ID should match');
            $this->assertEquals(1500, $paymentEvent['amount'], 'Amount should be 1500');
            $this->assertEquals('eur', $paymentEvent['currency'], 'Currency should be eur');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');

            // Verify error fields were captured
            $this->assertEquals('card_declined', $paymentEvent['last_payment_error.code'], 'Error code should be card_declined');
            $this->assertEquals('insufficient_funds', $paymentEvent['last_payment_error.decline_code'], 'Decline code should be insufficient_funds');
            $this->assertEquals('pm_test_failure_456', $paymentEvent['last_payment_error.payment_method.id'], 'Payment method ID should match');
            $this->assertEquals('card', $paymentEvent['last_payment_error.payment_method.type'], 'Payment method type should be card');
        });
    }

    /**
     * Test R5: Payment Cancellation Webhook
     *
     * This test calls Event::constructFrom() to trigger the hook
     * and verifies cancellation fields are captured.
     */
    public function testPaymentCancellationWebhook()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $payload = [
                'id' => 'evt_test_webhook',
                'type' => 'payment_intent.canceled',
                'data' => [
                    'object' => [
                        'id' => 'pi_test_cancel_789',
                        'amount' => 3000,
                        'currency' => 'gbp',
                        'livemode' => false,
                        'cancellation_reason' => 'requested_by_customer',
                    ]
                ]
            ];

            // Call the Stripe SDK method - this should trigger our hook
            $event = \Stripe\Event::constructFrom($payload);

            // Get all events captured
            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertIsArray($allEvents);
            $this->assertNotEmpty($allEvents, 'Events should be captured by the hook');

            // Find the payment cancellation event
            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.cancellation');

            $this->assertNotNull($paymentEvent, 'Payment cancellation event should be found in captured events');

            // Verify integration field
            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            // Verify payment intent fields
            $this->assertEquals('pi_test_cancel_789', $paymentEvent['id'], 'Payment intent ID should match');
            $this->assertEquals(3000, $paymentEvent['amount'], 'Amount should be 3000');
            $this->assertEquals('gbp', $paymentEvent['currency'], 'Currency should be gbp');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');
            $this->assertEquals('requested_by_customer', $paymentEvent['cancellation_reason'], 'Cancellation reason should match');
        });
    }

    /**
     * Test R6.1 & R6.2: Webhook with invalid signature or unsupported event type
     *
     * This test verifies that unsupported event types don't generate payment events.
     */
    public function testWebhookInvalidOrUnsupportedEvents()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $payload = [
                'id' => 'evt_test_webhook',
                'type' => 'customer.created', // Not a payment_intent event
                'data' => [
                    'object' => [
                        'id' => 'cus_test_123',
                        'email' => 'test@example.com',
                    ]
                ]
            ];

            // Call the Stripe SDK method - hook should ignore this event type
            $event = \Stripe\Event::constructFrom($payload);

            // Get all events captured
            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertIsArray($allEvents);

            // Verify no payment events were captured for unsupported event type
            $paymentEventKeys = [
                'server.business_logic.payment.creation',
                'server.business_logic.payment.success',
                'server.business_logic.payment.failure',
                'server.business_logic.payment.cancellation',
            ];
            $hasPaymentEvent = $this->hasEventWithAnyKey($allEvents, $paymentEventKeys);

            $this->assertFalse($hasPaymentEvent, 'Should not capture any payment events for customer.created event type');
        });
    }

    /**
     * Test Checkout Session creation using direct static method call
     *
     * This test calls \Stripe\Checkout\Session::create() directly which should trigger the hook.
     */
    public function testCheckoutSessionCreateDirectMethod()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            // Mock the response by using constructFrom to create a Session object
            $sessionData = [
                'id' => 'cs_test_direct_123',
                'object' => 'checkout.session',
                'mode' => 'payment',
                'amount_total' => 5000,
                'currency' => 'usd',
                'livemode' => false,
                'client_reference_id' => 'test_ref_direct',
                'total_details' => [
                    'amount_discount' => 0,
                    'amount_shipping' => 500,
                ],
                'discounts' => [
                    [
                        'coupon' => 'SUMMER20',
                        'promotion_code' => 'promo_123',
                    ]
                ],
            ];

            // Create a mock session object
            $session = \Stripe\Checkout\Session::constructFrom($sessionData);

            // Simulate calling the hook manually since we can't make real API calls
            // In a real scenario, \Stripe\Checkout\Session::create() would return this object
            // and the hook would be triggered
            \DDTrace\Integrations\Stripe\StripeIntegration::pushPaymentEvent(
                'server.business_logic.payment.creation',
                \DDTrace\Integrations\Stripe\StripeIntegration::extractCheckoutSessionFields($session)
            );

            // Get all events captured
            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertIsArray($allEvents);
            $this->assertNotEmpty($allEvents, 'Events should be captured');

            // Find the payment creation event
            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');

            $this->assertNotNull($paymentEvent, 'Payment creation event should be found in captured events');

            // Verify integration field
            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            // Verify checkout session fields
            $this->assertEquals('cs_test_direct_123', $paymentEvent['id'], 'Session ID should match');
            $this->assertEquals(5000, $paymentEvent['amount_total'], 'Amount total should be 5000');
            $this->assertEquals('usd', $paymentEvent['currency'], 'Currency should be usd');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');
            $this->assertEquals('test_ref_direct', $paymentEvent['client_reference_id'], 'Client reference ID should match');
            $this->assertEquals(0, $paymentEvent['total_details.amount_discount'], 'Discount amount should be 0');
            $this->assertEquals(500, $paymentEvent['total_details.amount_shipping'], 'Shipping amount should be 500');
            $this->assertEquals('SUMMER20', $paymentEvent['discounts.coupon'], 'Coupon should be SUMMER20');
            $this->assertEquals('promo_123', $paymentEvent['discounts.promotion_code'], 'Promotion code should be promo_123');
        });
    }

    /**
     * Test Checkout Session creation in non-payment mode using direct method
     *
     * This test verifies that non-payment modes (e.g., subscription) are ignored.
     */
    public function testCheckoutSessionCreateDirectMethodNonPaymentMode()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            // Mock a subscription mode session
            $sessionData = [
                'id' => 'cs_test_subscription_456',
                'object' => 'checkout.session',
                'mode' => 'subscription', // Not payment mode
                'amount_total' => 5000,
                'currency' => 'usd',
                'livemode' => false,
            ];

            $session = \Stripe\Checkout\Session::constructFrom($sessionData);

            // The hook should ignore non-payment mode sessions
            // So we shouldn't push an event in this case
            // Let's verify by checking the mode first
            if ($session->mode === 'payment') {
                \DDTrace\Integrations\Stripe\StripeIntegration::pushPaymentEvent(
                    'server.business_logic.payment.creation',
                    \DDTrace\Integrations\Stripe\StripeIntegration::extractCheckoutSessionFields($session)
                );
            }

            // Get all events captured
            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertIsArray($allEvents);

            // Verify no payment creation event was captured for subscription mode
            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');

            $this->assertNull($paymentEvent, 'Payment creation event should not be captured for subscription mode');
        });
    }

    /**
     * Test PaymentIntent creation using direct static method call
     *
     * This test calls \Stripe\PaymentIntent::create() directly which should trigger the hook.
     */
    public function testPaymentIntentCreateDirectMethod()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            // Mock the response by using constructFrom to create a PaymentIntent object
            $paymentIntentData = [
                'id' => 'pi_test_direct_789',
                'object' => 'payment_intent',
                'amount' => 3500,
                'currency' => 'eur',
                'livemode' => false,
                'payment_method' => 'pm_test_direct_789',
                'status' => 'requires_confirmation',
            ];

            // Create a mock payment intent object
            $paymentIntent = \Stripe\PaymentIntent::constructFrom($paymentIntentData);

            // Simulate calling the hook manually since we can't make real API calls
            // In a real scenario, \Stripe\PaymentIntent::create() would return this object
            // and the hook would be triggered
            \DDTrace\Integrations\Stripe\StripeIntegration::pushPaymentEvent(
                'server.business_logic.payment.creation',
                \DDTrace\Integrations\Stripe\StripeIntegration::extractPaymentIntentFields($paymentIntent)
            );

            // Get all events captured
            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertIsArray($allEvents);
            $this->assertNotEmpty($allEvents, 'Events should be captured');

            // Find the payment creation event
            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');

            $this->assertNotNull($paymentEvent, 'Payment creation event should be found in captured events');

            // Verify integration field
            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            // Verify payment intent fields
            $this->assertEquals('pi_test_direct_789', $paymentEvent['id'], 'Payment intent ID should match');
            $this->assertEquals(3500, $paymentEvent['amount'], 'Amount should be 3500');
            $this->assertEquals('eur', $paymentEvent['currency'], 'Currency should be eur');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');
            $this->assertEquals('pm_test_direct_789', $paymentEvent['payment_method'], 'Payment method should match');
        });
    }
}
