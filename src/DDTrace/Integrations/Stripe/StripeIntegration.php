<?php

namespace DDTrace\Integrations\Stripe;

use DDTrace\Integrations\Integration;

class StripeIntegration extends Integration
{
    const NAME = 'stripe';

    const EVENT_PAYMENT_SUCCEEDED = 'payment_intent.succeeded';
    const EVENT_PAYMENT_FAILED = 'payment_intent.payment_failed';
    const EVENT_PAYMENT_CANCELED = 'payment_intent.canceled';
    const CHECKOUT_MODE_PAYMENT = 'payment';

    public static function pushPaymentEvent(string $address, array $data)
    {
        if (function_exists('datadog\appsec\push_addresses')) {
            \datadog\appsec\push_addresses([$address => $data]);
        }
    }

    public static function flattenFields($data, array $fieldPaths): array
    {
        $result = ['integration' => self::NAME];

        foreach ($fieldPaths as $path) {
            $value = self::getNestedValue($data, $path);
            if ($value !== null) {
                $result[$path] = $value;
            }
        }

        return $result;
    }

    private static function getNestedValue($data, string $path)
    {
        $value = $data;
        $keys = explode('.', $path);

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } elseif (is_object($value)) {
                if (isset($value->$key)) {
                    $value = $value->$key;
                } elseif (property_exists($value, $key)) {
                    $value = $value->$key;
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }

    public static function extractCheckoutSessionFields($result): array
    {
        $fields = [
            'id',
            'amount_total',
            'client_reference_id',
            'currency',
            'livemode',
            'total_details.amount_discount',
            'total_details.amount_shipping',
        ];

        $payload = self::flattenFields($result, $fields);

        $discounts = self::getNestedValue($result, 'discounts');
        if (is_array($discounts) && count($discounts) > 0) {
            $discount = $discounts[0];
            $payload['discounts.coupon'] = self::getNestedValue($discount, 'coupon');
            $payload['discounts.promotion_code'] = self::getNestedValue($discount, 'promotion_code');
        } else {
            $payload['discounts.coupon'] = null;
            $payload['discounts.promotion_code'] = null;
        }

        return $payload;
    }

    public static function extractPaymentIntentFields($result): array
    {
        return self::flattenFields($result, [
            'id',
            'amount',
            'currency',
            'livemode',
            'payment_method',
        ]);
    }

    public static function extractPaymentSuccessFields($eventData): array
    {
        return self::flattenFields($eventData, [
            'id',
            'amount',
            'currency',
            'livemode',
            'payment_method',
        ]);
    }

    public static function extractPaymentFailureFields($eventData): array
    {
        return self::flattenFields($eventData, [
            'id',
            'amount',
            'currency',
            'livemode',
            'last_payment_error.code',
            'last_payment_error.decline_code',
            'last_payment_error.payment_method.id',
            'last_payment_error.payment_method.type',
        ]);
    }

    public static function extractPaymentCancellationFields($eventData): array
    {
        return self::flattenFields($eventData, [
            'id',
            'amount',
            'cancellation_reason',
            'currency',
            'livemode',
        ]);
    }

    public static function init(): int
    {
        self::hookCheckoutSessionCreate();
        self::hookPaymentIntentCreate();
        self::hookWebhookConstructEvent();
        self::hookEventConstructFrom();

        return Integration::LOADED;
    }

    private static function hookCheckoutSessionCreate()
    {
        $onCreate = static function ($This, $scope, $args, $retval) {
            if ($retval !== null && self::isCheckoutSessionPaymentMode($retval)) {
                $payload = self::extractCheckoutSessionFields($retval);
                self::pushPaymentEvent('server.business_logic.payment.creation', $payload);
            }
        };

        \DDTrace\hook_method('Stripe\Service\Checkout\SessionService', 'create', null, $onCreate);
        \DDTrace\hook_method('Stripe\Checkout\Session', 'create', null, $onCreate);
    }

    private static function hookPaymentIntentCreate()
    {
        $onCreate = static function ($This, $scope, $args, $retval) {
            if ($retval !== null) {
                $payload = self::extractPaymentIntentFields($retval);
                self::pushPaymentEvent('server.business_logic.payment.creation', $payload);
            }
        };

        \DDTrace\hook_method('Stripe\Service\PaymentIntentService', 'create', null, $onCreate);
        \DDTrace\hook_method('Stripe\PaymentIntent', 'create', null, $onCreate);
    }

    private static function hookWebhookConstructEvent()
    {
        \DDTrace\hook_method(
            'Stripe\Webhook',
            'constructEvent',
            null,
            static function ($This, $scope, $args, $retval, $exception) {
                if ($exception === null && $retval !== null) {
                    self::processWebhookEvent($retval);
                }
            }
        );
    }

    private static function hookEventConstructFrom()
    {
        \DDTrace\hook_method(
            'Stripe\Event',
            'constructFrom',
            null,
            static function ($This, $scope, $args, $retval, $exception) {
                if ($exception === null && $retval !== null) {
                    self::processWebhookEvent($retval);
                }
            }
        );
    }

    private static function isCheckoutSessionPaymentMode($session): bool
    {
        $mode = self::getNestedValue($session, 'mode');
        return $mode === self::CHECKOUT_MODE_PAYMENT;
    }

    private static function processWebhookEvent($event)
    {
        $eventType = self::getNestedValue($event, 'type');
        $eventObject = self::getNestedValue($event, 'data.object') ?? self::getNestedValue($event, 'object');

        if ($eventType === null || $eventObject === null) {
            return;
        }

        switch ($eventType) {
            case self::EVENT_PAYMENT_SUCCEEDED:
                $payload = self::extractPaymentSuccessFields($eventObject);
                self::pushPaymentEvent('server.business_logic.payment.success', $payload);
                break;
            case self::EVENT_PAYMENT_FAILED:
                $payload = self::extractPaymentFailureFields($eventObject);
                self::pushPaymentEvent('server.business_logic.payment.failure', $payload);
                break;
            case self::EVENT_PAYMENT_CANCELED:
                $payload = self::extractPaymentCancellationFields($eventObject);
                self::pushPaymentEvent('server.business_logic.payment.cancellation', $payload);
                break;
            default:
                break;
        }
    }
}
