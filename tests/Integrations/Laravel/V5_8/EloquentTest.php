<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_8;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class EloquentTest extends WebFrameworkTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

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
        $this->assertExpectedSpans($traces, [
            SpanAssertion::exists('laravel.request'),
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::exists('PDO.exec'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::build(
                'eloquent.get',
                '',
                'sql',
                'App\User'
            )->withExactTags([
                'sql.query' => 'select * from `users`',
            ]),
        ], true);
    }

    public function testInsert()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent insert', '/eloquent/insert');
            $response = $this->call($spec);
        });
        $this->assertExpectedSpans($traces, [
            SpanAssertion::exists('laravel.request'),
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::exists('PDO.exec'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::build(
                'eloquent.insert',
                '',
                'sql',
                'App\User'
            ),
        ], true);
    }

    public function testUpdate()
    {
        $this->connection()->exec("insert into users (email) VALUES ('test-user-updated@email.com')");
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent update', '/eloquent/update');
            $response = $this->call($spec);
        });
        $this->assertExpectedSpans($traces, [
            SpanAssertion::exists('laravel.request'),
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::exists('PDO.exec'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('eloquent.get'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::build(
                'eloquent.update',
                '',
                'sql',
                'App\User'
            ),
        ], true);
    }

    public function testDelete()
    {
        $this->connection()->exec("insert into users (email) VALUES ('test-user-deleted@email.com')");
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent delete', '/eloquent/delete');
            $response = $this->call($spec);
        });
        $this->assertExpectedSpans($traces, [
            SpanAssertion::exists('laravel.request'),
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::exists('PDO.exec'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::exists('eloquent.get'),
            SpanAssertion::exists('PDO.prepare'),
            SpanAssertion::exists('PDOStatement.execute'),
            SpanAssertion::build(
                'eloquent.delete',
                '',
                'sql',
                'App\User'
            ),
        ], true);
    }

    private function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }
}
