<?php

namespace DDTrace\Tests\Integration\Integrations;

use DDTrace\Integrations;
use DDTrace\Tests\Integration\Common\IntegrationTestCase;
use DDTrace\Tests\Integration\Common\SpanAssertion;

const BASE_TAGS = [
    'out.host' => 'memcached_integration',
    'out.port' => '11211',
];


final class MemcachedTest extends IntegrationTestCase
{
    /**
     * @var \Memcached
     */
    private $client;

    public static function setUpBeforeClass()
    {
        Integrations\Memcached::load();
    }

    protected function setUp()
    {
        parent::setUp();
        $this->client = new \Memcached();
        $this->client->addServer('memcached_integration', 11211);
        $this->withTracer(function() {
            // Cleaning up existing data from previous tests
            $this->client->flush();
        });
    }

    protected function tearDown()
    {
        $this->client->delete('key');
        parent::tearDown();
    }

    public function test_add()
    {
        $traces = $this->withTracer(function() {
            $this->client->add('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.add', 'memcached', 'memcached', 'add')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'add key',
                    'memcached.command' => 'add',
                ])),
        ]);
    }

    public function test_addByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->addByKey('my_server', 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.addByKey', 'memcached', 'memcached', 'addByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'addByKey key',
                    'memcached.command' => 'addByKey',
                    'memcached.server_key' => 'my_server',
                ])),
        ]);
    }

    /** Should fail because memcached is compressed by default */
    public function test_append_compressed()
    {
        $traces = $this->withTracer(function() {
            try {
                $this->client->append('key', 'value');
                $this->fail('An exception should be raised because client is compressed');
            } catch (\Exception $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.append', 'memcached', 'memcached', 'append')
                ->setError()
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'append key',
                    'memcached.command' => 'append',
                ]))
                ->withExistingTagsNames(['system.pid', 'error.msg', 'error.type', 'error.stack']),
        ]);
    }

    public function test_append_uncompressed()
    {
        $this->client->setOption(\Memcached::OPT_COMPRESSION, false);
        $traces = $this->withTracer(function() {
            $this->client->append('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.append', 'memcached', 'memcached', 'append')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'append key',
                    'memcached.command' => 'append',
                ])),
        ]);
    }

    /** Should fail because memcached is compressed by default */
    public function test_appendByKey_compressed()
    {
        $traces = $this->withTracer(function() {
            try {
                $this->client->appendByKey('my_server', 'key', 'value');
                $this->fail('An exception should be raised because client is compressed');
            } catch (\Exception $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.appendByKey', 'memcached', 'memcached', 'appendByKey')
                ->setError()
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'appendByKey key',
                    'memcached.command' => 'appendByKey',
                    'memcached.server_key' => 'my_server',
                ]))
                ->withExistingTagsNames(['system.pid', 'error.msg', 'error.type', 'error.stack']),
        ]);
    }

    /** Should fail because memcached is compressed by default */
    public function test_appendByKey()
    {
        $this->client->setOption(\Memcached::OPT_COMPRESSION, false);
        $traces = $this->withTracer(function() {
            $this->client->appendByKey('my_server', 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.appendByKey', 'memcached', 'memcached', 'appendByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'appendByKey key',
                    'memcached.command' => 'appendByKey',
                    'memcached.server_key' => 'my_server',
                ])),
        ]);
    }

    public function test_delete()
    {
        $traces = $this->withTracer(function() {
            $this->client->add('key', 'value');
            $this->assertSame('value', $this->client->get('key'));
            $this->client->delete('key');
            $this->assertFalse($this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.delete', 'memcached', 'memcached', 'delete')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'delete key',
                    'memcached.command' => 'delete',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function test_deleteByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->addByKey('my_server', 'key', 'value');
            $this->assertSame('value', $this->client->getByKey('my_server', 'key'));
            $this->client->deleteByKey('my_server', 'key');
            $this->assertFalse($this->client->getByKey('my_Server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::exists('Memcached.getByKey'),
            SpanAssertion::build('Memcached.deleteByKey', 'memcached', 'memcached', 'deleteByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'deleteByKey key',
                    'memcached.command' => 'deleteByKey',
                    'memcached.server_key' => 'my_server',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function test_deleteMulti()
    {
        $traces = $this->withTracer(function() {
            $this->client->add('key1', 'value1');
            $this->client->add('key2', 'value2');
            $this->assertSame('value1', $this->client->get('key1'));
            $this->assertSame('value2', $this->client->get('key2'));
            $this->client->deleteMulti(['key1', 'key2']);
            $this->assertFalse($this->client->get('key1'));
            $this->assertFalse($this->client->get('key2'));
        });
        // A note about xxxMulti from Memcached integration docblock:
        //
        //       setMulti and deleteMulti don't generate out.host and out.port because it
        //       might be different for each key. setMultiByKey does, since you're pinning a
        //       specific server.
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.deleteMulti', 'memcached', 'memcached', 'deleteMulti')
                ->withExactTags([
                    'memcached.query' => 'deleteMulti key1,key2',
                    'memcached.command' => 'deleteMulti',
                ]),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function test_deleteMultiByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->addByKey('my_server', 'key1', 'value1');
            $this->client->addByKey('my_server', 'key2', 'value2');
            $this->assertSame('value1', $this->client->getByKey('my_server', 'key1'));
            $this->assertSame('value2', $this->client->getByKey('my_server', 'key2'));
            $this->client->deleteMultiByKey('my_server', ['key1', 'key2']);
            $this->assertFalse($this->client->getByKey('my_Server', 'key1'));
            $this->assertFalse($this->client->getByKey('my_Server', 'key2'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::exists('Memcached.getByKey'),
            SpanAssertion::exists('Memcached.getByKey'),
            SpanAssertion::build('Memcached.deleteMultiByKey', 'memcached', 'memcached', 'deleteMultiByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'deleteMultiByKey key1,key2',
                    'memcached.command' => 'deleteMultiByKey',
                    'memcached.server_key' => 'my_server',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function test_decrement_binary_protocol()
    {
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $traces = $this->withTracer(function() {
            $this->client->decrement('key', 2, 100);
            // Note that the default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also when not loading the integration.
            $this->assertSame('100', $this->client->get('key'));
            $this->client->decrement('key', 2, 100);
            // Note that '$this->client->get('key')' returns '98 ' (note the trailing space). This is not an effect
            // of our instrumentation as is present even when not loaded.
            $this->assertSame('98', trim($this->client->get('key')));
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.decrement', 'memcached', 'memcached', 'decrement')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'decrement key',
                    'memcached.command' => 'decrement',
                ])),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.decrement', 'memcached', 'memcached', 'decrement')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'decrement key',
                    'memcached.command' => 'decrement',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function test_decrement_non_binary_protocol()
    {
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, false);
        $traces = $this->withTracer(function() {
            $this->client->add('key', 100);
            $this->client->decrement('key', 2);
            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(98, $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.decrement', 'memcached', 'memcached', 'decrement')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'decrement key',
                    'memcached.command' => 'decrement',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function test_decrementByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->addByKey('my_server', 'key', 100);
            $this->client->decrementByKey('my_server', 'key', 2);
            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(98, $this->client->getByKey('my_server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.decrementByKey', 'memcached', 'memcached', 'decrementByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'decrementByKey key',
                    'memcached.command' => 'decrementByKey',
                    'memcached.server_key' => 'my_server',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function test_increment_binary_protocol()
    {
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $traces = $this->withTracer(function() {
            $this->client->increment('key', 2, 100);
            // Note that the default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also when not loading the integration.
            $this->assertSame('100', $this->client->get('key'));
            $this->client->increment('key', 2, 100);
            $this->assertSame('102', $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.increment', 'memcached', 'memcached', 'increment')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'increment key',
                    'memcached.command' => 'increment',
                ])),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.increment', 'memcached', 'memcached', 'increment')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'increment key',
                    'memcached.command' => 'increment',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function test_increment_non_binary_protocol()
    {
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, false);
        $traces = $this->withTracer(function() {
            $this->client->add('key', 0);
            $this->client->increment('key', 2);
            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(2, $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.increment', 'memcached', 'memcached', 'increment')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'increment key',
                    'memcached.command' => 'increment',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function test_incrementByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->addByKey('my_server', 'key', 100);
            $this->client->incrementByKey('my_server', 'key', 2);
            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(102, $this->client->getByKey('my_server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.incrementByKey', 'memcached', 'memcached', 'incrementByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'incrementByKey key',
                    'memcached.command' => 'incrementByKey',
                    'memcached.server_key' => 'my_server',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function test_flush()
    {
        $traces = $this->withTracer(function() {
            $this->client->add('key', 'value');
            $this->assertSame('value', $this->client->get('key'));
            $this->client->flush();
            $this->assertFalse($this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.flush', 'memcached', 'memcached', 'flush')
                ->withExactTags([
                    'memcached.command' => 'flush',
                ]),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function test_get()
    {
        $traces = $this->withTracer(function() {
            $this->client->add('key', 'value');
            $this->assertSame('value', $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.get', 'memcached', 'memcached', 'get')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'get key',
                    'memcached.command' => 'get',
                ])),
        ]);
    }

    public function test_getMulti()
    {
        $traces = $this->withTracer(function() {
            $this->client->add('key1', 'value1');
            $this->client->add('key2', 'value2');
            $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $this->client->getMulti(['key1', 'key2']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.getMulti', 'memcached', 'memcached', 'getMulti')
                ->withExactTags([
                    'memcached.query' => 'getMulti key1,key2',
                    'memcached.command' => 'getMulti',
                ]),
        ]);
    }

    public function test_getByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->addByKey('my_server', 'key', 'value');
            $this->assertSame('value', $this->client->getByKey('my_server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.getByKey', 'memcached', 'memcached', 'getByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'getByKey key',
                    'memcached.command' => 'getByKey',
                    'memcached.server_key' => 'my_server',
                ])),
        ]);
    }

    public function test_getMultiByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->addByKey('my_server', 'key1', 'value1');
            $this->client->addByKey('my_server', 'key2', 'value2');
            $this->assertEquals(
                ['key1' => 'value1', 'key2' => 'value2'],
                $this->client->getMultiByKey('my_server', ['key1', 'key2'])
            );
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.getMultiByKey', 'memcached', 'memcached', 'getMultiByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'getMultiByKey key1,key2',
                    'memcached.command' => 'getMultiByKey',
                    'memcached.server_key' => 'my_server',
                ])),
        ]);
    }

    public function test_replace()
    {
        $traces = $this->withTracer(function() {
            $this->client->add('key', 'value');
            $this->client->replace('key', 'replaced');
            $this->assertEquals('replaced', $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.replace', 'memcached', 'memcached', 'replace')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'replace key',
                    'memcached.command' => 'replace',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function test_replaceByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->addByKey('my_server', 'key', 'value');
            $this->client->replaceByKey('my_server', 'key', 'replaced');
            $this->assertEquals('replaced', $this->client->getByKey('my_server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.replaceByKey', 'memcached', 'memcached', 'replaceByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'replaceByKey key',
                    'memcached.command' => 'replaceByKey',
                    'memcached.server_key' => 'my_server',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function test_set()
    {
        $traces = $this->withTracer(function() {
            $this->client->set('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.set', 'memcached', 'memcached', 'set')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'set key',
                    'memcached.command' => 'set',
                ])),
        ]);
    }

    public function test_setByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->setByKey('my_server', 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.setByKey', 'memcached', 'memcached', 'setByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'setByKey key',
                    'memcached.command' => 'setByKey',
                    'memcached.server_key' => 'my_server',
                ])),
        ]);
    }

    public function test_setMulti()
    {
        $traces = $this->withTracer(function() {
            $this->client->setMulti(['key1' =>'value1', 'key2' => 'value2']);
            $this->assertEquals(['key1' =>'value1', 'key2' => 'value2'], $this->client->getMulti(['key1', 'key2']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.setMulti', 'memcached', 'memcached', 'setMulti')
                ->withExactTags([
                    'memcached.query' => 'setMulti key1,key2',
                    'memcached.command' => 'setMulti',
                ]),
            SpanAssertion::exists('Memcached.getMulti'),
        ]);
    }

    public function test_setMultiByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->setMultiByKey('my_server', ['key1' =>'value1', 'key2' => 'value2']);
            $this->assertEquals(['key1' =>'value1', 'key2' => 'value2'], $this->client->getMultiByKey('my_server', ['key1', 'key2']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.setMultiByKey', 'memcached', 'memcached', 'setMultiByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'setMultiByKey key1,key2',
                    'memcached.command' => 'setMultiByKey',
                    'memcached.server_key' => 'my_server',
                ])),
            SpanAssertion::exists('Memcached.getMultiByKey'),
        ]);
    }

    public function test_touch()
    {
        $traces = $this->withTracer(function() {
            $this->client->touch('key');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.touch', 'memcached', 'memcached', 'touch')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'touch key',
                    'memcached.command' => 'touch',
                ])),
        ]);
    }

    public function test_touchByKey()
    {
        $traces = $this->withTracer(function() {
            $this->client->touchByKey('my_server', 'key');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.touchByKey', 'memcached', 'memcached', 'touchByKey')
                ->withExactTags(array_merge(BASE_TAGS, [
                    'memcached.query' => 'touchByKey key',
                    'memcached.command' => 'touchByKey',
                    'memcached.server_key' => 'my_server',
                ])),
        ]);
    }
}
