<?php

namespace DDTrace\Tests\Integrations\Logs\MonologV1;

use DDTrace\Tests\Integrations\Logs\BaseLogsTest;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class MonologV1Test extends BaseLogsTest
{
    protected function getLogger()
    {
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('/tmp/test.log'));

        return $logger;
    }

    public function testDebugWithPlaceholders64bit()
    {
        $this->withPlaceholders(
            'debug',
            $this->getLogger(),
            '/^\[.*\] test.DEBUG: A debug message \[dd.trace_id="\d+" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env" level_name="debug"\] \[\] \[\]/',
        );
    }

    public function testDebugInContext64bit()
    {
        $this->inContext(
            'debug',
            $this->getLogger(),
            '/^\[.*\] test.DEBUG: A debug message {"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env","level_name":"debug"} \[\]$/'
        );
    }

    public function testDebugAppended64bit()
    {
        $this->appended(
            'debug',
            $this->getLogger(),
            '/^\[.*\] test.DEBUG: A debug message \[dd.trace_id="\d+" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env" level_name="debug"\] \[\] \[\]/'
        );
    }

    public function testDebugWithPlaceholders128bit()
    {
        $this->withPlaceholders(
            'debug',
            $this->getLogger(),
            '/^\[.*\] test.DEBUG: A debug message \[dd.trace_id="192f3581c8461c79abf2684ee31ce27d" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env" level_name="debug"\] \[\] \[\]/',
            true
        );
    }

    public function testDebugInContext128bit()
    {
        $this->inContext(
            'debug',
            $this->getLogger(),
            '/^\[.*\] test.DEBUG: A debug message {"dd.trace_id":"192f3581c8461c79abf2684ee31ce27d","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env","level_name":"debug"} \[\]$/',
            true
        );
    }

    public function testDebugAppended128bit()
    {
        $this->appended(
            'debug',
            $this->getLogger(),
            '/^\[.*\] test.DEBUG: A debug message \[dd.trace_id="192f3581c8461c79abf2684ee31ce27d" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env" level_name="debug"\] \[\] \[\]/',
            true
        );
    }
}
