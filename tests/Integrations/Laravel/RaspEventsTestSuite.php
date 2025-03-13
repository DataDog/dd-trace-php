<?php

namespace DDTrace\Tests\Integrations\Laravel;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class RaspEventsTestSuite extends AppsecTestCase
{
    public function testWhenRequestIsBlockedLaravelPageIsNotDisplayed()
    {
        sleep(5);
        $eventToBlock = [
            "rasp_rule" => "lfi",
            0 => [
              "server.io.fs.file" => "index.php"
            ],
            "eventName" => "push_addresses"
        ];
        AppsecStatus::getInstance()->simulateBlockOnEvent($eventToBlock);
        $response = $this->call(
            GetSpec::create('Rasp endpoint', "/rasp?data=index.php")
        );
        $this->assertEquals('&nbsp;', $response);
    }
}
