<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature {

use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\Internal\Evaluator;
use DDTrace\FeatureFlags\Internal\ResultMapper;
use DDTrace\Log\Logger;
use DDTrace\Log\LoggerInterface;
use DDTrace\Log\LogLevel;
use DDTrace\Log\NullLogger;
use DDTrace\OpenFeature\DataDogProvider;
use DDTrace\OpenFeature\ExposureEvaluation;
use DDTrace\OpenFeature\ExposureHook;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\implementation\hooks\AbstractHook;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ResolutionDetails;
use OpenFeature\OpenFeatureAPI;
use PHPUnit\Framework\TestCase;

final class ExposureHookTest extends TestCase
{
    private ExposureTestEvaluator $evaluator;
    private ExposureTestCache $cache;
    /** @var list<ExposureEvaluation> */
    private array $evaluations = [];

    protected function setUp(): void
    {
        $this->evaluator = new ExposureTestEvaluator();
        $this->cache = new ExposureTestCache();
        $api = OpenFeatureAPI::getInstance();
        $api->clearHooks();
        $api->setProvider(DataDogProvider::createWithDependencies(
            $this->evaluator,
            new NullLogger(LogLevel::EMERGENCY),
            null,
            [$this->cache, 'record']
        ));
        $api->addHooks(new ExposureHook(function (ExposureEvaluation $evaluation): void {
            $this->evaluations[] = $evaluation;
        }));
    }

    protected function tearDown(): void
    {
        OpenFeatureAPI::getInstance()->clearHooks();
        Logger::reset();
    }

    public function testFirstEvaluationIsANewExposureAndARepeatIsACacheHit(): void
    {
        $this->evaluator->exposure('flag', 'on', 7);

        self::assertSame('on', $this->evaluate('flag'));
        self::assertSame('on', $this->evaluate('flag'));

        [$first, $repeat] = $this->evaluations;
        self::assertTrue($first->isExposure());
        self::assertFalse($first->isCacheHit());
        self::assertTrue($first->shouldSend());
        self::assertTrue($repeat->isExposure());
        self::assertTrue($repeat->isCacheHit());
        self::assertFalse($repeat->shouldSend());
        self::assertSame('flag', $first->getFlagKey());
        self::assertSame('on', $first->getVariant());
        self::assertSame('alloc', $first->getAllocationKey());
        self::assertSame(7, $first->getSplitSerialId());
        self::assertSame('user-1', $first->getTargetingKey());
        self::assertSame(
            [['flag', 'user-1', ['plan' => 'pro'], 'alloc', 'on', 7], ['flag', 'user-1', ['plan' => 'pro'], 'alloc', 'on', 7]],
            $this->cache->calls
        );
    }

    public function testVariantAndSerialIdChangesAreNewExposures(): void
    {
        $this->evaluator->exposure('flag', 'on', 1);
        $this->evaluate('flag');
        $this->evaluator->exposure('flag', 'off', 1);
        $this->evaluate('flag');
        $this->evaluator->exposure('flag', 'off', 2);
        $this->evaluate('flag');
        $this->evaluator->exposure('flag', 'off', null);
        $this->evaluate('flag');

        self::assertSame([true, true, true, true], array_map(fn ($e) => $e->shouldSend(), $this->evaluations));
    }

    public function testFailedEvaluationRecordsNothing(): void
    {
        $this->evaluator->failure('flag');

        self::assertSame('default', $this->evaluate('flag'));

        self::assertSame([], $this->cache->calls);
        self::assertCount(1, $this->evaluations);
        self::assertFalse($this->evaluations[0]->isExposure());
        self::assertFalse($this->evaluations[0]->shouldSend());
        self::assertNull($this->evaluations[0]->getVariant());
    }

    public function testErrorDetailsAreNotExposuresEvenWithDoLog(): void
    {
        $this->evaluator->details['flag'] = new \DDTrace\FeatureFlags\EvaluationDetails(
            'default',
            EvaluationType::STRING,
            \DDTrace\FeatureFlags\EvaluationReason::ERROR,
            'on',
            \DDTrace\FeatureFlags\EvaluationErrorCode::GENERAL,
            null,
            ['__dd_do_log' => true],
            ['allocationKey' => 'alloc', 'doLog' => true]
        );

        $this->evaluate('flag');

        self::assertSame([], $this->cache->calls);
        self::assertFalse($this->evaluations[0]->isExposure());
    }

    public function testEvaluationWithoutAllocationIsNotAnExposure(): void
    {
        $this->evaluator->exposure('flag', 'on', 1);
        $this->evaluator->withoutAllocation('flag');

        $this->evaluate('flag');

        self::assertSame([], $this->cache->calls);
        self::assertFalse($this->evaluations[0]->isExposure());
    }

    public function testLaterHooksCannotChangeTheReportedContext(): void
    {
        $this->evaluator->exposure('flag', 'on', 1);
        $client = OpenFeatureAPI::getInstance()->getClient();
        $client->addHooks(new class extends AbstractHook {
            public function supportsFlagValueType(string $flagValueType): bool
            {
                return true;
            }

            public function before(HookContext $context, HookHints $hints): ?\OpenFeature\interfaces\flags\EvaluationContext
            {
                return null;
            }

            public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
            {
                $context->getEvaluationContext()->setTargetingKey('changed-user');
            }

            public function error(HookContext $context, \Throwable $error, HookHints $hints): void
            {
            }

            public function finally(HookContext $context, HookHints $hints): void
            {
                $context->getEvaluationContext()->getAttributes()->add('plan', 'changed');
            }
        });

        $client->getStringValue('flag', 'default', new EvaluationContext('user-1', new Attributes(['plan' => 'pro'])));

        self::assertSame('user-1', $this->evaluations[0]->getTargetingKey());
        self::assertSame('user-1', $this->cache->calls[0][1]);
        self::assertSame(['plan' => 'pro'], $this->evaluations[0]->getEvaluationContext()->getAttributes()->toArray());
    }

    public function testAllocationThatDoesNotLogExposuresRecordsNothing(): void
    {
        $this->evaluator->exposure('flag', 'on', 1, false);

        $this->evaluate('flag');

        self::assertSame([], $this->cache->calls);
        self::assertFalse($this->evaluations[0]->isExposure());
        self::assertSame('on', $this->evaluations[0]->getVariant());
    }

    public function testLaterAfterHookFailureRecordsNothing(): void
    {
        $this->evaluator->exposure('flag', 'on', 1);
        OpenFeatureAPI::getInstance()->addHooks(new class extends AbstractHook {
            public function supportsFlagValueType(string $flagValueType): bool
            {
                return true;
            }

            public function before(HookContext $context, HookHints $hints): ?\OpenFeature\interfaces\flags\EvaluationContext
            {
                return null;
            }

            public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
            {
                throw new \RuntimeException('rejected');
            }

            public function error(HookContext $context, \Throwable $error, HookHints $hints): void
            {
            }

            public function finally(HookContext $context, HookHints $hints): void
            {
            }
        });

        self::assertSame('default', $this->evaluate('flag'));

        self::assertSame([], $this->cache->calls);
        self::assertFalse($this->evaluations[0]->isExposure());
    }

    public function testRecorderUnavailableIsAnExposureWithoutAVerdict(): void
    {
        $this->evaluator->exposure('flag', 'on', 1);
        $this->cache->verdict = null;

        $this->evaluate('flag');

        self::assertTrue($this->evaluations[0]->isExposure());
        self::assertFalse($this->evaluations[0]->isCacheHit());
        self::assertFalse($this->evaluations[0]->shouldSend());
    }

    public function testCallbackFailureDoesNotAffectTheEvaluation(): void
    {
        $logger = new ExposureTestLogger();
        Logger::set($logger);
        $this->evaluator->exposure('flag', 'on', 1);
        OpenFeatureAPI::getInstance()->addHooks(new ExposureHook(function (): void {
            throw new \Error('callback failed');
        }));

        self::assertSame('on', $this->evaluate('flag'));
        self::assertSame('on', $this->evaluate('flag'));

        self::assertCount(2, $this->evaluations);
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('callback failed', $logger->warnings[0]);
        self::assertCount(1, $logger->debugs);
    }

    public function testLoggerFailureIsContained(): void
    {
        Logger::set(new class implements LoggerInterface {
            public function debug($message, array $context = []) { throw new \RuntimeException('debug'); }
            public function warning($message, array $context = []) { throw new \RuntimeException('warning'); }
            public function error($message, array $context = []) { throw new \RuntimeException('error'); }
            public function isLevelActive($level) { throw new \RuntimeException('level'); }
        });
        $hook = new ExposureHook(function (): void {
            throw new \RuntimeException('callback failed');
        });
        $context = (new \OpenFeature\implementation\hooks\HookContextBuilder())
            ->withFlagKey('flag')
            ->withType('STRING')
            ->withDefaultValue('d')
            ->withEvaluationContext(new EvaluationContext('u'))
            ->build();

        $hook->finally($context, new \OpenFeature\implementation\hooks\HookHints());
        $hook->finally($context, new \OpenFeature\implementation\hooks\HookHints());

        $this->addToAssertionCount(1);
    }

    public function testFeaturesAreTheHookFeaturesAndTheContextIsACopy(): void
    {
        $this->evaluator->exposure('flag', 'on', 1, true, [
            ['key' => 'holdout.key', 'value' => 'h1', 'destinations' => ['HOOK']],
            ['key' => 'holdout.assignment_group', 'value' => 'status_quo', 'destinations' => ['HOOK', 'EXPOSURE']],
            ['key' => 'weight', 'value' => 0.5, 'destinations' => ['EVALUATION']],
            ['key' => 'included', 'value' => false, 'destinations' => ['HOOK']],
        ]);
        $context = new EvaluationContext('user-1', new Attributes(['plan' => 'pro']));

        OpenFeatureAPI::getInstance()->getClient()->getStringValue('flag', 'default', $context);

        $evaluation = $this->evaluations[0];
        self::assertSame(
            ['holdout.key' => 'h1', 'holdout.assignment_group' => 'status_quo', 'included' => false],
            $evaluation->getFeatures()
        );
        self::assertNotSame($context, $evaluation->getEvaluationContext());
        self::assertSame('user-1', $evaluation->getEvaluationContext()->getTargetingKey());
        self::assertSame(['plan' => 'pro'], $evaluation->getEvaluationContext()->getAttributes()->toArray());
        self::assertSame([['holdout.assignment_group' => 'status_quo']], $this->cache->features);
    }

    public function testSplitWithoutFeaturesHasNoFeatures(): void
    {
        $this->evaluator->exposure('flag', 'on', 1);

        $this->evaluate('flag');

        self::assertSame([], $this->evaluations[0]->getFeatures());
    }

    public function testMetadataCarriesHookFeaturesAndTheFinalVerdict(): void
    {
        if (!method_exists(ResolutionDetails::class, 'getMetadata')) {
            self::markTestSkipped('open-feature/sdk before 2.4.0 has no flag metadata');
        }
        $this->evaluator->exposure('flag', 'on', 3, true, [
            ['key' => 'holdout.key', 'value' => 'h1', 'destinations' => ['HOOK']],
        ]);
        $capture = new class extends AbstractHook {
            public ?ResolutionDetails $details = null;

            public function supportsFlagValueType(string $flagValueType): bool
            {
                return true;
            }

            public function before(HookContext $context, HookHints $hints): ?\OpenFeature\interfaces\flags\EvaluationContext
            {
                return null;
            }

            public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
            {
                $this->details = $details;
            }

            public function error(HookContext $context, \Throwable $error, HookHints $hints): void
            {
            }

            public function finally(HookContext $context, HookHints $hints): void
            {
            }
        };
        OpenFeatureAPI::getInstance()->addHooks($capture);

        $details = OpenFeatureAPI::getInstance()->getClient()->getStringDetails(
            'flag',
            'default',
            new EvaluationContext('user-1')
        );

        self::assertSame(
            ['__dd_feature.holdout.key' => 'h1', '__dd_do_log' => true, '__dd_split_serial_id' => 3],
            $details->getMetadata()
        );
        self::assertSame(
            ['__dd_exposure_cache_hit' => false, '__dd_feature.holdout.key' => 'h1', '__dd_do_log' => true, '__dd_split_serial_id' => 3],
            $capture->details->getMetadata()
        );
    }

    public function testOtherProvidersGiveNonExposures(): void
    {
        OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider());

        $this->evaluate('flag');

        self::assertCount(1, $this->evaluations);
        self::assertFalse($this->evaluations[0]->isExposure());
        self::assertSame('flag', $this->evaluations[0]->getFlagKey());
        self::assertSame('user-1', $this->evaluations[0]->getTargetingKey());
    }

    public function testDirectProviderCallsRecordNoExposure(): void
    {
        $this->evaluator->exposure('flag', 'on', 1);

        OpenFeatureAPI::getInstance()->getProvider()->resolveStringValue('flag', 'default', new EvaluationContext('user-1'));

        self::assertSame([], $this->cache->calls);
    }

    private function evaluate(string $flagKey): string
    {
        return OpenFeatureAPI::getInstance()->getClient()->getStringValue(
            $flagKey,
            'default',
            new EvaluationContext('user-1', new Attributes(['plan' => 'pro']))
        );
    }
}

final class ExposureTestEvaluator implements Evaluator
{
    /** @var array<string, array<string, mixed>> */
    private array $results = [];
    /** @var array<string, \DDTrace\FeatureFlags\EvaluationDetails> */
    public array $details = [];

    public function exposure(string $flagKey, string $variant, ?int $serialId, bool $doLog = true, array $features = []): void
    {
        $this->results[$flagKey] = [
            'value_json' => json_encode($variant),
            'variant' => $variant,
            'allocation_key' => 'alloc',
            'reason' => ResultMapper::BRIDGE_REASON_SPLIT,
            'error_code' => ResultMapper::BRIDGE_ERROR_NONE,
            'do_log' => $doLog,
            'serial_id' => $serialId,
            'features' => $features,
        ];
    }

    public function withoutAllocation(string $flagKey): void
    {
        unset($this->results[$flagKey]['allocation_key']);
    }

    public function failure(string $flagKey): void
    {
        $this->results[$flagKey] = [
            'value_json' => 'null',
            'reason' => ResultMapper::BRIDGE_REASON_ERROR,
            'error_code' => ResultMapper::BRIDGE_ERROR_TYPE_MISMATCH,
            'do_log' => false,
        ];
    }

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = [], $targetingKeyInvalid = false)
    {
        return $this->details[$flagKey]
            ?? (new ResultMapper())->map($this->results[$flagKey] ?? null, $expectedType, $defaultValue);
    }
}

final class ExposureTestCache
{
    public array $calls = [];
    public array $features = [];
    /** @var array<string, array> */
    private array $entries = [];
    public bool|null|string $verdict = 'cache';

    public function record(string $flagKey, ?string $targetingKey, array $attributes, string $allocationKey, string $variant, ?int $serialId, array $features = []): ?bool
    {
        $this->calls[] = [$flagKey, $targetingKey, $attributes, $allocationKey, $variant, $serialId];
        $this->features[] = $features;
        if ($this->verdict !== 'cache') {
            return $this->verdict;
        }
        $key = $flagKey . "\0" . $targetingKey;
        $value = [$allocationKey, $variant, $serialId];
        $new = ($this->entries[$key] ?? null) !== $value;
        $this->entries[$key] = $value;

        return $new;
    }
}

final class ExposureTestLogger implements LoggerInterface
{
    public array $warnings = [];
    public array $debugs = [];

    public function debug($message, array $context = [])
    {
        $this->debugs[] = strtr($message, ['{error}' => $context['error'] ?? '']);
    }

    public function warning($message, array $context = [])
    {
        $this->warnings[] = strtr($message, ['{error}' => $context['error'] ?? '']);
    }

    public function error($message, array $context = [])
    {
    }

    public function isLevelActive($level)
    {
        return true;
    }
}
}
