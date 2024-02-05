<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_9;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class PathParamsTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_6_1/index.php';
    }

    public function ddSetUp()
    {
        parent::ddSetUp();
        $pdo = new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
        $pdo->exec(file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_6_1/scripts/wp_initdb.sql'));
        AppsecStatus::getInstance()->setDefaults();
    }

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        AppsecStatus::getInstance()->init();
    }

    public static function ddTearDownAfterClass()
    {
        AppsecStatus::getInstance()->destroy();
        parent::ddTearDownAfterClass();
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
