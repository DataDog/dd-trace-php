<?php

namespace DDTrace\Tests\Integrations\Logs\LaminasLogV2;

use DDTrace\Tests\Integrations\Logs\BaseLogsTest;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;

class LaminasLogV2Test extends BaseLogsTest
{
    protected function getLogger()
    {
        $logger = new Logger();
        $writer = new Stream('/tmp/test.log');
        $logger->addWriter($writer);

        return $logger;
    }

    public function testDebugWithPlaceholders64bit()
    {
        $this->withPlaceholders(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message \[dd.trace_id="\d+" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env" level_name="log"\]/',
        );
    }

    public function testDebugInContext64bit()
    {
        $this->inContext(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message {"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env","level_name":"debug"}/'
        );
    }

    public function testDebugAppended64bit()
    {
        $this->appended(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message \[dd.trace_id="\d+" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env" level_name="debug"\]/'
        );
    }

    public function testDebugWithPlaceholders128bit()
    {
        $this->withPlaceholders(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message \[dd.trace_id="192f3581c8461c79abf2684ee31ce27d" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env" level_name="debug"\]/',
            true
        );
    }

    public function testDebugInContext128bit()
    {
        $this->inContext(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message {"dd.trace_id":"192f3581c8461c79abf2684ee31ce27d","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env","level_name":"debug"}/',
            true
        );
    }

    public function testDebugAppended128bit()
    {
        $this->appended(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message \[dd.trace_id="192f3581c8461c79abf2684ee31ce27d" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env" level_name="debug"\]/',
            true
        );
    }
}
