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

    /**
     * Get nested value from array or object using dot notation
     */
    private static function getNestedValue($data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } elseif (is_object($value)) {
                // Try to access as property
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

        // Convert final value if it's an object
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }

    /**
     * Convert object to array recursively
     * Handles Stripe objects which have a toArray() method
     */
    private static function objectToArray($obj)
    {
        // Check if it's a Stripe object with toArray() method
        if (is_object($obj) && method_exists($obj, 'toArray')) {
            return $obj->toArray();
        }

        // For other objects, try to access public properties via get_object_vars
        // or cast to array as fallback
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

    /**
     * Extract fields for checkout session creation
     */
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

        // Handle discounts array - must take first element as per RFC
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

    /**
     * Extract fields for payment intent creation
     */
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

    /**
     * Extract fields for payment success webhook
     */
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

    /**
     * Extract fields for payment failure webhook
     */
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

    /**
     * Extract fields for payment cancellation webhook
     */
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

    /**
     * Add instrumentation to Stripe SDK
     */
    public static function init(): int
    {
        error_log("STRIPE DEBUG: StripeIntegration::init() called!");
        file_put_contents('/tmp/stripe_init.log', "Stripe integration initialized at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        \DDTrace\hook_method(
            'Stripe\Service\Checkout\SessionService',
            'create',
            function ($This, $scope, $args) {
                // Prehook
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
                error_log("STRIPE DEBUG: Webhook::constructEvent prehook called");
            },
            function ($This, $scope, $args, $retval, $exception) {
                error_log("STRIPE DEBUG: Webhook::constructEvent posthook called");
                error_log("STRIPE DEBUG: exception: " . var_export($exception, true));
                error_log("STRIPE DEBUG: retval type: " . (is_object($retval) ? get_class($retval) : gettype($retval)));

                if ($exception !== null) {
                    error_log("STRIPE DEBUG: Exception is not null, returning");
                    return;
                }

                if ($retval === null) {
                    error_log("STRIPE DEBUG: retval is null for Webhook, returning");
                    return;
                }

                // Get event type
                $eventType = null;
                if (is_object($retval) && isset($retval->type)) {
                    $eventType = $retval->type;
                } elseif (is_array($retval) && isset($retval['type'])) {
                    $eventType = $retval['type'];
                }

                error_log("STRIPE DEBUG: Webhook event type: " . var_export($eventType, true));

                if ($eventType === null) {
                    error_log("STRIPE DEBUG: Webhook event type is null, returning");
                    return;
                }

                // Get event object data
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

        // Also hook Event::constructFrom in case webhooks are constructed without signature validation
        \DDTrace\hook_method(
            'Stripe\Event',
            'constructFrom',
            function ($This, $scope, $args) {
                // Prehook
                error_log("STRIPE DEBUG: Event::constructFrom prehook called");
            },
            function ($This, $scope, $args, $retval) {
                error_log("STRIPE DEBUG: Event::constructFrom posthook called");
                error_log("STRIPE DEBUG: retval type: " . (is_object($retval) ? get_class($retval) : gettype($retval)));

                if ($retval === null) {
                    error_log("STRIPE DEBUG: retval is null, returning");
                    return;
                }

                // Get event type
                $eventType = null;
                if (is_object($retval) && isset($retval->type)) {
                    $eventType = $retval->type;
                } elseif (is_array($retval) && isset($retval['type'])) {
                    $eventType = $retval['type'];
                }

                error_log("STRIPE DEBUG: Event type: " . var_export($eventType, true));

                if ($eventType === null) {
                    error_log("STRIPE DEBUG: Event type is null, returning");
                    return;
                }

                // Get event object data
                $eventObject = null;
                if (is_object($retval)) {
                    $eventObject = $retval->data->object ?? null;
                } elseif (is_array($retval)) {
                    $eventObject = $retval['data']['object'] ?? $retval['object'] ?? null;
                }

                error_log("STRIPE DEBUG: Event object: " . (is_object($eventObject) ? get_class($eventObject) : gettype($eventObject)));

                if ($eventObject === null) {
                    error_log("STRIPE DEBUG: Event object is null, returning");
                    return;
                }

                error_log("STRIPE DEBUG: About to process event type: " . $eventType);

                switch ($eventType) {
                    case 'payment_intent.succeeded':
                        error_log("STRIPE DEBUG: Processing payment_intent.succeeded");
                        $payload = self::extractPaymentSuccessFields($eventObject);
                        error_log("STRIPE DEBUG: Payload: " . json_encode($payload));
                        self::pushPaymentEvent('server.business_logic.payment.success', $payload);
                        error_log("STRIPE DEBUG: Payment success event pushed");
                        break;

                    case 'payment_intent.payment_failed':
                        error_log("STRIPE DEBUG: Processing payment_intent.payment_failed");
                        $payload = self::extractPaymentFailureFields($eventObject);
                        self::pushPaymentEvent('server.business_logic.payment.failure', $payload);
                        break;

                    case 'payment_intent.canceled':
                        error_log("STRIPE DEBUG: Processing payment_intent.canceled");
                        $payload = self::extractPaymentCancellationFields($eventObject);
                        self::pushPaymentEvent('server.business_logic.payment.cancellation', $payload);
                        break;

                    default:
                        error_log("STRIPE DEBUG: Unhandled event type: " . $eventType);
                        break;
                }
            }
        );

        return Integration::LOADED;
    }
}
