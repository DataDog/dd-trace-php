<?php

namespace DDTrace\Tests\Integrations\WordPress\V4_8;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

 /**
 * @group appsec
 */
class PathParamsTest extends AppsecTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_4_8/index.php';
    }

    protected function databaseDump() {
        return file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_5_5/wp_2020-10-21.sql');
    }

    public function testPost()
    {
        $this->call(
            GetSpec::create(
                'Post example',
                '/hello-world'
            )
        );

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals('hello-world', $events[0]['name']);
        $this->assertEquals('push_params', $events[0]['eventName']);
    }

    public function testCategory()
    {
        $this->call(
            GetSpec::create(
                'Category',
                '/category/uncategorized'
            )
        );

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals('uncategorized', $events[0]['category_name']);
        $this->assertEquals('push_params', $events[0]['eventName']);
    }

    public function testAuthor()
    {
        $this->call(
            GetSpec::create(
                'Author',
                '/author/test'
            )
        );

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals('test', $events[0]['author_name']);
        $this->assertEquals('push_params', $events[0]['eventName']);
    }

    public function testNonExistingPost()
    {
       $this->call(
            GetSpec::create(
                'Not existing post',
                '/non-existing-post'
            )
        );

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(0, count($events));
    }
}
