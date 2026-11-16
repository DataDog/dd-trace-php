<?php

namespace DDTrace\Tests\Integrations\WordPress;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class PathParamsTestSuite extends AppsecTestCase
{
    public function testPost()
    {
        $this->call(
            GetSpec::create(
                'Post example',
                '/hello-world'
            )
        );

        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertEquals('hello-world', $events[0][0]["server.request.path_params"]['name']);
    }

    public function testCategory()
    {
        $this->call(
            GetSpec::create(
                'Category',
                '/category/uncategorized'
            )
        );

        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertEquals('uncategorized', $events[0][0]["server.request.path_params"]['category_name']);
    }

    public function testAuthor()
    {
        $this->call(
            GetSpec::create(
                'Author',
                '/author/test'
            )
        );

        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertEquals('test', $events[0][0]["server.request.path_params"]['author_name']);
    }

    public function testNonExistingPost()
    {
       $this->call(
            GetSpec::create(
                'Not existing post',
                '/non-existing-post'
            )
        );

        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(0, count($events));
    }
}
