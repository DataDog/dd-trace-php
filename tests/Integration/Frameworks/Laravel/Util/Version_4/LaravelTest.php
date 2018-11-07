<?php

namespace DDTrace\Tests\Integration\Frameworks\Laravel\Util\Version_4;


class LaravelTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testBasicTest()
    {
        $this->client->request('GET', '/');
        $this->assertTrue($this->client->getResponse()->isOk());
    }
}
