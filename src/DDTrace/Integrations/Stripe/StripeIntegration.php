<?php

namespace DDTrace\Integrations\Stripe;

use DDTrace\Integrations\Integration;

class StripeIntegration extends Integration
{
    const NAME = 'stripe';

    /**
     * Push payment event to AppSec
     */
    public static function pushPaymentEvent(string $address, array $data)
    {
        if (function_exists('datadog\appsec\push_addresses')) {
            \datadog\appsec\push_addresses([$address => $data]);
        }
    }

    /**
     * Flatten nested object fields for WAF payload
     * Handles nested arrays and objects according to RFC specs
     */
    public static function flattenFields(array $data, array $fieldPaths): array
    {
        $result = ['integration' => 'stripe'];

        foreach ($fieldPaths as $path) {
            $value = self::getNestedValue($data, $path);
            if ($value !== null) {
                $result[$path] = $value;
            }
        }

        return $result;
    }

    /**
     * Get nested value from array using dot notation
     */
    private static function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } elseif (is_object($value) && isset($value->$key)) {
                $value = $value->$key;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Convert object to array recursively
     */
    private static function objectToArray($obj)
    {
        if (is_object($obj)) {
            $obj = (array)$obj;
        }
        if (is_array($obj)) {
            return array_map([self::class, 'objectToArray'], $obj);
        }
        return $obj;
    }

    /**
     * Extract fields for checkout session creation
     */
    public static function extractCheckoutSessionFields($result): array
    {
        $data = is_object($result) ? self::objectToArray($result) : $result;

        $fields = [
            'id',
            'amount_total',
            'client_reference_id',
            'currency',
            'livemode',
            'total_details.amount_discount',
            'total_details.amount_shipping',
        ];

        $payload = self::flattenFields($data, $fields);

        // Handle discounts array - must take first element as per RFC
        if (isset($data['discounts']) && is_array($data['discounts']) && count($data['discounts']) > 0) {
            $discount = $data['discounts'][0];
            $payload['discounts.coupon'] = $discount['coupon'] ?? null;
            $payload['discounts.promotion_code'] = $discount['promotion_code'] ?? null;
        } else {
            $payload['discounts.coupon'] = null;
            $payload['discounts.promotion_code'] = null;
        }

        return $payload;
    }

    /**
     * Extract fields for payment intent creation
     */
    public static function extractPaymentIntentFields($result): array
    {
        $data = is_object($result) ? self::objectToArray($result) : $result;

        $fields = [
            'id',
            'amount',
            'currency',
            'livemode',
            'payment_method',
        ];

        return self::flattenFields($data, $fields);
    }

    /**
     * Extract fields for payment success webhook
     */
    public static function extractPaymentSuccessFields(array $eventData): array
    {
        $fields = [
            'id',
            'amount',
            'currency',
            'livemode',
            'payment_method',
        ];

        return self::flattenFields($eventData, $fields);
    }

    /**
     * Extract fields for payment failure webhook
     */
    public static function extractPaymentFailureFields(array $eventData): array
    {
        $fields = [
            'id',
            'amount',
            'currency',
            'livemode',
            'last_payment_error.code',
            'last_payment_error.decline_code',
            'last_payment_error.payment_method.id',
            'last_payment_error.payment_method.type',
        ];

        return self::flattenFields($eventData, $fields);
    }

    /**
     * Extract fields for payment cancellation webhook
     */
    public static function extractPaymentCancellationFields(array $eventData): array
    {
        $fields = [
            'id',
            'amount',
            'cancellation_reason',
            'currency',
            'livemode',
        ];

        return self::flattenFields($eventData, $fields);
    }

    /**
     * Add instrumentation to Stripe SDK
     */
    public static function init(): int
    {
        \DDTrace\hook_method(
            'Stripe\Service\Checkout\SessionService',
            'create',
            function ($This, $scope, $args) {
                // Prehook - will execute after the method
            },
            function ($This, $scope, $args, $retval) {
                if ($retval === null) {
                    return;
                }

                $mode = null;
                if (is_object($retval) && isset($retval->mode)) {
                    $mode = $retval->mode;
                } elseif (is_array($retval) && isset($retval['mode'])) {
                    $mode = $retval['mode'];
                }

                if ($mode !== 'payment') {
                    return;
                }

                $payload = self::extractCheckoutSessionFields($retval);
                self::pushPaymentEvent('server.business_logic.payment.creation', $payload);
            }
        );

        \DDTrace\hook_method(
            'Stripe\Service\PaymentIntentService',
            'create',
            function ($This, $scope, $args) {
                // Prehook
            },
            function ($This, $scope, $args, $retval) {
                if ($retval === null) {
                    return;
                }

                $payload = self::extractPaymentIntentFields($retval);
                self::pushPaymentEvent('server.business_logic.payment.creation', $payload);
            }
        );

        \DDTrace\hook_method(
            'Stripe\Webhook',
            'constructEvent',
            function ($This, $scope, $args) {
                // Prehook
            },
            function ($This, $scope, $args, $retval, $exception) {
                if ($exception !== null) {
                    return;
                }

                if ($retval === null) {
                    return;
                }

                $event = is_object($retval) ? self::objectToArray($retval) : $retval;

                $eventType = $event['type'] ?? null;
                if ($eventType === null) {
                    return;
                }

                $eventObject = $event['data']['object'] ?? $event['object'] ?? null;
                if ($eventObject === null) {
                    return;
                }

                switch ($eventType) {
                    case 'payment_intent.succeeded':
                        $payload = self::extractPaymentSuccessFields($eventObject);
                        self::pushPaymentEvent('server.business_logic.payment.success', $payload);
                        break;

                    case 'payment_intent.payment_failed':
                        $payload = self::extractPaymentFailureFields($eventObject);
                        self::pushPaymentEvent('server.business_logic.payment.failure', $payload);
                        break;

                    case 'payment_intent.canceled':
                        $payload = self::extractPaymentCancellationFields($eventObject);
                        self::pushPaymentEvent('server.business_logic.payment.cancellation', $payload);
                        break;

                    default:
                        break;
                }
            }
        );

        return Integration::LOADED;
    }
}
