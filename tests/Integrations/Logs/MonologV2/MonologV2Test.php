<?php

namespace DDTrace\Tests\Integrations\Logs\MonologV2;

use DDTrace\Tests\Integrations\Logs\MonologV1\MonologV1Test;

class MonologV2Test extends MonologV1Test
{
    public function testDebugJsonFormatting() {
        $this->usingJson(
            'debug',
            $this->getLogger(true),
            '/^{"message":"A debug message","context":{"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env"},"level":100,"level_name":"DEBUG","channel":"test","datetime":".*","extra":{},"ddsource":"php"}/'
        );
    }

    public function testLogJsonFormatting() {
        $this->usingJson(
            'log',
            $this->getLogger(true),
            '/^{"message":"A critical message","context":{"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env"},"level":500,"level_name":"CRITICAL","channel":"test","datetime":".*","extra":{},"ddsource":"php"}/',
            false,
            'critical'
        );
    }
}
