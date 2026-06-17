<?php

namespace DDTrace\FeatureFlags;

use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\TriggerErrorLogger;

final class Client
{
    private $evaluator;
    private $logger;
    private $warnedAboutNonProductionRuntime = false;
    /** @var SpanEnrichmentBinder|null Null unless the span-enrichment gate is on. */
    private $spanEnrichmentBinder = null;

    public function __construct($logger = null)
    {
        if ($logger !== null && !$logger instanceof LoggerInterface) {
            throw new \InvalidArgumentException('Expected a LoggerInterface instance');
        }

        $this->evaluator = NativeEvaluator::create();
        $this->logger = $logger ?: new TriggerErrorLogger();
        // DG-004/DG-005: the native Client does NOT go through the OpenFeature
        // provider, so APM span enrichment is bound here on the same evaluation
        // path. To stay fully inert with the gate off (PR review should-fix:
        // gate-off must allocate no binder and read no per-evaluation config),
        // construct the binder ONLY when the experimental span-enrichment gate
        // is on; when it is off $spanEnrichmentBinder stays null and evaluate()
        // skips the enrichment call entirely.
        if (SpanEnrichmentBinder::gateEnabled()) {
            $this->spanEnrichmentBinder = new SpanEnrichmentBinder();
        }
    }

    /**
     * @return bool
     */
    public function getBooleanValue($flagKey, $defaultValue, array $context = array())
    {
        return $this->getBooleanDetails($flagKey, $defaultValue, $context)->getValue();
    }

    /**
     * @return string
     */
    public function getStringValue($flagKey, $defaultValue, array $context = array())
    {
        return $this->getStringDetails($flagKey, $defaultValue, $context)->getValue();
    }

    /**
     * @return int
     */
    public function getIntegerValue($flagKey, $defaultValue, array $context = array())
    {
        return $this->getIntegerDetails($flagKey, $defaultValue, $context)->getValue();
    }

    /**
     * @return float
     */
    public function getFloatValue($flagKey, $defaultValue, array $context = array())
    {
        return $this->getFloatDetails($flagKey, $defaultValue, $context)->getValue();
    }

    /**
     * @return array<string, mixed>
     */
    public function getObjectValue($flagKey, array $defaultValue, array $context = array())
    {
        return $this->getObjectDetails($flagKey, $defaultValue, $context)->getValue();
    }

    /**
     * @return EvaluationDetails
     */
    public function getBooleanDetails($flagKey, $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::BOOLEAN, $this->expectBoolean($defaultValue), $context);
    }

    /**
     * @return EvaluationDetails
     */
    public function getStringDetails($flagKey, $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::STRING, $this->expectString($defaultValue), $context);
    }

    /**
     * @return EvaluationDetails
     */
    public function getIntegerDetails($flagKey, $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::INTEGER, $this->expectInteger($defaultValue), $context);
    }

    /**
     * @return EvaluationDetails
     */
    public function getFloatDetails($flagKey, $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::FLOAT, $this->expectFloat($defaultValue), $context);
    }

    /**
     * @return EvaluationDetails
     */
    public function getObjectDetails($flagKey, array $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::OBJECT, $defaultValue, $context);
    }

    private function evaluate($flagKey, $expectedType, $defaultValue, array $context)
    {
        $flagKey = $this->expectFlagKey($flagKey);
        list($targetingKey, $attributes) = $this->normalizeContext($context);

        $details = $this->evaluator->evaluate(
            $flagKey,
            $expectedType,
            $defaultValue,
            $targetingKey,
            $attributes
        );

        $this->warnIfNonProductionRuntime($details);
        // APM span enrichment. Skipped entirely with the gate off (no binder was
        // constructed). When on, accumulates from the same EvaluationDetails the
        // caller receives into the shared request-scoped registry; the native
        // close-span path writes the staged ffe_* tags onto the root span.
        if ($this->spanEnrichmentBinder !== null) {
            $this->spanEnrichmentBinder->accumulate($flagKey, $details, $targetingKey);
        }

        return $details;
    }

    private function normalizeContext(array $context)
    {
        $targetingKey = null;
        if (array_key_exists('targetingKey', $context) && $context['targetingKey'] !== null) {
            $targetingKey = (string) $context['targetingKey'];
        }

        $attributes = array();
        if (isset($context['attributes']) && is_array($context['attributes'])) {
            foreach ($context['attributes'] as $key => $value) {
                if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                    $attributes[(string) $key] = $value;
                }
            }
        }

        return array($targetingKey, $attributes);
    }

    private function warnIfNonProductionRuntime(EvaluationDetails $details)
    {
        if ($this->warnedAboutNonProductionRuntime) {
            return;
        }

        $providerState = $details->getProviderState();
        if (!array_key_exists('productionRuntime', $providerState) || $providerState['productionRuntime'] !== false) {
            return;
        }

        $message = $details->getErrorMessage();
        if (!is_string($message) || $message === '') {
            $message = 'Datadog-backed PHP feature flag evaluation is running without exposure and metric reporting in this milestone.';
        }

        $this->logger->warning($message);
        $this->warnedAboutNonProductionRuntime = true;
    }

    private function expectFlagKey($flagKey)
    {
        if (!is_string($flagKey) || $flagKey === '') {
            throw new \InvalidArgumentException('Feature flag key must be a non-empty string');
        }

        return $flagKey;
    }

    private function expectBoolean($value)
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('Boolean flag default value must be a bool');
        }

        return $value;
    }

    private function expectString($value)
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('String flag default value must be a string');
        }

        return $value;
    }

    private function expectInteger($value)
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Integer flag default value must be an int');
        }

        return $value;
    }

    private function expectFloat($value)
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException('Float flag default value must be a number');
        }

        return (float) $value;
    }
}
