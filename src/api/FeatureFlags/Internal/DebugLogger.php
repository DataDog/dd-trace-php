<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\LogLevel;
use DDTrace\Log\NonThrowingLogger;

/** @internal */
final class DebugLogger
{
    private $logger;
    private $ready;

    private function __construct(NonThrowingLogger $logger)
    {
        $this->logger = $logger;
    }

    /** @return self|null Debug logging is selected once per client/provider. */
    public static function create(LoggerInterface $logger)
    {
        if (!$logger instanceof NonThrowingLogger) {
            $logger = new NonThrowingLogger($logger);
        }
        return $logger->isLevelActive(LogLevel::DEBUG) ? new self($logger) : null;
    }

    public function initialize()
    {
        if (!NativeEvaluator::isAvailable()) {
            $this->logger->debug('Feature Flags: provider unavailable; the ddtrace FFE runtime is not loaded');
            return;
        }

        // Subscription settings are process-wide and are logged by the native
        // startup path. Request-local INI values do not change that subscription.
        $this->logger->debug('Feature Flags: provider created; native evaluator is available');
        $this->logger->debug('Feature Flags: evaluation settings {settings}', array('settings' => json_encode(array(
            'DD_ENV' => \dd_trace_env_config('DD_ENV'),
            'DD_SERVICE' => \dd_trace_env_config('DD_SERVICE'),
        ), JSON_PARTIAL_OUTPUT_ON_ERROR)));
        $this->logReadiness(\DDTrace\ffe_has_config());
    }

    public function evaluation($flagKey, EvaluationDetails $details)
    {
        $state = $details->getProviderState();
        if (isset($state['ready']) && is_bool($state['ready'])) {
            $this->logReadiness($state['ready']);
        }

        // Flag keys and variant identifiers are diagnostic identifiers. Values,
        // defaults, targeting keys, attributes, arbitrary metadata and raw error
        // messages can contain customer data and must never be serialized here.
        // JSON also escapes control characters in identifiers to keep one event
        // per log line. Build it only when debug logging is active.
        $this->logger->debug('Feature Flags: evaluated {details}', array('details' => json_encode(array(
            'flagKey' => $flagKey,
            'valueType' => $details->getValueType(),
            'reason' => $details->getReason(),
            'variant' => $details->getVariant(),
            'errorCode' => $details->getErrorCode(),
            'configVersion' => isset($state['configVersion']) && is_int($state['configVersion'])
                ? $state['configVersion'] : null,
        ), JSON_PARTIAL_OUTPUT_ON_ERROR)));
    }

    private function logReadiness($ready)
    {
        if ($this->ready === $ready) {
            return;
        }
        $this->ready = $ready;
        $this->logger->debug($ready
            ? 'Feature Flags: provider ready; Remote Configuration is available'
            : 'Feature Flags: provider not ready; no usable Remote Configuration, returning default values');
    }
}
