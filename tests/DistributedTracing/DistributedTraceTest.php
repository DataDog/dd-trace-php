<?php

namespace DDTrace\Tests\DistributedTracing;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class DistributedTraceTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_DISTRIBUTED_TRACING' => 'true',
        ]);
    }

    public function testDistributedTrace()
    {
        $traces = $this->rawTracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/simple',
                [
                    'x-datadog-trace-id: 1042',
                    'x-datadog-parent-id: 1000',
                ]
            );
            $this->call($spec);
        });

        $this->assertContains('"trace_id":1042', $traces);
        // Uncomment this when parent_id propagation gets fixed
        //$this->assertContains('"parent_id":1000', $traces);
    }

    // Synthetics requests have "0" as the parent ID
    public function testDistributedTraceWithNoParent()
    {
        $traces = $this->rawTracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/simple',
                [
                    'x-datadog-trace-id: 6017420907356617206',
                    'x-datadog-parent-id: 0',
                ]
            );
            $this->call($spec);
        });
        $this->assertContains('"trace_id":6017420907356617206', $traces);
        $this->assertNotContains('"parent_id":', $traces);
    }

    public function testDistributedTraceWith64BitUnsignedInts()
    {
        $traces = $this->rawTracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/simple',
                [
                    'x-datadog-trace-id: 16142341506862590864',
                    'x-datadog-parent-id: 14922400571872573419',
                ]
            );
            $this->call($spec);
        });
        $this->assertContains('"trace_id":16142341506862590864', $traces);
        // Uncomment this when parent_id propagation gets fixed
        //$this->assertContains('"parent_id":14922400571872573419', $traces);
    }

    public function testGarbageIds()
    {
        $traces = $this->rawTracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/simple',
                [
                    'x-datadog-trace-id: this-is-not-valid',
                    'x-datadog-parent-id: 42.4',
                ]
            );
            $this->call($spec);
        });
        $this->assertNotContains('"trace_id":"this-is-not-valid"', $traces);
        $this->assertNotContains('"parent_id":', $traces);
    }
}
