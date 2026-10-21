<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use DDTrace\FeatureFlags\Client;
use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\FeatureFlags\SpanEnrichmentAccumulator;
use DDTrace\FeatureFlags\SpanEnrichmentRegistry;
use DDTrace\OpenFeature\DataDogProvider;
use DDTrace\Util\ObjectKVStore;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\implementation\flags\EvaluationOptions;
use OpenFeature\implementation\flags\MutableAttributes;
use OpenFeature\interfaces\flags\EvaluationContext as EvaluationContextInterface;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ResolutionDetails;
use OpenFeature\OpenFeatureAPI;
use PHPUnit\Framework\TestCase;

/**
 * Real OpenFeature client -> provider -> native evaluator -> native queues.
 * Queue observations do not flush or replace any telemetry recorder.
 */
final class EvaluationTelemetryOptOutTest extends TestCase
{
    private const KEY = 'dd_evaluation_telemetry_opt_out';

    private $api;
    private array $initialCounts;
    private array $previousIni = [];

    protected function setUp(): void
    {
        if (!function_exists('DDTrace\\Testing\\ffe_load_config')) {
            self::markTestSkipped('The native ddtrace feature flag evaluator is required.');
        }
        foreach ([
            'datadog.metrics_otel_enabled',
            'datadog.experimental_flagging_provider_span_enrichment_enabled',
        ] as $name) {
            $this->previousIni[$name] = ini_set($name, '1');
        }
        self::assertTrue(\dd_trace_env_config('DD_METRICS_OTEL_ENABLED'));
        self::assertTrue(SpanEnrichmentRegistry::gateEnabled());
        $providerPath = getenv('DD_AUTOLOAD_NO_COMPILE') === 'true'
            ? '/src/DDTrace/OpenFeature/DataDogProvider.php'
            : '/src/bridge/_generated_openfeature.php';
        foreach ([
            DataDogProvider::class => $providerPath,
            NativeEvaluator::class => '/src/api/FeatureFlags/Internal/NativeEvaluator.php',
            \DDTrace\FeatureFlags\Internal\EvaluationTelemetry::class => '/src/api/FeatureFlags/Internal/EvaluationTelemetry.php',
        ] as $class => $path) {
            self::assertSame(realpath(dirname(__DIR__, 2) . $path), realpath((new \ReflectionClass($class))->getFileName()));
        }
        $this->api = OpenFeatureAPI::getInstance();
        $this->api->clearHooks();
        $this->api->setEvaluationContext(new EvaluationContext());
        $root = \DDTrace\root_span();
        self::assertNotNull($root);
        ObjectKVStore::put($root, SpanEnrichmentRegistry::ACCUMULATOR_KEY, new SpanEnrichmentAccumulator());
        foreach (['ffe_flags_enc', 'ffe_subjects_enc', 'ffe_runtime_defaults'] as $key) {
            unset($root->meta[$key]);
        }
        $root->meta['customer.tag'] = 'retained';
        self::assertTrue(\DDTrace\Testing\ffe_load_config($this->fixture()));
        $this->initialCounts = $this->counts();
    }

    protected function tearDown(): void
    {
        if ($this->api !== null) {
            $this->api->clearHooks();
            $this->api->setEvaluationContext(new EvaluationContext());
        }
        foreach ($this->previousIni as $name => $value) {
            ini_set($name, $value);
        }
    }

    /** @dataProvider decisions */
    public function testStrictDecisionPreservesNativeAssignment(bool $enabled, array $attributes, bool $suppressed): void
    {
        $client = $this->client($enabled);
        $details = $client->getStringDetails('string.flag', 'fallback', new EvaluationContext('subject', new Attributes($attributes)));

        self::assertSame('blue', $details->getValue());
        self::assertSame('treatment', $details->getVariant());
        self::assertSame('STATIC', $details->getReason());
        self::assertNull($details->getError());
        $this->assertDelta($suppressed ? 0 : 1, 1);
        $this->assertSpanIds($suppressed ? [] : [101]);
        self::assertSame('retained', \DDTrace\root_span()->meta['customer.tag']);
    }

    public static function decisions(): array
    {
        return [
            'enabled true' => [true, [self::KEY => true], true],
            'disabled true' => [false, [self::KEY => true], false],
            'missing' => [true, [], false],
            'false' => [true, [self::KEY => false], false],
            'null' => [true, [self::KEY => null], false],
            'string true' => [true, [self::KEY => 'true'], false],
            'string false' => [true, [self::KEY => 'false'], false],
            'empty string' => [true, [self::KEY => ''], false],
            'integer one' => [true, [self::KEY => 1], false],
            'integer zero' => [true, [self::KEY => 0], false],
            'float one' => [true, [self::KEY => 1.0], false],
            'array' => [true, [self::KEY => [true]], false],
            'old key' => [true, ['dd_telemetry_opt_out' => true], false],
        ];
    }

    /** @dataProvider decisions */
    public function testOptOutAttributeUsesNormalTargetingAndExposureAdmission(bool $enabled, array $attributes, bool $suppressed): void
    {
        $client = $this->client($enabled);
        $context = new EvaluationContext('same-subject', new Attributes($attributes));
        $details = $client->getStringDetails('attribute-targeting.flag', 'runtime-default', $context);
        $value = $attributes[self::KEY] ?? null;
        // ONE_OF uses the evaluator's normal string matching for boolean and string values.
        $matched = $value === true || $value === 'true';

        self::assertSame($matched ? 'attribute-matched' : 'attribute-not-matched', $details->getValue());
        self::assertSame($matched ? 'matched' : 'fallback', $details->getVariant());
        self::assertSame($matched ? 'TARGETING_MATCH' : 'STATIC', $details->getReason());
        self::assertNull($details->getError());
        self::assertSame($attributes, $context->getAttributes()->toArray());
        $this->assertDelta($suppressed ? 0 : 1, 1);
        $this->assertSpanIds($suppressed ? [] : [$matched ? 301 : 302]);
        self::assertSame('retained', \DDTrace\root_span()->meta['customer.tag']);
    }

    public function testDefaultConstructorAndNativeClientKeepExistingBehavior(): void
    {
        $this->api->setProvider(new DataDogProvider());
        self::assertSame('blue', $this->api->getClient()->getStringValue('string.flag', 'fallback', $this->context(true)));
        self::assertSame('blue', (new Client())->getStringValue('string.flag', 'fallback', [
            'targetingKey' => 'native-subject', 'attributes' => [self::KEY => true],
        ]));
        $this->assertDelta(2, 2);
        $this->assertSpanIds([101]);
    }

    public function testAllValueTypesAndExistingQueuesArePreserved(): void
    {
        $client = $this->client(true);
        self::assertSame('blue', $client->getStringValue('string.flag', '', $this->context(false)));
        $meta = \DDTrace\root_span()->meta;
        $version = \DDTrace\ffe_config_version();
        $optedOut = $this->context(true);
        self::assertTrue($client->getBooleanValue('boolean.flag', false, $optedOut));
        self::assertSame(42, $client->getIntegerValue('integer.flag', 0, $optedOut));
        self::assertSame(3.5, $client->getFloatValue('float.flag', 0.0, $optedOut));
        self::assertSame(['enabled' => true], $client->getObjectValue('object.flag', [], $optedOut));
        self::assertSame('blue', $client->getStringValue('string.flag', '', $optedOut));
        self::assertSame($meta, \DDTrace\root_span()->meta);
        self::assertSame($version, \DDTrace\ffe_config_version());
        $this->assertDelta(1, 6);
    }

    public function testMergedContextAndBeforeHooksDetermineDecision(): void
    {
        $client = $this->client(true);
        $this->api->setEvaluationContext($this->context(true));
        $client->setEvaluationContext($this->context(false));
        $client->getStringValue('string.flag', '', $this->context(true));
        $client->getStringValue('string.flag', '');

        $hook = new EvaluationTelemetryTestHook();
        $hook->beforeCallback = fn () => $this->context(true);
        $client->addHooks($hook);
        $client->getStringValue('integer.flag', '', $this->context(false));
        self::assertSame(['before', 'after', 'finally'], $hook->stages);
        $this->assertDelta(1, 2);
        $this->assertSpanIds([101]);
    }

    public function testBeforeHookCanRestoreTelemetryAndCustomHooksRun(): void
    {
        $client = $this->client(true);
        $hook = new EvaluationTelemetryTestHook();
        $hook->beforeCallback = fn () => $this->context(false);
        $client->addHooks($hook);
        self::assertSame('blue', $client->getStringValue('string.flag', '', $this->context(true)));
        self::assertSame(['before', 'after', 'finally'], $hook->stages);
        $this->assertDelta(1, 1);
    }

    public function testRandomizedAssignmentAndExposureStayUnchanged(): void
    {
        $client = $this->client(true);
        $allowed = new EvaluationContext('same-subject', new Attributes([self::KEY => false]));
        $suppressed = new EvaluationContext('same-subject', new Attributes([self::KEY => true]));
        $first = $client->getStringDetails('random.flag', 'fallback', $allowed);
        $second = $client->getStringDetails('random.flag', 'fallback', $suppressed);
        self::assertNull($first->getError());
        self::assertSame('SPLIT', $first->getReason());
        self::assertSame($first->getValue(), $second->getValue());
        self::assertSame($first->getVariant(), $second->getVariant());
        self::assertSame($first->getReason(), $second->getReason());
        $this->assertDelta(1, 2);
    }

    public function testContextMutationIsReadForEachEvaluation(): void
    {
        $client = $this->client(true);
        $attributes = new MutableAttributes([self::KEY => true]);
        $context = new EvaluationContext('same-subject', $attributes);
        self::assertSame('blue', $client->getStringValue('string.flag', '', $context));
        $attributes->add(self::KEY, 'true');
        self::assertSame('blue', $client->getStringValue('string.flag', '', $context));
        $attributes->add(self::KEY, true);
        self::assertSame('blue', $client->getStringValue('string.flag', '', $context));
        $this->assertDelta(1, 3);
    }

    public function testProvidersKeepIndependentActivation(): void
    {
        $enabled = new DataDogProvider(null, true);
        $disabled = new DataDogProvider();
        $this->api->setProvider($enabled);
        $client = $this->api->getClient();
        $hook = new EvaluationTelemetryTestHook();
        $hook->beforeCallback = function () use ($client, $disabled, $enabled) {
            $this->api->setProvider($disabled);
            self::assertSame(42, $client->getIntegerValue('integer.flag', 0, $this->context(true)));
            $this->api->setProvider($enabled);
            return null;
        };
        self::assertSame('blue', $client->getStringValue('string.flag', '', $this->context(true), new EvaluationOptions([$hook])));
        $this->assertDelta(1, 2);
        $this->assertSpanIds([103]);
    }

    /** @dataProvider errors */
    public function testErrorsPreserveDefaultsAndSuppressNewTelemetry(string $flag, string $error): void
    {
        $client = $this->client(true);
        $details = $client->getStringDetails($flag, 'fallback', $this->context(true));
        self::assertSame('fallback', $details->getValue());
        self::assertSame('ERROR', $details->getReason());
        self::assertSame($error, $details->getError()->getResolutionErrorCode()->getValue());
        $this->assertDelta(0, 0);
        self::assertArrayNotHasKey('ffe_runtime_defaults', \DDTrace\root_span()->meta);
        $this->assertSpanIds([]);
    }

    public static function errors(): array
    {
        return [['missing.flag', 'FLAG_NOT_FOUND'], ['integer.flag', 'TYPE_MISMATCH']];
    }

    /** @dataProvider hookFailures */
    public function testHookFailuresDoNotLeakSuppression(string $stage, int $exposures): void
    {
        $client = $this->client(true);
        $hook = new EvaluationTelemetryTestHook();
        $hook->{$stage . 'Callback'} = static function () {
            throw new \RuntimeException('customer hook failure');
        };
        $options = new EvaluationOptions([$hook]);
        $details = $client->getStringDetails('string.flag', 'fallback', $this->context(true), $options);
        if ($stage !== 'finally') {
            self::assertSame('fallback', $details->getValue());
        }
        self::assertContains('finally', $hook->stages);
        self::assertSame('blue', $client->getStringValue('string.flag', '', $this->context(false)));
        $this->assertDelta(1, $exposures + 1);
    }

    public static function hookFailures(): array
    {
        return [['before', 0], ['after', 1], ['finally', 1]];
    }

    public function testNestedEvaluationsKeepSeparateDecisions(): void
    {
        $client = $this->client(true);
        $hook = new EvaluationTelemetryTestHook();
        $hook->beforeCallback = function () use ($client) {
            self::assertSame(42, $client->getIntegerValue('integer.flag', 0, $this->context(false)));
            return null;
        };
        self::assertSame('blue', $client->getStringValue('string.flag', '', $this->context(true), new EvaluationOptions([$hook])));
        $this->assertDelta(1, 2);
        $this->assertSpanIds([103]);
    }

    public function testConcurrentFibersKeepSeparateDecisions(): void
    {
        if (!class_exists('Fiber')) {
            self::markTestSkipped('Fibers require PHP 8.1.');
        }
        $client = $this->client(true);
        $hook = new EvaluationTelemetryTestHook();
        $hook->beforeCallback = static function () {
            \Fiber::suspend();
            return null;
        };
        $options = new EvaluationOptions([$hook]);
        $suppressed = new \Fiber(fn () => $client->getStringValue('string.flag', '', $this->context(true), $options));
        $allowed = new \Fiber(fn () => $client->getIntegerValue('integer.flag', 0, $this->context(false), $options));
        $suppressed->start();
        $allowed->start();
        $allowed->resume();
        $suppressed->resume();
        self::assertSame('blue', $suppressed->getReturn());
        self::assertSame(42, $allowed->getReturn());
        $this->assertDelta(1, 2);
        $this->assertSpanIds([103]);
    }

    private function client(bool $enabled)
    {
        $this->api->setProvider(new DataDogProvider(null, $enabled));
        return $this->api->getClient('telemetry-opt-out');
    }

    private function context(bool $optOut): EvaluationContext
    {
        return new EvaluationContext($optOut ? 'opted-out' : 'allowed', new Attributes([self::KEY => $optOut]));
    }

    private function counts(): array
    {
        $counts = \dd_trace_internal_fn('ffe_buffer_counts');
        self::assertIsArray($counts, 'Build this checkout to observe the actual native queues.');
        return $counts;
    }

    private function assertDelta(int $metrics, int $exposures): void
    {
        $counts = $this->counts();
        self::assertSame(['evaluation_metrics' => $metrics, 'exposures' => $exposures], [
            'evaluation_metrics' => $counts['evaluation_metrics'] - $this->initialCounts['evaluation_metrics'],
            'exposures' => $counts['exposures'] - $this->initialCounts['exposures'],
        ], 'native admission counts');
    }

    private function assertSpanIds(array $ids): void
    {
        $meta = \DDTrace\root_span()->meta;
        $actual = isset($meta['ffe_flags_enc']) ? (new SpanEnrichmentAccumulator())->decodeDeltaVarint($meta['ffe_flags_enc']) : [];
        self::assertSame($ids, $actual);
    }

    private function fixture(): string
    {
        $flags = [];
        $values = ['string' => 'blue', 'boolean' => true, 'integer' => 42, 'float' => 3.5, 'object' => ['enabled' => true]];
        $types = ['string' => 'STRING', 'boolean' => 'BOOLEAN', 'integer' => 'INTEGER', 'float' => 'NUMERIC', 'object' => 'JSON'];
        $id = 101;
        foreach ($values as $type => $value) {
            $key = $type . '.flag';
            $flags[$key] = [
                'key' => $key, 'enabled' => true, 'variationType' => $types[$type],
                'variations' => ['treatment' => ['key' => 'treatment', 'value' => $value]],
                'allocations' => [[
                    'key' => 'allocation-' . $type, 'rules' => [], 'doLog' => true,
                    'splits' => [['variationKey' => 'treatment', 'serialId' => $id++, 'shards' => []]],
                ]],
            ];
        }
        $flags['random.flag'] = [
            'key' => 'random.flag', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => ['a' => ['key' => 'a', 'value' => 'A'], 'b' => ['key' => 'b', 'value' => 'B']],
            'allocations' => [[
                'key' => 'random-allocation', 'rules' => [], 'doLog' => true,
                'splits' => [
                    ['variationKey' => 'a', 'serialId' => 201, 'shards' => [[
                        'salt' => 'opt-out-test', 'totalShards' => 10000, 'ranges' => [['start' => 0, 'end' => 5000]],
                    ]]],
                    ['variationKey' => 'b', 'serialId' => 202, 'shards' => [[
                        'salt' => 'opt-out-test', 'totalShards' => 10000, 'ranges' => [['start' => 5000, 'end' => 10000]],
                    ]]],
                ],
            ]],
        ];
        $flags['attribute-targeting.flag'] = [
            'key' => 'attribute-targeting.flag', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => [
                'matched' => ['key' => 'matched', 'value' => 'attribute-matched'],
                'fallback' => ['key' => 'fallback', 'value' => 'attribute-not-matched'],
            ],
            'allocations' => [
                [
                    'key' => 'attribute-match', 'doLog' => true,
                    'rules' => [['conditions' => [[
                        'attribute' => self::KEY, 'operator' => 'ONE_OF', 'value' => ['true'],
                    ]]]],
                    'splits' => [['variationKey' => 'matched', 'serialId' => 301, 'shards' => []]],
                ],
                [
                    'key' => 'attribute-fallback', 'rules' => [], 'doLog' => true,
                    'splits' => [['variationKey' => 'fallback', 'serialId' => 302, 'shards' => []]],
                ],
            ],
        ];
        return json_encode(['createdAt' => '2026-01-01T00:00:00Z', 'environment' => ['name' => 'test'], 'flags' => $flags]);
    }
}

final class EvaluationTelemetryTestHook implements Hook
{
    public array $stages = [];
    public $beforeCallback;
    public $afterCallback;
    public $finallyCallback;

    public function supportsFlagValueType(string $flagValueType): bool
    {
        return true;
    }

    public function before(HookContext $context, HookHints $hints): ?EvaluationContextInterface
    {
        $this->stages[] = 'before';
        return $this->beforeCallback ? ($this->beforeCallback)($context) : null;
    }

    public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
    {
        $this->stages[] = 'after';
        if ($this->afterCallback) {
            ($this->afterCallback)($context);
        }
    }

    public function error(HookContext $context, \Throwable $error, HookHints $hints): void
    {
        $this->stages[] = 'error';
    }

    public function finally(HookContext $context, HookHints $hints): void
    {
        $this->stages[] = 'finally';
        if ($this->finallyCallback) {
            ($this->finallyCallback)($context);
        }
    }
}
