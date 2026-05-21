<?php

namespace DDTrace\FeatureFlags;

final class Client
{
    private $evaluator;
    private $warningEmitter;
    private $exposureWriter;
    private $metricsRecorder;
    private $warnedAboutNonProductionRuntime = false;

    public function __construct(
        Evaluator $evaluator,
        WarningEmitter $warningEmitter,
        $exposureWriter = null,
        $metricsRecorder = null
    ) {
        if ($exposureWriter !== null && !$exposureWriter instanceof ExposureWriter) {
            throw new \InvalidArgumentException('Expected an ExposureWriter instance');
        }

        if ($metricsRecorder !== null && !$metricsRecorder instanceof MetricsRecorder) {
            throw new \InvalidArgumentException('Expected a MetricsRecorder instance');
        }

        $this->evaluator = $evaluator;
        $this->warningEmitter = $warningEmitter;
        $this->exposureWriter = $exposureWriter ?: new NoopExposureWriter();
        $this->metricsRecorder = $metricsRecorder ?: new NoopMetricsRecorder();
    }

    public static function create(
        $evaluator = null,
        $warningEmitter = null,
        $exposureWriter = null,
        $metricsRecorder = null
    ) {
        if ($evaluator !== null && !$evaluator instanceof Evaluator) {
            throw new \InvalidArgumentException('Expected an Evaluator instance');
        }

        if ($warningEmitter !== null && !$warningEmitter instanceof WarningEmitter) {
            throw new \InvalidArgumentException('Expected a WarningEmitter instance');
        }

        return new self(
            $evaluator ?: NativeEvaluator::createOrUnavailable(),
            $warningEmitter ?: new TriggerErrorWarningEmitter(),
            $exposureWriter,
            $metricsRecorder
        );
    }

    public function getBooleanValue($flagKey, $defaultValue, array $context = array())
    {
        return $this->getBooleanDetails($flagKey, $defaultValue, $context)->getValue();
    }

    public function getStringValue($flagKey, $defaultValue, array $context = array())
    {
        return $this->getStringDetails($flagKey, $defaultValue, $context)->getValue();
    }

    public function getIntegerValue($flagKey, $defaultValue, array $context = array())
    {
        return $this->getIntegerDetails($flagKey, $defaultValue, $context)->getValue();
    }

    public function getFloatValue($flagKey, $defaultValue, array $context = array())
    {
        return $this->getFloatDetails($flagKey, $defaultValue, $context)->getValue();
    }

    public function getObjectValue($flagKey, array $defaultValue, array $context = array())
    {
        return $this->getObjectDetails($flagKey, $defaultValue, $context)->getValue();
    }

    public function getBooleanDetails($flagKey, $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::BOOLEAN, $this->expectBoolean($defaultValue), $context);
    }

    public function getStringDetails($flagKey, $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::STRING, $this->expectString($defaultValue), $context);
    }

    public function getIntegerDetails($flagKey, $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::INTEGER, $this->expectInteger($defaultValue), $context);
    }

    public function getFloatDetails($flagKey, $defaultValue, array $context = array())
    {
        return $this->evaluate($flagKey, EvaluationType::FLOAT, $this->expectFloat($defaultValue), $context);
    }

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
        $this->metricsRecorder->recordEvaluation(
            $flagKey,
            $details->getValueType(),
            $details->getReason(),
            $details->getErrorCode()
        );
        $this->writeExposure($flagKey, $targetingKey, $attributes, $details);

        return $details;
    }

    private function writeExposure($flagKey, $targetingKey, array $attributes, EvaluationDetails $details)
    {
        if ($details->isError()) {
            return;
        }

        $exposureData = $details->getExposureData();
        if (!$exposureData || (array_key_exists('doLog', $exposureData) && $exposureData['doLog'] === false)) {
            return;
        }

        $event = array(
            'flagKey' => $flagKey,
            'targetingKey' => $targetingKey,
            'attributes' => $attributes,
            'value' => $details->getValue(),
            'valueType' => $details->getValueType(),
            'reason' => $details->getReason(),
            'variant' => $details->getVariant(),
            'flagMetadata' => $details->getFlagMetadata(),
            'exposureData' => $exposureData,
        );

        if (array_key_exists('allocationKey', $exposureData)) {
            $event['allocationKey'] = $exposureData['allocationKey'];
        }

        if (array_key_exists('doLog', $exposureData)) {
            $event['doLog'] = $exposureData['doLog'];
        }

        $this->exposureWriter->write($event);
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
            $message = 'Datadog-backed PHP feature flag evaluation is not fully enabled yet.';
        }

        $this->warningEmitter->warning($message);
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
