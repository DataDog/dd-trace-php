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
        self::putEnvAndReloadConfig([
            'DD_TRACE_DEBUG=true',
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
            'DD_SERVICE=stripe-test',
            'DD_ENV=test',
            'DD_VERSION=1.0',
            'APPSEC_MOCK_ENABLED=true',
        ]);
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

    private function findEventByKey(array $eventWrappers, string $key)
    {
        foreach ($eventWrappers as $eventWrapper) {
            if (isset($eventWrapper[0][$key])) {
                return $eventWrapper[0][$key];
            }
        }
        return null;
    }

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

            \Stripe\Event::constructFrom($payload);

            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertNotEmpty($allEvents, 'Events should be captured by the hook');

            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.success');

            $this->assertNotNull($paymentEvent, 'Payment success event should be found in captured events');

            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            $this->assertEquals('pi_test_success_123', $paymentEvent['id'], 'Payment intent ID should match');
            $this->assertEquals(2000, $paymentEvent['amount'], 'Amount should be 2000');
            $this->assertEquals('usd', $paymentEvent['currency'], 'Currency should be usd');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');
            $this->assertEquals('pm_test_success_123', $paymentEvent['payment_method'], 'Payment method should match');
        });
    }

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

            \Stripe\Event::constructFrom($payload);

            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertNotEmpty($allEvents, 'Events should be captured by the hook');

            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.failure');

            $this->assertNotNull($paymentEvent, 'Payment failure event should be found in captured events');

            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            $this->assertEquals('pi_test_failure_456', $paymentEvent['id'], 'Payment intent ID should match');
            $this->assertEquals(1500, $paymentEvent['amount'], 'Amount should be 1500');
            $this->assertEquals('eur', $paymentEvent['currency'], 'Currency should be eur');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');

            $this->assertEquals('card_declined', $paymentEvent['last_payment_error.code'], 'Error code should be card_declined');
            $this->assertEquals('insufficient_funds', $paymentEvent['last_payment_error.decline_code'], 'Decline code should be insufficient_funds');
            $this->assertEquals('pm_test_failure_456', $paymentEvent['last_payment_error.payment_method.id'], 'Payment method ID should match');
            $this->assertEquals('card', $paymentEvent['last_payment_error.payment_method.type'], 'Payment method type should be card');
        });
    }

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

            \Stripe\Event::constructFrom($payload);

            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertNotEmpty($allEvents, 'Events should be captured by the hook');

            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.cancellation');

            $this->assertNotNull($paymentEvent, 'Payment cancellation event should be found in captured events');

            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            $this->assertEquals('pi_test_cancel_789', $paymentEvent['id'], 'Payment intent ID should match');
            $this->assertEquals(3000, $paymentEvent['amount'], 'Amount should be 3000');
            $this->assertEquals('gbp', $paymentEvent['currency'], 'Currency should be gbp');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');
            $this->assertEquals('requested_by_customer', $paymentEvent['cancellation_reason'], 'Cancellation reason should match');
        });
    }

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

            \Stripe\Event::constructFrom($payload);

            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);


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

    public function testPaymentSuccessWebhookViaConstructEvent()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $payload = [
                'id' => 'evt_webhook_construct_event',
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'pi_webhook_123',
                        'amount' => 1999,
                        'currency' => 'eur',
                        'livemode' => false,
                        'payment_method' => 'pm_webhook_123',
                    ]
                ]
            ];
            $payloadJson = json_encode($payload);
            $secret = 'whsec_test_secret';
            $timestamp = time();
            $signedPayload = $timestamp . '.' . $payloadJson;
            $signature = hash_hmac('sha256', $signedPayload, $secret);
            $sigHeader = "t={$timestamp},v1={$signature}";

            $event = \Stripe\Webhook::constructEvent($payloadJson, $sigHeader, $secret);

            $this->assertSame('payment_intent.succeeded', $event->type);

            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);
            $this->assertNotEmpty($allEvents);

            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.success');
            $this->assertNotNull($paymentEvent, 'Payment success event should be found when using Webhook::constructEvent');
            $this->assertEquals('stripe', $paymentEvent['integration']);
            $this->assertEquals('pi_webhook_123', $paymentEvent['id']);
            $this->assertEquals(1999, $paymentEvent['amount']);
            $this->assertEquals('eur', $paymentEvent['currency']);
            $this->assertEquals('pm_webhook_123', $paymentEvent['payment_method']);
        });
    }

    public function testCheckoutSessionCreateDirectMethod()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

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

            $session = \Stripe\Checkout\Session::constructFrom($sessionData);

            \DDTrace\Integrations\Stripe\StripeIntegration::pushPaymentEvent(
                'server.business_logic.payment.creation',
                \DDTrace\Integrations\Stripe\StripeIntegration::extractCheckoutSessionFields($session)
            );

            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertNotEmpty($allEvents, 'Events should be captured');

            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');

            $this->assertNotNull($paymentEvent, 'Payment creation event should be found in captured events');

            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

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

    public function testCheckoutSessionCreateDirectMethodNonPaymentMode()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $sessionData = [
                'id' => 'cs_test_subscription_456',
                'object' => 'checkout.session',
                'mode' => 'subscription', // Not payment mode
                'amount_total' => 5000,
                'currency' => 'usd',
                'livemode' => false,
            ];

            $session = \Stripe\Checkout\Session::constructFrom($sessionData);

            if ($session->mode === 'payment') {
                \DDTrace\Integrations\Stripe\StripeIntegration::pushPaymentEvent(
                    'server.business_logic.payment.creation',
                    \DDTrace\Integrations\Stripe\StripeIntegration::extractCheckoutSessionFields($session)
                );
            }

            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);


            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');

            $this->assertNull($paymentEvent, 'Payment creation event should not be captured for subscription mode');
        });
    }

    public function testPaymentIntentCreateDirectMethod()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $paymentIntentData = [
                'id' => 'pi_test_direct_789',
                'object' => 'payment_intent',
                'amount' => 3500,
                'currency' => 'eur',
                'livemode' => false,
                'payment_method' => 'pm_test_direct_789',
                'status' => 'requires_confirmation',
            ];

            $paymentIntent = \Stripe\PaymentIntent::constructFrom($paymentIntentData);

            \DDTrace\Integrations\Stripe\StripeIntegration::pushPaymentEvent(
                'server.business_logic.payment.creation',
                \DDTrace\Integrations\Stripe\StripeIntegration::extractPaymentIntentFields($paymentIntent)
            );

            $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);

            $this->assertNotEmpty($allEvents, 'Events should be captured');

            $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');

            $this->assertNotNull($paymentEvent, 'Payment creation event should be found in captured events');

            $this->assertEquals('stripe', $paymentEvent['integration'], 'Integration should be stripe');

            $this->assertEquals('pi_test_direct_789', $paymentEvent['id'], 'Payment intent ID should match');
            $this->assertEquals(3500, $paymentEvent['amount'], 'Amount should be 3500');
            $this->assertEquals('eur', $paymentEvent['currency'], 'Currency should be eur');
            $this->assertEquals(false, $paymentEvent['livemode'], 'Livemode should be false');
            $this->assertEquals('pm_test_direct_789', $paymentEvent['payment_method'], 'Payment method should match');
        });
    }

    public function testCheckoutSessionCreateViaSessionService()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $mockResponse = [
                'id' => 'cs_test_session_service_123',
                'object' => 'checkout.session',
                'mode' => 'payment',
                'amount_total' => 4200,
                'currency' => 'eur',
                'livemode' => false,
                'client_reference_id' => 'ref_session_service',
                'total_details' => ['amount_discount' => 0, 'amount_shipping' => 200],
                'discounts' => [],
            ];
            $mock = new MockStripeHttpClient(json_encode($mockResponse));
            \Stripe\ApiRequestor::setHttpClient($mock);
            try {
                $client = new \Stripe\StripeClient('sk_test_fake_key_for_testing');
                $session = $client->checkout->sessions->create([
                    'mode' => 'payment',
                    'success_url' => 'https://example.com/success',
                    'cancel_url' => 'https://example.com/cancel',
                    'line_items' => [['price_data' => ['currency' => 'eur', 'product_data' => ['name' => 'Test'], 'unit_amount' => 4200], 'quantity' => 1]],
                ]);
                $this->assertSame('cs_test_session_service_123', $session->id);

                $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);
                $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');
                $this->assertNotNull($paymentEvent, 'Payment creation event should be captured by SessionService::create hook');
                $this->assertEquals('stripe', $paymentEvent['integration']);
                $this->assertEquals('cs_test_session_service_123', $paymentEvent['id']);
                $this->assertEquals(4200, $paymentEvent['amount_total']);
                $this->assertEquals('eur', $paymentEvent['currency']);
                $this->assertEquals('ref_session_service', $paymentEvent['client_reference_id']);
            } finally {
                \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
            }
        });
    }

    public function testCheckoutSessionCreateViaStaticMethod()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $mockResponse = [
                'id' => 'cs_test_static_456',
                'object' => 'checkout.session',
                'mode' => 'payment',
                'amount_total' => 1000,
                'currency' => 'usd',
                'livemode' => false,
                'client_reference_id' => 'ref_static',
                'total_details' => ['amount_discount' => 0, 'amount_shipping' => 0],
                'discounts' => [],
            ];
            $mock = new MockStripeHttpClient(json_encode($mockResponse));
            \Stripe\ApiRequestor::setHttpClient($mock);
            try {
                $session = \Stripe\Checkout\Session::create([
                    'mode' => 'payment',
                    'success_url' => 'https://example.com/success',
                    'cancel_url' => 'https://example.com/cancel',
                    'line_items' => [['price_data' => ['currency' => 'usd', 'product_data' => ['name' => 'Item'], 'unit_amount' => 1000], 'quantity' => 1]],
                ]);
                $this->assertSame('cs_test_static_456', $session->id);

                $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);
                $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');
                $this->assertNotNull($paymentEvent, 'Payment creation event should be captured by Checkout\Session::create hook');
                $this->assertEquals('stripe', $paymentEvent['integration']);
                $this->assertEquals('cs_test_static_456', $paymentEvent['id']);
                $this->assertEquals(1000, $paymentEvent['amount_total']);
                $this->assertEquals('usd', $paymentEvent['currency']);
            } finally {
                \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
            }
        });
    }

    public function testPaymentIntentCreateViaPaymentIntentService()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $mockResponse = [
                'id' => 'pi_test_service_789',
                'object' => 'payment_intent',
                'amount' => 5000,
                'currency' => 'gbp',
                'livemode' => false,
                'payment_method' => 'pm_test_service_789',
                'status' => 'requires_payment_method',
            ];
            $mock = new MockStripeHttpClient(json_encode($mockResponse));
            \Stripe\ApiRequestor::setHttpClient($mock);
            try {
                $client = new \Stripe\StripeClient('sk_test_fake_key_for_testing');
                $paymentIntent = $client->paymentIntents->create([
                    'amount' => 5000,
                    'currency' => 'gbp',
                    'payment_method_types' => ['card'],
                ]);
                $this->assertSame('pi_test_service_789', $paymentIntent->id);

                $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);
                $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');
                $this->assertNotNull($paymentEvent, 'Payment creation event should be captured by PaymentIntentService::create hook');
                $this->assertEquals('stripe', $paymentEvent['integration']);
                $this->assertEquals('pi_test_service_789', $paymentEvent['id']);
                $this->assertEquals(5000, $paymentEvent['amount']);
                $this->assertEquals('gbp', $paymentEvent['currency']);
                $this->assertEquals('pm_test_service_789', $paymentEvent['payment_method']);
            } finally {
                \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
            }
        });
    }

    public function testPaymentIntentCreateViaStaticMethod()
    {
        $this->isolateTracer(function () {
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $mockResponse = [
                'id' => 'pi_test_static_999',
                'object' => 'payment_intent',
                'amount' => 7500,
                'currency' => 'jpy',
                'livemode' => false,
                'payment_method' => 'pm_test_static_999',
                'status' => 'requires_confirmation',
            ];
            $mock = new MockStripeHttpClient(json_encode($mockResponse));
            \Stripe\ApiRequestor::setHttpClient($mock);
            try {
                $paymentIntent = \Stripe\PaymentIntent::create([
                    'amount' => 7500,
                    'currency' => 'jpy',
                    'payment_method_types' => ['card'],
                ]);
                $this->assertSame('pi_test_static_999', $paymentIntent->id);

                $allEvents = AppsecStatus::getInstance()->getEvents(['push_addresses'], []);
                $paymentEvent = $this->findEventByKey($allEvents, 'server.business_logic.payment.creation');
                $this->assertNotNull($paymentEvent, 'Payment creation event should be captured by PaymentIntent::create hook');
                $this->assertEquals('stripe', $paymentEvent['integration']);
                $this->assertEquals('pi_test_static_999', $paymentEvent['id']);
                $this->assertEquals(7500, $paymentEvent['amount']);
                $this->assertEquals('jpy', $paymentEvent['currency']);
                $this->assertEquals('pm_test_static_999', $paymentEvent['payment_method']);
            } finally {
                \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
            }
        });
    }
}

/**
 * Mock HTTP client for Stripe API requests. Returns a fixed JSON response so hooks can run without a real server.
 */
class MockStripeHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /** @var string */
    private $responseBody;
    /** @var int */
    private $responseCode;

    public function __construct(string $responseBody, int $responseCode = 200)
    {
        $this->responseBody = $responseBody;
        $this->responseCode = $responseCode;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        return [$this->responseBody, $this->responseCode, []];
    }
}
