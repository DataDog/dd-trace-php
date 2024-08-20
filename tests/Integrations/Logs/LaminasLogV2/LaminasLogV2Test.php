<?php

namespace DDTrace\Tests\Integrations\Logs\LaminasLogV2;

use DDTrace\Integrations\Laminas\LaminasIntegration;
use DDTrace\Tests\Integrations\Logs\BaseLogsTest;
use Laminas\Log\Formatter\Json;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;

class LaminasLogV2Test extends BaseLogsTest
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->putEnvAndReloadConfig([
            'DD_LOGS_INJECTION=1'
        ]);
        $integration = new LaminasIntegration();
        $integration->init();
    }

    protected function getLogger($jsonFormatter = false)
    {
        $logger = new Logger();
        $writer = new Stream(static::logFile());

        if ($jsonFormatter) {
            $writer->setFormatter(new Json());
        }

        $logger->addWriter($writer);

        return $logger;
    }

    public function testDebugWithPlaceholders64bit()
    {
        $this->withPlaceholders(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message \[dd.trace_id="\d+" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env"\]/'
        );
    }

    public function testDebugInContext64bit()
    {
        $this->inContext(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message {"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env"}/'
        );
    }

    public function testDebugAppended64bit()
    {
        $this->appended(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message \[dd.trace_id="\d+" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env"\]/'
        );
    }

    public function testDebugWithPlaceholders128bit()
    {
        $this->withPlaceholders(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message \[dd.trace_id="192f3581c8461c79abf2684ee31ce27d" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env"\]/',
            true
        );
    }

    public function testDebugInContext128bit()
    {
        $this->inContext(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message {"dd.trace_id":"192f3581c8461c79abf2684ee31ce27d","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env"}/',
            true
        );
    }

    public function testDebugAppended128bit()
    {
        $this->appended(
            'debug',
            $this->getLogger(),
            '/^.* DEBUG \(7\): A debug message \[dd.trace_id="192f3581c8461c79abf2684ee31ce27d" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env"\]/',
            true
        );
    }

    public function testLogWithPlaceholders64bit()
    {
        $this->withPlaceholders(
            'log',
            $this->getLogger(),
            '/^.* INFO \(6\): A 6 message \[dd.trace_id="\d+" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env"\]/',
            false,
            Logger::INFO
        );
    }

    public function testLogInContext64bit()
    {
        $this->inContext(
            'log',
            $this->getLogger(),
            '/^.* WARN \(4\): A 4 message {"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":"4.2","dd.env":"my-env"}/',
            false,
            Logger::WARN
        );
    }

    public function testLogAppended64bit()
    {
        $this->appended(
            'log',
            $this->getLogger(),
            '/^.* ERR \(3\): A 3 message \[dd.trace_id="\d+" dd.span_id="\d+" dd.service="my-service" dd.version="4.2" dd.env="my-env"\]/',
            false,
            Logger::ERR
        );
    }

    public function testDebugJsonFormatting()
    {
        $this->usingJson(
            'debug',
            $this->getLogger(true),
            '/^{"timestamp":".*","priority":7,"priorityName":"DEBUG","message":"A debug message","extra":{"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":4.2,"dd.env":"my-env"}}/'
        );
    }

    public function testLogJsonFormatting()
    {
        $this->usingJson(
            'log',
            $this->getLogger(true),
            '/^{"timestamp":".*","priority":4,"priorityName":"WARN","message":"A 4 message","extra":{"dd.trace_id":"\d+","dd.span_id":"\d+","dd.service":"my-service","dd.version":4.2,"dd.env":"my-env"}}/',
            false,
            Logger::WARN
        );
    }
}
