<?php

namespace DDTrace\Tests\Integration;

use DDTrace\HookData;
use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class DatabaseMonitoringTest extends IntegrationTestCase
{
    public function ddTearDown()
    {
        parent::ddTearDown();
        self::putenv('DD_TRACE_DEBUG_PRNG_SEED');
        self::putenv('DD_DBM_PROPAGATION_MODE');
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN");
        self::putEnv("DD_ENV");
        self::putEnv("DD_SERVICE");
        self::putEnv("DD_VERSION");
    }

    public function instrumented($arg, $optionalArg = null)
    {
        return $optionalArg;
    }

    public function testInjection()
    {
        try {
            $hook = \DDTrace\install_hook(self::class . "::instrumented", function (HookData $hook) {
                $hook->span()->service = "testdb";
                $hook->span()->name = "instrumented";
                DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 1);
            });
            self::putEnv("DD_TRACE_DEBUG_PRNG_SEED=42");
            self::putEnv("DD_DBM_PROPAGATION_MODE=full");
            $traces = $this->isolateTracer(function () use (&$commentedQuery) {
                \DDTrace\start_trace_span();
                $commentedQuery = $this->instrumented(0, "SELECT 1");
                \DDTrace\close_span();
            });
        } finally {
            \DDTrace\remove_hook($hook);
        }

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->assertSame("/*dddbs='testdb',ddps='phpunit',traceparent='00-0000000000000000c08c967f0e5e7b0a-22e2c43f8a1ad34e-01'*/ SELECT 1", $commentedQuery);
        // phpcs:enable Generic.Files.LineLength.TooLong
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists("phpunit")->withChildren([
                SpanAssertion::exists('instrumented')->withExactTags([
                    "_dd.dbm_trace_injected" => "true"
                ])
            ])
        ]);
    }

    public function testEnvPropagation()
    {
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=0");
        self::putEnv("DD_ENV=envtest");
        self::putEnv("DD_SERVICE=service \'test");
        self::putEnv("DD_VERSION=0");
        $commented = DatabaseIntegrationHelper::propagateViaSqlComments("q", "", \DDTrace\DBM_PROPAGATION_SERVICE);
        $this->assertSame("/*dde='envtest',ddps='service%20%5C%27test',ddpv='0'*/ q", $commented);
    }

    public function testRootSpanPropagation()
    {
        $rootSpan = \DDTrace\root_span();
        $rootSpan->service = "";
        $rootSpan->meta["version"] = "0";
        $rootSpan->meta["env"] = "0";
        $commented = DatabaseIntegrationHelper::propagateViaSqlComments("q", "", \DDTrace\DBM_PROPAGATION_SERVICE);
        $this->assertSame("/*dde='0',ddpv='0'*/ q", $commented);

        $this->resetTracer();
    }
}
