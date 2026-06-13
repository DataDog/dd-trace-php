<?php

declare(strict_types=1);

namespace Benchmarks\API;

/**
 * Microbenchmark for the server-side flag-evaluation hot path.
 *
 * Each evaluation goes through the native bridge (\DDTrace\ffe_evaluate ->
 * ddog_ffe_evaluate), which performs the UFC assignment and, when flag
 * evaluation counting is enabled, the per-evaluation record + aggregation
 * insert into the in-process evaluation aggregator. The config is loaded
 * once from a static in-memory UFC document, so no Remote Config backend is
 * required to drive the path.
 *
 * @BeforeMethods("setUp")
 */
class FlagEvaluationBench
{
    /** Subset of the UFC type ids exposed via the native bridge. */
    const TYPE_STRING = 0;
    const TYPE_BOOL = 3;

    /**
     * In-memory UFC configuration exercising the common evaluation outcomes:
     * a split-allocation string flag, a targeting-rule match, and a disabled
     * flag (default outcome).
     */
    public static $ufc = <<<'JSON'
{
  "createdAt": "2024-01-01T00:00:00Z",
  "environment": {"name": "bench"},
  "flags": {
    "string.flag": {
      "key": "string.flag",
      "enabled": true,
      "variationType": "STRING",
      "variations": {
        "blue": {"key": "blue", "value": "blue"},
        "green": {"key": "green", "value": "green"}
      },
      "allocations": [{
        "key": "alloc-split",
        "rules": [],
        "splits": [
          {"variationKey": "blue", "shards": [{"salt": "bench", "totalShards": 10000, "ranges": [{"start": 0, "end": 5000}]}]},
          {"variationKey": "green", "shards": [{"salt": "bench", "totalShards": 10000, "ranges": [{"start": 5000, "end": 10000}]}]}
        ],
        "doLog": true
      }]
    },
    "targeted.flag": {
      "key": "targeted.flag",
      "enabled": true,
      "variationType": "STRING",
      "variations": {
        "premium": {"key": "premium", "value": "premium"}
      },
      "allocations": [{
        "key": "alloc-targeted",
        "rules": [{"conditions": [{"attribute": "plan", "operator": "ONE_OF", "value": ["pro"]}]}],
        "splits": [{"variationKey": "premium", "shards": []}],
        "doLog": true
      }]
    },
    "disabled.flag": {
      "key": "disabled.flag",
      "enabled": false,
      "variationType": "BOOLEAN",
      "variations": {
        "on": {"key": "on", "value": true}
      },
      "allocations": []
    }
  }
}
JSON;

    /**
     * Records an evaluation into the aggregator on each call. The first
     * evaluation for a (flag, variant, allocation, reason, context) tuple
     * creates a bucket; subsequent evaluations merge into it, so the subject
     * measures both the insert and the merge paths.
     *
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchEvaluateSplit(): void
    {
        \DDTrace\ffe_evaluate('string.flag', self::TYPE_STRING, 'user-1', [
            'country' => 'US',
            'age' => 42,
        ]);
    }

    /**
     * Targeting-rule match path: a rule condition is matched before the split
     * is applied, exercising the targeting branch of the evaluator and the
     * resulting aggregation bucket.
     *
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchEvaluateTargetingMatch(): void
    {
        \DDTrace\ffe_evaluate('targeted.flag', self::TYPE_STRING, 'user-1', [
            'plan' => 'pro',
        ]);
    }

    /**
     * High-cardinality context: a distinct targeting key and attribute per
     * rev produce a fresh full-tier bucket each time, exercising the
     * aggregation insert (rather than merge) and its bounding behaviour.
     *
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchEvaluateDistinctContexts(): void
    {
        static $i = 0;
        \DDTrace\ffe_evaluate('string.flag', self::TYPE_STRING, 'user-' . (++$i), [
            'bucket' => $i,
        ]);
    }

    /**
     * Baseline with flag evaluation counting disabled: isolates the cost of
     * the record + aggregation insert by measuring the same evaluation call
     * without it.
     *
     * @BeforeMethods({"setUp", "disableCounting"})
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchEvaluateWithoutCounting(): void
    {
        \DDTrace\ffe_evaluate('string.flag', self::TYPE_STRING, 'user-1', [
            'country' => 'US',
            'age' => 42,
        ]);
    }

    public function setUp(): void
    {
        // Enable flag evaluation counting so the record + aggregation insert
        // runs on every evaluation (this is also the default when unset).
        putenv('DD_FLAGGING_EVALUATION_COUNTS_ENABLED=true');
        \DDTrace\Testing\ffe_load_config(self::$ufc);
    }

    public function disableCounting(): void
    {
        putenv('DD_FLAGGING_EVALUATION_COUNTS_ENABLED=false');
    }
}
