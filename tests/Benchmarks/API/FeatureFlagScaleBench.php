<?php

declare(strict_types=1);

namespace Benchmarks\API;

use DDTrace\FeatureFlags\Internal\NativeEvaluator;
use DDTrace\Tests\FeatureFlags\ScaleSuite;

/**
 * Evaluates the `scale` and `scale-json` suites of ffe-system-test-data through
 * NativeEvaluator and ResultMapper, the path that Client uses. Each setup
 * checks the case's value and reason first, so a wrong result fails the run
 * instead of being measured.
 *
 * - benchJsonFirstEvaluation: the first evaluation of a JSON flag after a
 *   configuration load. One evaluation per iteration.
 * - benchJsonRepeatEvaluation: a repeat evaluation of the same flag, variant
 *   and configuration.
 * - benchScaleEvaluation: every case of one `scale` case file per revolution.
 *   These values are small; the cost is in targeting.
 */
class FeatureFlagScaleBench
{
    /** @var NativeEvaluator */
    private $evaluator;

    /** @var array<int, array<string, mixed>> */
    private $cases = [];

    private $details;

    public function provideJsonCases(): \Generator
    {
        foreach (ScaleSuite::caseFiles(ScaleSuite::SCALE_JSON) as $caseFile) {
            foreach (ScaleSuite::cases(ScaleSuite::SCALE_JSON, $caseFile) as $index => $case) {
                yield $caseFile . '#' . $index . ' ' . $case['flag'] => [
                    'file' => $caseFile,
                    'index' => $index,
                ];
            }
        }
    }

    public function provideScaleCaseFiles(): \Generator
    {
        foreach (ScaleSuite::caseFiles(ScaleSuite::SCALE) as $caseFile) {
            yield $caseFile => ['file' => $caseFile];
        }
    }

    public function setUpJsonFirstEvaluation(array $params): void
    {
        $this->setUpJsonCase($params);
        // A new configuration starts without anything derived from the
        // previous one.
        ScaleSuite::loadConfig(ScaleSuite::SCALE_JSON);
        self::resetPeakMemory();
    }

    public function setUpJsonRepeatEvaluation(array $params): void
    {
        $this->setUpJsonCase($params);
        self::resetPeakMemory();
    }

    public function setUpScaleEvaluation(array $params): void
    {
        ScaleSuite::loadConfig(ScaleSuite::SCALE);
        $this->evaluator = NativeEvaluator::create(false);
        self::assertNoFailures(ScaleSuite::checkAll($this->evaluator, ScaleSuite::SCALE, $params['file']));
        $this->cases = ScaleSuite::cases(ScaleSuite::SCALE, $params['file']);
        self::resetPeakMemory();
    }

    /**
     * @ParamProviders({"provideJsonCases"})
     * @BeforeMethods({"setUpJsonFirstEvaluation"})
     * @Revs(1)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     */
    public function benchJsonFirstEvaluation(): void
    {
        $this->details = ScaleSuite::evaluate($this->evaluator, $this->cases[0]);
    }

    /**
     * @ParamProviders({"provideJsonCases"})
     * @BeforeMethods({"setUpJsonRepeatEvaluation"})
     * @Revs(20)
     * @Iterations(5)
     * @Warmup(1)
     * @OutputTimeUnit("microseconds")
     */
    public function benchJsonRepeatEvaluation(): void
    {
        $this->details = ScaleSuite::evaluate($this->evaluator, $this->cases[0]);
    }

    /**
     * @ParamProviders({"provideScaleCaseFiles"})
     * @BeforeMethods({"setUpScaleEvaluation"})
     * @Revs(100)
     * @Iterations(5)
     * @Warmup(1)
     * @OutputTimeUnit("microseconds")
     */
    public function benchScaleEvaluation(): void
    {
        foreach ($this->cases as $case) {
            $this->details = ScaleSuite::evaluate($this->evaluator, $case);
        }
    }

    private function setUpJsonCase(array $params): void
    {
        ScaleSuite::loadConfig(ScaleSuite::SCALE_JSON);
        $this->evaluator = NativeEvaluator::create(false);

        $case = ScaleSuite::cases(ScaleSuite::SCALE_JSON, $params['file'])[$params['index']];
        $failure = ScaleSuite::check(ScaleSuite::evaluate($this->evaluator, $case), $case);
        if ($failure !== null) {
            throw new \RuntimeException($params['file'] . '#' . $params['index'] . ' ' . $case['flag'] . ': ' . $failure);
        }

        // Keep only the inputs, so the expected value does not count towards
        // the measured memory.
        unset($case['result']);
        $this->cases = [$case];
        $this->details = null;
    }

    private static function assertNoFailures(array $failures): void
    {
        if ($failures) {
            throw new \RuntimeException(implode("\n", $failures));
        }
    }

    private static function resetPeakMemory(): void
    {
        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }
}
