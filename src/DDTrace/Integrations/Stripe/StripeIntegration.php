<?php

namespace DDTrace\Integrations\Stripe;

use DDTrace\Integrations\Integration;

class StripeIntegration extends Integration
{
    const NAME = 'stripe';

    public static function pushPaymentEvent(string $address, array $data)
    {
        if (function_exists('datadog\appsec\push_addresses')) {
            \datadog\appsec\push_addresses([$address => $data]);
        }
    }

    public static function flattenFields($data, array $fieldPaths): array
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

    private static function getNestedValue($data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

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

    private static function objectToArray($obj)
    {
        if (is_object($obj) && method_exists($obj, 'toArray')) {
            return $obj->toArray();
        }

        if (is_object($obj)) {
            $vars = get_object_vars($obj);
            if (!empty($vars)) {
                return array_map([self::class, 'objectToArray'], $vars);
            }
            $obj = (array)$obj;
        }

        if (is_array($obj)) {
            return array_map([self::class, 'objectToArray'], $obj);
        }

        return $obj;
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
        $fields = [
            'id',
            'amount',
            'currency',
            'livemode',
            'payment_method',
        ];

        return self::flattenFields($result, $fields);
    }

    public static function extractPaymentSuccessFields($eventData): array
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

    public static function extractPaymentFailureFields($eventData): array
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

    public static function extractPaymentCancellationFields($eventData): array
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

    public static function init(): int
    {
        file_put_contents('/tmp/stripe_init.log', "Stripe integration initialized at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        \DDTrace\hook_method(
            'Stripe\Service\Checkout\SessionService',
            'create',
            null,
            static function ($This, $scope, $args, $retval) {
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
            null,
            static function ($This, $scope, $args, $retval) {
                if ($retval === null) {
                    return;
                }


                $payload = self::extractPaymentIntentFields($retval);
                self::pushPaymentEvent('server.business_logic.payment.creation', $payload);
            }
        );

        \DDTrace\hook_method(
            'Stripe\Checkout\Session',
            'create',
            null,
            static function ($This, $scope, $args, $retval) {
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
            'Stripe\PaymentIntent',
            'create',
            null,
            static function ($This, $scope, $args, $retval) {
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
            null,
            static function ($This, $scope, $args, $retval, $exception) {

                if ($exception !== null) {
                    return;
                }

                if ($retval === null) {
                    return;
                }

                $eventType = null;
                if (is_object($retval) && isset($retval->type)) {
                    $eventType = $retval->type;
                } elseif (is_array($retval) && isset($retval['type'])) {
                    $eventType = $retval['type'];
                }


                if ($eventType === null) {
                    return;
                }

                $eventObject = null;
                if (is_object($retval)) {
                    $eventObject = $retval->data->object ?? null;
                } elseif (is_array($retval)) {
                    $eventObject = $retval['data']['object'] ?? $retval['object'] ?? null;
                }

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

        \DDTrace\hook_method(
            'Stripe\Event',
            'constructFrom',
            null,
            static function ($This, $scope, $args, $retval) {

                if ($retval === null) {
                    return;
                }

                $eventType = null;
                if (is_object($retval) && isset($retval->type)) {
                    $eventType = $retval->type;
                } elseif (is_array($retval) && isset($retval['type'])) {
                    $eventType = $retval['type'];
                }


                if ($eventType === null) {
                    return;
                }

                $eventObject = null;
                if (is_object($retval)) {
                    $eventObject = $retval->data->object ?? null;
                } elseif (is_array($retval)) {
                    $eventObject = $retval['data']['object'] ?? $retval['object'] ?? null;
                }


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
