<?php

// This file does not actually replace the CachedInstrumentation, but it's guaranteed to be autoloaded before the actual CachedInstrumentation.
// We just hook the CachedInstrumentation to track it.

use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;

\DDTrace\install_hook('OpenTelemetry\API\Instrumentation\CachedInstrumentation::__construct', null, function () {
    dd_trace_internal_fn("mark_integration_loaded", $this->name, $this->version);
});

\DDTrace\install_hook('OpenTelemetry\API\Instrumentation\CachedInstrumentation::tracer', null, function (\DDTrace\HookData $hook) {
    $tracer = $hook->returned;

    $name = $this->name;
    if (strpos($name, "io.opentelemetry.contrib.php.") === 0) {
        $name = substr($name, strlen("io.opentelemetry.contrib.php."));
    }
    $name = "otel.$name";

    $hook->overrideReturnValue(new class($tracer, $name) implements \OpenTelemetry\API\Trace\TracerInterface {
        public $tracer;
        public $name;

        public function __construct($tracer, $name)
        {
            $this->tracer = $tracer;
            $this->name = $name;
        }

        public function spanBuilder(string $spanName): \OpenTelemetry\API\Trace\SpanBuilderInterface
        {
            $spanBuilder = $this->tracer->spanBuilder($spanName);
            $spanBuilder->setAttribute("component", $this->name);
            return $spanBuilder;
        }

        public function isEnabled(): bool
        {
            return $this->tracer->isEnabled();
        }

        public function getInstrumentationScope(): InstrumentationScopeInterface
        {
            if (!method_exists($this->tracer, 'getInstrumentationScope')) {
                throw new \Error("There is no getInstrumentationScope method available for " . get_class($this->tracer));
            }
            return $this->tracer->getInstrumentationScope();
        }

        public function updateConfig(Configurator $configurator): void
        {
            $this->tracer->updateConfig($configurator);
        }
    });
});
