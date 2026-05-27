<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature {

use DDTrace\FeatureFlags\Client as FeatureFlagsClient;
use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\FeatureFlags\Internal\UnavailableEvaluator;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\LogLevel;
use DDTrace\Log\NullLogger;
use DDTrace\OpenFeature\DataDogProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\OpenFeatureAPI;
use PHPUnit\Framework\TestCase;

final class DataDogProviderTest extends TestCase
{
    public function testProviderMetadataNamesDatadogProvider(): void
    {
        $provider = new DataDogProvider();

        self::assertSame('Datadog', $provider->getMetadata()->getName());
    }

    public function testOpenFeatureClientResolvesTypedValuesThroughDatadogClient(): void
    {
        $evaluator = new OpenFeatureTestEvaluator();
        $evaluator
            ->setSuccess('bool.flag', true, EvaluationReason::TARGETING_MATCH, 'on')
            ->setSuccess('string.flag', 'blue')
            ->setSuccess('integer.flag', 42)
            ->setSuccess('float.flag', 3.5)
            ->setSuccess('object.flag', ['enabled' => true]);

        $client = $this->openFeatureClientFor($this->providerForEvaluator($evaluator));

        self::assertTrue($client->getBooleanValue('bool.flag', false));
        self::assertSame('blue', $client->getStringValue('string.flag', 'red'));
        self::assertSame(42, $client->getIntegerValue('integer.flag', 0));
        self::assertSame(3.5, $client->getFloatValue('float.flag', 0.0));
        self::assertSame(['enabled' => true], $client->getObjectValue('object.flag', []));

        $details = $client->getBooleanDetails('bool.flag', false);
        self::assertSame('bool.flag', $details->getFlagKey());
        self::assertTrue($details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
        self::assertSame('on', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testStaticReasonIsPreservedAsDatadogReason(): void
    {
        $evaluator = new OpenFeatureTestEvaluator();
        $evaluator->setSuccess('static.flag', 'value', EvaluationReason::STATIC_REASON);

        $client = $this->openFeatureClientFor($this->providerForEvaluator($evaluator));
        $details = $client->getStringDetails('static.flag', 'fallback');

        self::assertSame(EvaluationReason::STATIC_REASON, $details->getReason());
    }

    public function testEvaluationContextIsNormalizedForDatadogClient(): void
    {
        $evaluator = new OpenFeatureTestEvaluator();
        $evaluator->setSuccess('context.flag', 'on');

        $provider = $this->providerForEvaluator($evaluator);
        $provider->resolveStringValue('context.flag', 'off', new EvaluationContext(
            'user-123',
            new Attributes([
                'plan' => 'pro',
                'age' => 41,
                'rate' => 1.5,
                'beta' => true,
                'nested' => ['drop'],
                'null' => null,
                'date' => new \DateTimeImmutable(),
            ])
        ));

        $calls = $evaluator->getCalls();
        self::assertCount(1, $calls);
        self::assertSame('user-123', $calls[0]['targetingKey']);
        self::assertSame([
            'plan' => 'pro',
            'age' => 41,
            'rate' => 1.5,
            'beta' => true,
        ], $calls[0]['attributes']);
    }

    public function testUnavailableRuntimeReturnsDefaultDetailsAndOneWarning(): void
    {
        $logger = new OpenFeatureRecordingLogger();
        $client = $this->openFeatureClientFor(new DataDogProvider($logger));

        $value = $client->getBooleanValue('checkout.enabled', true);
        $details = $client->getStringDetails('checkout.copy', 'fallback');

        self::assertTrue($value);
        self::assertSame('fallback', $details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertSame(ErrorCode::PROVIDER_NOT_READY()->getValue(), $details->getError()->getResolutionErrorCode()->getValue());
        self::assertContains($details->getError()->getResolutionErrorMessage(), [
            NativeEvaluator::WARNING_MESSAGE,
            UnavailableEvaluator::WARNING_MESSAGE,
        ]);
        self::assertSame([$details->getError()->getResolutionErrorMessage()], $logger->warnings());
    }

    public function testProviderWarningIsEmittedOncePerProvider(): void
    {
        $logger = new OpenFeatureRecordingLogger();
        $evaluator = new OpenFeatureTestEvaluator();
        $evaluator
            ->setUnavailable('first.flag', true, 'temporary unavailable')
            ->setUnavailable('second.flag', true, 'temporary unavailable');

        $client = $this->openFeatureClientFor($this->providerForEvaluator($evaluator, $logger));

        $client->getBooleanValue('first.flag', false);
        $client->getBooleanValue('second.flag', false);

        self::assertSame(['temporary unavailable'], $logger->warnings());
    }

    public function testProviderErrorsMapToOpenFeatureDetails(): void
    {
        $evaluator = new OpenFeatureTestEvaluator();
        $evaluator->setFlagNotFound('missing.flag');

        $client = $this->openFeatureClientFor($this->providerForEvaluator($evaluator));
        $details = $client->getStringDetails('missing.flag', 'fallback');

        self::assertSame('fallback', $details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertSame(EvaluationErrorCode::FLAG_NOT_FOUND, $details->getError()->getResolutionErrorCode()->getValue());
        self::assertSame('Feature flag "missing.flag" was not found', $details->getError()->getResolutionErrorMessage());
    }

    public function testTypeMismatchReturnsDefaultWithOpenFeatureError(): void
    {
        $evaluator = new OpenFeatureTestEvaluator();
        $evaluator->setSuccess('integer.flag', 'not-an-int');

        $client = $this->openFeatureClientFor($this->providerForEvaluator($evaluator));
        $details = $client->getIntegerDetails('integer.flag', 7);

        self::assertSame(7, $details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertSame(EvaluationErrorCode::TYPE_MISMATCH, $details->getError()->getResolutionErrorCode()->getValue());
    }

    private function providerForEvaluator(Evaluator $evaluator, ?LoggerInterface $logger = null): DataDogProvider
    {
        $logger = $logger ?: new NullLogger(LogLevel::EMERGENCY);
        $provider = new DataDogProvider($logger);
        $client = $this->clientForEvaluator($evaluator, $logger);

        (function () use ($client): void {
            $this->client = $client;
        })->call($provider);

        return $provider;
    }

    private function clientForEvaluator(Evaluator $evaluator, LoggerInterface $logger): FeatureFlagsClient
    {
        $client = new FeatureFlagsClient($logger);
        (function () use ($evaluator): void {
            $this->evaluator = $evaluator;
        })->call($client);

        return $client;
    }

    private function openFeatureClientFor(DataDogProvider $provider)
    {
        $api = OpenFeatureAPI::getInstance();
        $api->setProvider($provider);

        return $api->getClient('datadog-test');
    }
}

final class OpenFeatureTestEvaluator implements Evaluator
{
    /** @var array<string, EvaluationDetails> */
    private array $details = [];

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    public function setSuccess(
        string $flagKey,
        mixed $value,
        string $reason = EvaluationReason::STATIC_REASON,
        ?string $variant = null
    ): self {
        $this->details[$flagKey] = new EvaluationDetails(
            $value,
            $this->typeForValue($value),
            $reason,
            $variant
        );

        return $this;
    }

    public function setUnavailable(string $flagKey, mixed $defaultValue, string $message): self
    {
        $this->details[$flagKey] = new EvaluationDetails(
            $defaultValue,
            $this->typeForValue($defaultValue),
            EvaluationReason::ERROR,
            null,
            EvaluationErrorCode::PROVIDER_NOT_READY,
            $message,
            [],
            [],
            ['ready' => false, 'productionRuntime' => false, 'reason' => 'test_unavailable']
        );

        return $this;
    }

    public function setFlagNotFound(string $flagKey): self
    {
        $this->details[$flagKey] = new EvaluationDetails(
            'fallback',
            EvaluationType::STRING,
            EvaluationReason::ERROR,
            null,
            EvaluationErrorCode::FLAG_NOT_FOUND,
            'Feature flag "' . $flagKey . '" was not found'
        );

        return $this;
    }

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = [])
    {
        $this->calls[] = [
            'flagKey' => $flagKey,
            'expectedType' => $expectedType,
            'targetingKey' => $targetingKey,
            'attributes' => $attributes,
        ];

        if (array_key_exists($flagKey, $this->details)) {
            $details = $this->details[$flagKey];
            if ($this->matchesExpectedType($details->getValue(), $expectedType)) {
                return $details;
            }

            return new EvaluationDetails(
                $defaultValue,
                $expectedType,
                EvaluationReason::ERROR,
                null,
                EvaluationErrorCode::TYPE_MISMATCH,
                'Expected ' . $expectedType . ' flag value'
            );
        }

        return new EvaluationDetails(
            $defaultValue,
            $expectedType,
            EvaluationReason::ERROR,
            null,
            EvaluationErrorCode::PROVIDER_NOT_READY,
            UnavailableEvaluator::WARNING_MESSAGE,
            [],
            [],
            ['ready' => false, 'productionRuntime' => false, 'reason' => 'test_missing_result']
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    private function typeForValue(mixed $value): string
    {
        if (is_bool($value)) {
            return EvaluationType::BOOLEAN;
        }
        if (is_int($value)) {
            return EvaluationType::INTEGER;
        }
        if (is_float($value)) {
            return EvaluationType::FLOAT;
        }
        if (is_array($value)) {
            return EvaluationType::OBJECT;
        }
        return EvaluationType::STRING;
    }

    private function matchesExpectedType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            EvaluationType::BOOLEAN => is_bool($value),
            EvaluationType::STRING => is_string($value),
            EvaluationType::INTEGER => is_int($value),
            EvaluationType::FLOAT => is_float($value) || is_int($value),
            EvaluationType::OBJECT => is_array($value),
            default => false,
        };
    }
}

final class OpenFeatureRecordingLogger implements LoggerInterface
{
    /** @var string[] */
    private array $warnings = [];

    public function debug($message, array $context = [])
    {
    }

    public function warning($message, array $context = [])
    {
        $this->warnings[] = $message;
    }

    public function error($message, array $context = [])
    {
    }

    public function isLevelActive($level)
    {
        return true;
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
}
