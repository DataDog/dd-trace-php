<?php

require_once __DIR__ . '/../vendor/autoload.php';

$stripeApiBase = getenv('STRIPE_API_BASE') ?: 'http://localhost:8086';
$action = $_GET['action'] ?? 'checkout_session';

\Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

try {
    switch ($action) {
        case 'checkout_session':
            $client = new \Stripe\StripeClient([
                'api_key' => 'sk_test_fake_key_for_testing',
                'api_base' => $stripeApiBase
            ]);

            $session = $client->checkout->sessions->create([
                'mode' => 'payment',
                'success_url' => 'https://example.com/success',
                'cancel_url' => 'https://example.com/cancel',
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => 'usd',
                            'product_data' => ['name' => 'Test Product'],
                            'unit_amount' => 1000,
                        ],
                        'quantity' => 1,
                    ],
                ],
                'client_reference_id' => 'test_ref_123',
            ]);

            echo json_encode([
                'status' => 'success',
                'action' => 'checkout_session',
                'session_id' => $session->id
            ]);
            break;

        case 'checkout_session_subscription':
            $client = new \Stripe\StripeClient([
                'api_key' => 'sk_test_fake_key_for_testing',
                'api_base' => $stripeApiBase
            ]);

            $session = $client->checkout->sessions->create([
                'mode' => 'subscription',
                'success_url' => 'https://example.com/success',
                'cancel_url' => 'https://example.com/cancel',
                'line_items' => [
                    [
                        'price' => 'price_123',
                        'quantity' => 1,
                    ],
                ],
            ]);

            echo json_encode([
                'status' => 'success',
                'action' => 'checkout_session_subscription',
                'session_id' => $session->id
            ]);
            break;

        case 'payment_intent':
            $client = new \Stripe\StripeClient([
                'api_key' => 'sk_test_fake_key_for_testing',
                'api_base' => $stripeApiBase
            ]);

            $paymentIntent = $client->paymentIntents->create([
                'amount' => 2000,
                'currency' => 'usd',
                'payment_method_types' => ['card'],
            ]);

            echo json_encode([
                'status' => 'success',
                'action' => 'payment_intent',
                'payment_intent_id' => $paymentIntent->id
            ]);
            break;

        case 'webhook_success':
            new \Stripe\StripeClient(['api_key' => 'sk_test_fake_key_for_testing']);

            $payload = json_encode([
                'id' => 'evt_test_success_' . time(),
                'object' => 'event',
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'pi_test_success_123',
                        'object' => 'payment_intent',
                        'amount' => 2000,
                        'currency' => 'usd',
                        'livemode' => false,
                        'payment_method' => 'pm_test_123',
                        'status' => 'succeeded'
                    ]
                ]
            ]);

            $timestamp = time();
            $secret = 'whsec_test_secret';
            $signedPayload = $timestamp . '.' . $payload;
            $signature = hash_hmac('sha256', $signedPayload, $secret);
            $sigHeader = "t={$timestamp},v1={$signature}";

            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
            } catch (\Exception $e) {
                $event = \Stripe\Event::constructFrom(json_decode($payload, true));
            }

            echo json_encode([
                'status' => 'success',
                'action' => 'webhook_success',
                'event_id' => $event->id,
                'event_type' => $event->type,
                'debug' => 'webhook event constructed'
            ]);
            break;

        case 'webhook_failure':
            new \Stripe\StripeClient(['api_key' => 'sk_test_fake_key_for_testing']);

            $payload = json_encode([
                'id' => 'evt_test_failure_' . time(),
                'object' => 'event',
                'type' => 'payment_intent.payment_failed',
                'data' => [
                    'object' => [
                        'id' => 'pi_test_failure_456',
                        'object' => 'payment_intent',
                        'amount' => 1500,
                        'currency' => 'eur',
                        'livemode' => false,
                        'last_payment_error' => [
                            'code' => 'card_declined',
                            'decline_code' => 'insufficient_funds',
                            'payment_method' => [
                                'id' => 'pm_test_456',
                                'type' => 'card'
                            ]
                        ],
                        'status' => 'requires_payment_method'
                    ]
                ]
            ]);

            $timestamp = time();
            $secret = 'whsec_test_secret';
            $signedPayload = $timestamp . '.' . $payload;
            $signature = hash_hmac('sha256', $signedPayload, $secret);
            $sigHeader = "t={$timestamp},v1={$signature}";

            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
            } catch (\Exception $e) {
                $event = \Stripe\Event::constructFrom(json_decode($payload, true));
            }

            echo json_encode([
                'status' => 'success',
                'action' => 'webhook_failure',
                'event_id' => $event->id,
                'event_type' => $event->type
            ]);
            break;

        case 'webhook_cancellation':
            new \Stripe\StripeClient(['api_key' => 'sk_test_fake_key_for_testing']);

            $payload = json_encode([
                'id' => 'evt_test_cancel_' . time(),
                'object' => 'event',
                'type' => 'payment_intent.canceled',
                'data' => [
                    'object' => [
                        'id' => 'pi_test_cancel_789',
                        'object' => 'payment_intent',
                        'amount' => 3000,
                        'currency' => 'gbp',
                        'livemode' => false,
                        'cancellation_reason' => 'requested_by_customer',
                        'status' => 'canceled'
                    ]
                ]
            ]);

            $timestamp = time();
            $secret = 'whsec_test_secret';
            $signedPayload = $timestamp . '.' . $payload;
            $signature = hash_hmac('sha256', $signedPayload, $secret);
            $sigHeader = "t={$timestamp},v1={$signature}";

            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
            } catch (\Exception $e) {
                $event = \Stripe\Event::constructFrom(json_decode($payload, true));
            }

            echo json_encode([
                'status' => 'success',
                'action' => 'webhook_cancellation',
                'event_id' => $event->id,
                'event_type' => $event->type
            ]);
            break;

        case 'webhook_unsupported':
            new \Stripe\StripeClient(['api_key' => 'sk_test_fake_key_for_testing']);

            $payload = json_encode([
                'id' => 'evt_test_unsupported_' . time(),
                'object' => 'event',
                'type' => 'customer.created',
                'data' => [
                    'object' => [
                        'id' => 'cus_test_123',
                        'object' => 'customer',
                        'email' => 'test@example.com'
                    ]
                ]
            ]);

            $timestamp = time();
            $secret = 'whsec_test_secret';
            $signedPayload = $timestamp . '.' . $payload;
            $signature = hash_hmac('sha256', $signedPayload, $secret);
            $sigHeader = "t={$timestamp},v1={$signature}";

            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
            } catch (\Exception $e) {
                // Fallback to direct construction if webhook fails
                $event = \Stripe\Event::constructFrom(json_decode($payload, true));
            }

            echo json_encode([
                'status' => 'success',
                'action' => 'webhook_unsupported',
                'event_id' => $event->id,
                'event_type' => $event->type
            ]);
            break;

        case 'checkout_session_direct':
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $opts = ['api_base' => $stripeApiBase];

            $session = \Stripe\Checkout\Session::create([
                'mode' => 'payment',
                'success_url' => 'https://example.com/success',
                'cancel_url' => 'https://example.com/cancel',
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => 'usd',
                            'product_data' => ['name' => 'Test Product Direct'],
                            'unit_amount' => 2500,
                        ],
                        'quantity' => 2,
                    ],
                ],
                'client_reference_id' => 'test_ref_direct_456',
            ], $opts);

            echo json_encode([
                'status' => 'success',
                'action' => 'checkout_session_direct',
                'session_id' => $session->id
            ]);
            break;

        case 'payment_intent_direct':
            \Stripe\Stripe::setApiKey('sk_test_fake_key_for_testing');

            $opts = ['api_base' => $stripeApiBase];

            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => 3500,
                'currency' => 'eur',
                'payment_method_types' => ['card'],
            ], $opts);

            echo json_encode([
                'status' => 'success',
                'action' => 'payment_intent_direct',
                'payment_intent_id' => $paymentIntent->id
            ]);
            break;

        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Unknown action: ' . $action
            ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
