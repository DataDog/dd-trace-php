<?php

namespace DDTrace\Tests\Integrations\Logs\MonologV2;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Integrations\Logs\MonologV1\MonologV1Test;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

use function DDTrace\close_span;
use function DDTrace\set_distributed_tracing_context;
use function DDTrace\start_span;

class MonologV2Test extends MonologV1Test
{
    public function testDebugJsonFormatting() {
        $this->usingJson(
            'debug',
            $this->getLogger(true),
            '/^{"message":"A debug message","context":{"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env","level_name":"debug"},"level":100,"level_name":"DEBUG","channel":"test","datetime":".*","extra":{}}/'
        );
    }

    public function testLogJsonFormatting() {
        $this->usingJson(
            'log',
            $this->getLogger(true),
            '/^{"message":"A critical message","context":{"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env","level_name":"critical"},"level":500,"level_name":"CRITICAL","channel":"test","datetime":".*","extra":{}}/',
            false,
            'critical'
        );
    }
}
