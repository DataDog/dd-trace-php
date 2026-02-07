<?php

namespace DDTrace\OpenFeature;

use DDTrace\FeatureFlags\Provider;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\Metadata;
use OpenFeature\interfaces\provider\ResolutionDetails as ResolutionDetailsInterface;

/**
 * Datadog OpenFeature Provider.
 *
 * Implements the OpenFeature Provider interface for Datadog's
 * Feature Flags and Experimentation (FFE) product.
 *
 * Usage:
 *   use OpenFeature\API;
 *   use DDTrace\OpenFeature\DataDogProvider;
 *
 *   API::setProvider(new DataDogProvider());
 *   $client = API::getClient();
 *   $value = $client->getBooleanValue('my-flag', false, $context);
 *
 * Requires: composer require open-feature/sdk
 */
class DataDogProvider extends AbstractProvider
{
    /** @var Provider */
    private $ffeProvider;

    public function __construct()
    {
        $this->ffeProvider = Provider::getInstance();
        $this->ffeProvider->start();
    }

    public function getMetadata(): Metadata
    {
        return new class implements Metadata {
            public function getName(): string
            {
                return 'Datadog';
            }
        };
    }

    public function resolveBooleanValue(
        string $flagKey,
        bool $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, 'BOOLEAN', $defaultValue, $context);
    }

    public function resolveStringValue(
        string $flagKey,
        string $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, 'STRING', $defaultValue, $context);
    }

    public function resolveIntegerValue(
        string $flagKey,
        int $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, 'INTEGER', $defaultValue, $context);
    }

    public function resolveFloatValue(
        string $flagKey,
        float $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, 'NUMERIC', $defaultValue, $context);
    }

    public function resolveObjectValue(
        string $flagKey,
        array $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        return $this->resolve($flagKey, 'JSON', $defaultValue, $context);
    }

    /**
     * @param mixed $defaultValue
     */
    private function resolve(
        string $flagKey,
        string $variationType,
        $defaultValue,
        ?EvaluationContext $context = null
    ): ResolutionDetailsInterface {
        $targetingKey = '';
        $attributes = [];

        if ($context !== null) {
            $targetingKey = $context->getTargetingKey() ?? '';
            $attrs = $context->getAttributes();
            if ($attrs !== null) {
                $attributes = $attrs->toArray();
            }
        }

        $result = $this->ffeProvider->evaluate(
            $flagKey,
            $variationType,
            $defaultValue,
            $targetingKey,
            $attributes
        );

        $builder = new ResolutionDetailsBuilder();
        $builder->withValue($result['value']);

        if (isset($result['reason'])) {
            $builder->withReason($result['reason']);
        }

        return $builder->build();
    }
}
