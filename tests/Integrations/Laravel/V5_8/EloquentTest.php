<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_8;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class EloquentTest extends WebFrameworkTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    const IS_SANDBOX = false;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_8/public/index.php';
    }

    protected function setUp()
    {
        parent::setUp();
        $this->connection()->exec("DELETE from users where email LIKE 'test-user-%'");
    }

    public function testGet()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent get', '/eloquent/get');
            $this->call($spec);
        });
        $this->assertOneExpectedSpan($traces, SpanAssertion::build(
            'eloquent.get',
            'Laravel',
            'sql',
            'select * from `users`'
        )->withExactTags([
            'sql.query' => 'select * from `users`',
            'integration.name' => 'eloquent',
        ]));
    }

    public function testInsert()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent insert', '/eloquent/insert');
            $this->call($spec);
        });
        $this->assertOneExpectedSpan($traces, SpanAssertion::build(
            'eloquent.insert',
            'Laravel',
            'sql',
            'App\User'
        )->withExactTags([
            'integration.name' => 'eloquent',
        ]));
    }

    public function testUpdate()
    {
        $this->connection()->exec("insert into users (email) VALUES ('test-user-updated@email.com')");
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent update', '/eloquent/update');
            $this->call($spec);
        });
        $this->assertOneExpectedSpan($traces, SpanAssertion::build(
            'eloquent.update',
            'Laravel',
            'sql',
            'App\User'
        )->withExactTags([
            'integration.name' => 'eloquent',
        ]));
    }

    public function testDelete()
    {
        $this->connection()->exec("insert into users (email) VALUES ('test-user-deleted@email.com')");
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent delete', '/eloquent/delete');
            $this->call($spec);
        });
        $this->assertOneExpectedSpan($traces, SpanAssertion::build(
            'eloquent.delete',
            'Laravel',
            'sql',
            'App\User'
        )->withExactTags([
            'integration.name' => 'eloquent',
        ]));
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }
}
