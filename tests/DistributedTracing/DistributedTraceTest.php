<?php

namespace DDTrace\Tests\DistributedTracing;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class DistributedTraceTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../Frameworks/Custom/Version_Not_Autoloaded/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_DISTRIBUTED_TRACING' => 'true',
            'DD_TRACE_NO_AUTOLOADER' => 'true',
        ]);
    }

    public function testDistributedTrace()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/index.php',
                [
                    'x-datadog-trace-id: 1042',
                    'x-datadog-parent-id: 1000',
                ]
            );
            $this->call($spec);
        });

        $this->assertSame(1042, $traces[0][0]['trace_id']);
        $this->assertSame(1000, $traces[0][0]['parent_id']);
    }

    // Synthetics requests have "0" as the parent ID
    public function testDistributedTraceWithNoParent()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/index.php',
                [
                    'x-datadog-trace-id: 6017420907356617206',
                    'x-datadog-parent-id: 0',
                ]
            );
            $this->call($spec);
        });

        $this->assertSame(6017420907356617206, $traces[0][0]['trace_id']);
        $this->assertArrayNotHasKey('parent_id', $traces[0][0]);
    }

    public function testInvalidTraceId()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/index.php',
                [
                    'x-datadog-trace-id: this-is-not-valid',
                    'x-datadog-parent-id: 42',
                ]
            );
            $this->call($spec);
        });

        $this->assertNotSame('this-is-not-valid', $traces[0][0]['trace_id']);
        $this->assertNotSame(0, $traces[0][0]['trace_id']);
        $this->assertArrayNotHasKey('parent_id', $traces[0][0]);
    }

    public function testInvalidParentId()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/index.php',
                [
                    'x-datadog-trace-id: 42',
                    'x-datadog-parent-id: this-is-not-valid',
                ]
            );
            $this->call($spec);
        });

        $this->assertNotSame(42, $traces[0][0]['trace_id']);
        $this->assertArrayNotHasKey('parent_id', $traces[0][0]);
    }
}
