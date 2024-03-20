<?php

declare(strict_types=1);

namespace Benchmarks\API;

class ComposerTelemetryBench
{
    /**
     * @Revs(1)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Groups({"overhead"})
     */
    public function benchTelemetryParsing()
    {
        dd_trace_internal_fn(
            "detect_composer_installed_json",
            __DIR__ . "/../support/ComposerTelemetryBench/vendor/autoload.php"
        );
    }
}
