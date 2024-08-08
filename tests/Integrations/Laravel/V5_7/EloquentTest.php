<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_7;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class EloquentTest extends WebFrameworkTestCase
{
    public static $database = "laravel57";

    use SpanAssertionTrait;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_7/public/index.php';
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
            'Laravel',
            'sql',
            'App\User'
        )->withExactTags([
            TAG::SPAN_KIND => 'client',
            Tag::COMPONENT => 'eloquent',
            Tag::DB_SYSTEM => 'other_sql',
        ]));
    }

    public function testRefresh()
    {
        $this->connection()->exec("insert into users (id, email) VALUES (1, 'test-user-deleted@email.com')");
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent delete', '/eloquent/refresh');
            $this->call($spec);
        });
        $this->assertOneExpectedSpan($traces, SpanAssertion::build(
            'eloquent.refresh',
            'Laravel',
            'sql',
            'App\User'
        )->withExactTags([
            TAG::SPAN_KIND => 'client',
            Tag::COMPONENT => 'eloquent',
            Tag::DB_SYSTEM => 'other_sql',
        ]));
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
            TAG::SPAN_KIND => 'client',
            'sql.query' => 'select * from `users`',
            Tag::COMPONENT => 'eloquent',
            Tag::DB_SYSTEM => 'other_sql',
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
            TAG::SPAN_KIND => 'client',
            Tag::COMPONENT => 'eloquent',
            Tag::DB_SYSTEM => 'other_sql',
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
            TAG::SPAN_KIND => 'client',
            Tag::COMPONENT => 'eloquent',
            Tag::DB_SYSTEM => 'other_sql',
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
            TAG::SPAN_KIND => 'client',
            Tag::COMPONENT => 'eloquent',
            Tag::DB_SYSTEM => 'other_sql',
        ]));
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=laravel57', 'test', 'test');
    }
}
