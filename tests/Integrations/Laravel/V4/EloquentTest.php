<?php

namespace DDTrace\Tests\Integrations\Laravel\V4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class EloquentTest extends WebFrameworkTestCase
{
    use SpanAssertionTrait;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_4_2/public/index.php';
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->connection()->exec("DELETE from users where email LIKE 'test-user-%'");
    }

    public function testDestroy()
    {
        $this->connection()->exec("insert into users (id, email) VALUES (1, 'test-user-deleted@email.com')");
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent destroy', '/eloquent/destroy');
            $this->call($spec);
        });
        $this->assertOneExpectedSpan($traces, SpanAssertion::build(
            'eloquent.destroy',
            'laravel',
            'sql',
            'User'
        )->withExactTags([]));
    }

    public function testGet()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent get', '/eloquent/get');
            $this->call($spec);
        });
        $this->assertOneExpectedSpan($traces, SpanAssertion::build(
            'eloquent.get',
            'laravel',
            'sql',
            'select * from `users`'
        )->withExactTags([
            'sql.query' => 'select * from `users`',
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
            'laravel',
            'sql',
            'User'
        )->withExactTags([
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
            'laravel',
            'sql',
            'User'
        )->withExactTags([
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
            'laravel',
            'sql',
            'User'
        )->withExactTags([
        ]));
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }
}
