<?php

require_once __DIR__ . '/vendor/autoload.php';

// Set your secret key. Remember to switch to your live secret key in production.
// See your keys here: https://dashboard.stripe.com/apikeys
\Stripe\Stripe::setApiKey(getenv('STRIPE_TEST_API_KEY'));

$response = \Stripe\PaymentIntent::create([
    'amount' => 1100,
    'currency' => 'usd',
    'payment_method_types' => ['card'],
    'receipt_email' => 'jenny.rosen@example.com',
]);

error_log('Response: ' . var_export($response, 1));
