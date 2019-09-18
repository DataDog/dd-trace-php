<?php

namespace DDTrace\Tests\Integrations\Laravel\V4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class EloquentSandboxedTest extends EloquentTest
{
    use TracerTestTrait, SpanAssertionTrait;

    const IS_SANDBOXED = true;

    public function testDestroy()
    {
        $this->connection()->exec("insert into users (id, email) VALUES (1, 'test-user-deleted@email.com')");
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Eloquent destroy', '/eloquent/destroy');
            $this->call($spec);
        });
        $this->assertOneExpectedSpan($traces, SpanAssertion::build(
            'eloquent.destroy',
            '',
            'sql',
            'User'
        )->withExactTags([
            'integration.name' => 'eloquent',
        ]));
    }
}
