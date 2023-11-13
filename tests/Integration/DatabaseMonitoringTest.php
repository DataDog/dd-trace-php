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
        self::putEnv("DD_ENV");
        self::putEnv("DD_SERVICE");
        self::putEnv("DD_SERVICE_MAPPING");
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
                DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql', 1);
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
        $this->assertSame("/*dddbs='testdb',ddps='phpunit',traceparent='00-0000000000000000c151df7d6ee5e2d6-a3978fb9b92502a8-01'*/ SELECT 1", $commentedQuery);
        // phpcs:enable Generic.Files.LineLength.TooLong
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists("phpunit")->withChildren([
                SpanAssertion::exists('instrumented')->withExactTags([
                    "_dd.dbm_trace_injected" => "true",
                    "_dd.base_service" => "phpunit",
                ])
            ])
        ]);
    }

    public function testInjectionPeerService()
    {
        try {
            $hook = \DDTrace\install_hook(self::class . "::instrumented", function (HookData $hook) {
                $hook->span()->service = "testdb";
                $hook->span()->name = "instrumented";
                $hook->span()->meta["peer.service"] = 'dbinstance';
                DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql', 1);
            });
            self::putEnv("DD_TRACE_DEBUG_PRNG_SEED=42");
            self::putEnv("DD_DBM_PROPAGATION_MODE=full");
            self::putEnv("DD_SERVICE_MAPPING=phpunit:mapped-service");
            $traces = $this->isolateTracer(function () use (&$commentedQuery) {
                \DDTrace\start_trace_span();
                $commentedQuery = $this->instrumented(0, "SELECT 1");
                \DDTrace\close_span();
            });
        } finally {
            \DDTrace\remove_hook($hook);
        }

        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->assertSame("/*dddbs='dbinstance',ddps='mapped-service',traceparent='00-0000000000000000c151df7d6ee5e2d6-a3978fb9b92502a8-01'*/ SELECT 1", $commentedQuery);
        // phpcs:enable Generic.Files.LineLength.TooLong
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists("phpunit")->withChildren([
                SpanAssertion::exists('instrumented')->withExactTags([
                    "_dd.dbm_trace_injected" => "true",
                    "peer.service" => "dbinstance",
                    "_dd.base_service" => "mapped-service",
                ])
            ])
        ]);
    }

    public function testEnvPropagation()
    {
        self::putEnv("DD_ENV=envtest");
        self::putEnv("DD_SERVICE=service \'test");
        self::putEnv("DD_VERSION=0");
        $commented = DatabaseIntegrationHelper::propagateViaSqlComments("q", "", \DDTrace\DBM_PROPAGATION_SERVICE);
        $this->assertSame("/*dde='envtest',ddps='service%20%5C%27test',ddpv='0'*/ q", $commented);
    }

    public function testRootSpanPropagation()
    {
        $this->putEnv("DD_TRACE_GENERATE_ROOT_SPAN=true");

        $rootSpan = \DDTrace\root_span();
        $rootSpan->service = "";
        $rootSpan->meta["version"] = "0";
        $rootSpan->meta["env"] = "0";
        $commented = DatabaseIntegrationHelper::propagateViaSqlComments("q", "", \DDTrace\DBM_PROPAGATION_SERVICE);
        $this->assertSame("/*dde='0',ddpv='0'*/ q", $commented);

        $this->resetTracer();
    }

    public function noInjectionWithUnsupportedDriver()
    {
        try {
            $hook = \DDTrace\install_hook(self::class . "::instrumented", function (HookData $hook) {
                DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'sqlite');
            });
            self::putEnv("DD_DBM_PROPAGATION_MODE=full");
            $this->assertSame("SELECT 1", $this->instrumented(0, "SELECT 1"));
        } finally {
            \DDTrace\remove_hook($hook);
        }
    }
}
