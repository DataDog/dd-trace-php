<?php

namespace DDTrace\Tests\Integrations\Memcached;

use DDTrace\Integrations\SpanTaxonomy;
use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Util\Obfuscation;

final class MemcachedTest extends IntegrationTestCase
{
    /**
     * @var \Memcached
     */
    private $client;

    private static $host = 'memcached_integration';
    private static $port = '11211';

    protected function ddSetUp()
    {
        parent::ddSetUp();

        $this->client = new \Memcached();
        $this->client->addServer(self::$host, self::$port);
        $this->isolateTracer(function () {
            // Cleaning up existing data from previous tests
            $this->client->flush();
        });
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED',
            'DD_SERVICE',
            'DD_TRACE_MEMCACHED_OBFUSCATION',
        ];
    }

    public function testAdd()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.add', 'memcached', 'memcached', 'add')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'add ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'add',
                    Tag::SPAN_KIND => 'client',
                ]))
        ]);
    }

    public function testAddByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->addByKey('my_server', 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.addByKey', 'memcached', 'memcached', 'addByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'addByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'addByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ]))
        ]);
    }

    /** Should fail because memcached is compressed by default */
    public function testAppendCompressed()
    {
        $traces = $this->isolateTracer(function () {
            try {
                $this->client->append('key', 'value');
                $this->fail('An exception should be raised because client is compressed');
            } catch (\Exception $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.append', 'memcached', 'memcached', 'append')
                ->setError()
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'append ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'append',
                    Tag::SPAN_KIND => 'client',
                ]))
                ->withExistingTagsNames([Tag::ERROR_MSG, 'error.type', 'error.stack']),
        ]);
    }

    public function testAppendUncompressed()
    {
        $this->client->setOption(\Memcached::OPT_COMPRESSION, false);
        $traces = $this->isolateTracer(function () {
            $this->client->append('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.append', 'memcached', 'memcached', 'append')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'append ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'append',
                    Tag::SPAN_KIND => 'client',
                ])),
        ]);
    }

    /** Should fail because memcached is compressed by default */
    public function testAppendByKeyCompressed()
    {
        $traces = $this->isolateTracer(function () {
            try {
                $this->client->appendByKey('my_server', 'key', 'value');
                $this->fail('An exception should be raised because client is compressed');
            } catch (\Exception $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.appendByKey', 'memcached', 'memcached', 'appendByKey')
                ->setError()
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'appendByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'appendByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ]))
                ->withExistingTagsNames([Tag::ERROR_MSG, 'error.type', 'error.stack']),
        ]);
    }

    /** Should fail because memcached is compressed by default */
    public function testAppendByKey()
    {
        $this->client->setOption(\Memcached::OPT_COMPRESSION, false);
        $traces = $this->isolateTracer(function () {
            $this->client->appendByKey('my_server', 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.appendByKey', 'memcached', 'memcached', 'appendByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'appendByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'appendByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
        ]);
    }

    public function testDelete()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');

            $this->assertSame('value', $this->client->get('key'));

            $this->client->delete('key');

            $this->assertFalse($this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.delete', 'memcached', 'memcached', 'delete')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'delete ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'delete',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function testDeleteByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->addByKey('my_server', 'key', 'value');

            $this->assertSame('value', $this->client->getByKey('my_server', 'key'));

            $this->client->deleteByKey('my_server', 'key');

            $this->assertFalse($this->client->getByKey('my_Server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::exists('Memcached.getByKey'),
            SpanAssertion::build('Memcached.deleteByKey', 'memcached', 'memcached', 'deleteByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'deleteByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'deleteByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function testLimitedTracerDeleteMulti()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $this->client->add('key1', 'value1');
            $this->client->add('key2', 'value2');

            $this->assertSame('value1', $this->client->get('key1'));
            $this->assertSame('value2', $this->client->get('key2'));

            $this->client->deleteMulti(['key1', 'key2']);

            $this->assertFalse($this->client->get('key1'));
            $this->assertFalse($this->client->get('key2'));
        });

        $this->assertEmpty($traces);
    }

    public function testDeleteMulti()
    {
        $traces = $this->isolateTracer(function () {
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
                ->withExactTags(array_merge($this->baseTags(), [
                    'memcached.query' => 'deleteMulti ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'deleteMulti',
                ])),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function testDeleteMultiByKey()
    {
        $traces = $this->isolateTracer(function () {
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
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'deleteMultiByKey ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'deleteMultiByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function testDecrementBinaryProtocol()
    {
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $traces = $this->isolateTracer(function () {
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
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'decrement ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'decrement',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.decrement', 'memcached', 'memcached', 'decrement')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'decrement ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'decrement',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function testDecrementNonBinaryProtocol()
    {
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, false);
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 100);

            $this->client->decrement('key', 2);

            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(98, $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.decrement', 'memcached', 'memcached', 'decrement')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'decrement ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'decrement',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function testDecrementByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->addByKey('my_server', 'key', 100);

            $this->client->decrementByKey('my_server', 'key', 2);

            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(98, $this->client->getByKey('my_server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.decrementByKey', 'memcached', 'memcached', 'decrementByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'decrementByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'decrementByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function testIncrementBinaryProtocol()
    {
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $traces = $this->isolateTracer(function () {
            $this->client->increment('key', 2, 100);

            // Note that the default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also when not loading the integration.
            $this->assertSame('100', $this->client->get('key'));

            $this->client->increment('key', 2, 100);

            $this->assertSame('102', $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.increment', 'memcached', 'memcached', 'increment')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'increment ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'increment',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.increment', 'memcached', 'memcached', 'increment')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'increment ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'increment',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function testIncrementNonBinaryProtocol()
    {
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, false);
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 0);
            $this->client->increment('key', 2);

            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(2, $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.increment', 'memcached', 'memcached', 'increment')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'increment ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'increment',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function testIncrementByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->addByKey('my_server', 'key', 100);
            $this->client->incrementByKey('my_server', 'key', 2);

            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(102, $this->client->getByKey('my_server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.incrementByKey', 'memcached', 'memcached', 'incrementByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'incrementByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'incrementByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function testFlush()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');

            $this->assertSame('value', $this->client->get('key'));

            $this->client->flush();

            $this->assertFalse($this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.flush', 'memcached', 'memcached', 'flush')
                ->withExactTags(array_merge($this->baseTags(), [
                    'memcached.command' => 'flush',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function testGet()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');

            $this->assertSame('value', $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.get', 'memcached', 'memcached', 'get')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'get ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'get',
                    Tag::SPAN_KIND => 'client',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testGetMissingKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');

            $this->assertSame(false, $this->client->get('missing_key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.get', 'memcached', 'memcached', 'get')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'get ' . Obfuscation::toObfuscatedString('missing_key'),
                    'memcached.command' => 'get',
                    Tag::SPAN_KIND => 'client',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testGetMulti()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key1', 'value1');
            $this->client->add('key2', 'value2');

            $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $this->client->getMulti(['key1', 'key2']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.getMulti', 'memcached', 'memcached', 'getMulti')
                ->withExactTags(array_merge($this->baseTags(), [
                    'memcached.query' => 'getMulti ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'getMulti',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 2,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testGetMultiNoObfuscation()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_MEMCACHED_OBFUSCATION=false']);
        $traces = $this->isolateTracer(function () {
            $this->client->add('key1', 'value1');
            $this->client->add('key2', 'value2');

            $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $this->client->getMulti(['key1', 'key2']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.getMulti', 'memcached', 'memcached', 'getMulti')
                ->withExactTags(array_merge($this->baseTags(), [
                    'memcached.query' => 'getMulti key1,key2',
                    'memcached.command' => 'getMulti',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 2,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testGetMultiNotAllExist()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key1', 'value1');
            $this->client->add('key2', 'value2');

            $this->assertEquals(['key1' => 'value1'], $this->client->getMulti(['key1', 'missing_key']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.getMulti', 'memcached', 'memcached', 'getMulti')
                ->withExactTags(array_merge($this->baseTags(), [
                    'memcached.query' => 'getMulti ' . Obfuscation::toObfuscatedString(['key1', 'missing_key'], ','),
                    'memcached.command' => 'getMulti',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testGetMultiNoneExist()
    {
        $traces = $this->isolateTracer(function () {
            $this->assertEquals([], $this->client->getMulti(['key1', 'key2']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.getMulti', 'memcached', 'memcached', 'getMulti')
                ->withExactTags(array_merge($this->baseTags(), [
                    'memcached.query' => 'getMulti ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'getMulti',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testGetByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->addByKey('my_server', 'key', 'value');
            $this->assertSame('value', $this->client->getByKey('my_server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.getByKey', 'memcached', 'memcached', 'getByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'getByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'getByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testGetMultiByKey()
    {
        $traces = $this->isolateTracer(function () {
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
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'getMultiByKey ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'getMultiByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 2,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testReplace()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');
            $this->client->replace('key', 'replaced');

            $this->assertEquals('replaced', $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.replace', 'memcached', 'memcached', 'replace')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'replace ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'replace',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }

    public function testReplaceByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->addByKey('my_server', 'key', 'value');
            $this->client->replaceByKey('my_server', 'key', 'replaced');

            $this->assertEquals('replaced', $this->client->getByKey('my_server', 'key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.addByKey'),
            SpanAssertion::build('Memcached.replaceByKey', 'memcached', 'memcached', 'replaceByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'replaceByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'replaceByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.getByKey'),
        ]);
    }

    public function testSet()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->set('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.set', 'memcached', 'memcached', 'set')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'set ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'set',
                    Tag::SPAN_KIND => 'client',
                ])),
        ]);
    }

    public function testSetByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->setByKey('my_server', 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.setByKey', 'memcached', 'memcached', 'setByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'setByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'setByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
        ]);
    }

    public function testSetMulti()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->setMulti(['key1' => 'value1', 'key2' => 'value2']);

            $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $this->client->getMulti(['key1', 'key2']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.setMulti', 'memcached', 'memcached', 'setMulti')
                ->withExactTags(array_merge($this->baseTags(), [
                    'memcached.query' => 'setMulti ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'setMulti',
                ])),
            SpanAssertion::exists('Memcached.getMulti'),
        ]);
    }

    public function testSetMultiByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->setMultiByKey('my_server', ['key1' => 'value1', 'key2' => 'value2']);

            $this->assertEquals(
                ['key1' => 'value1', 'key2' => 'value2'],
                $this->client->getMultiByKey('my_server', ['key1', 'key2'])
            );
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.setMultiByKey', 'memcached', 'memcached', 'setMultiByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'setMultiByKey ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'setMultiByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.getMultiByKey'),
        ]);
    }

    public function testTouch()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->touch('key');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.touch', 'memcached', 'memcached', 'touch')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'touch ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'touch',
                    Tag::SPAN_KIND => 'client',
                ])),
        ]);
    }

    public function testTouchByKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->touchByKey('my_server', 'key');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.touchByKey', 'memcached', 'memcached', 'touchByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'touchByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'touchByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ])),
        ]);
    }

    public function testCas()
    {
        $this->client->set('ip_block', 'some_value');
        $result = $this->client->get('ip_block', null, \Memcached::GET_EXTENDED);
        $cas = $result['cas'];
        $traces = $this->isolateTracer(function () use ($cas) {
            $this->client->cas($cas, 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.cas', 'memcached', 'memcached', 'cas')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'cas ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'cas',
                    Tag::SPAN_KIND => 'client',
                ]))
                ->withExistingTagsNames(['memcached.cas_token']),
        ]);
    }

    public function testCasByKey()
    {
        $this->client->setByKey('my_server', 'ip_block', 'some_value');
        $result = $this->client->getByKey('my_server', 'ip_block', null, \Memcached::GET_EXTENDED);
        $cas = $result['cas'];
        $traces = $this->isolateTracer(function () use ($cas) {
            $this->client->casByKey($cas, 'my_server', 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.casByKey', 'memcached', 'memcached', 'casByKey')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'casByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'casByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ]))
                ->withExistingTagsNames(['memcached.cas_token']),
        ]);
    }

    // https://github.com/DataDog/dd-trace-php/issues/622
    // https://github.com/DataDog/dd-trace-php/issues/656
    public function testResultCodeIsError()
    {
        $this->isolateTracer(function () {
            $m = new \Memcached();
            $m->addServer('memcached_server_does_not_exist', 11211);
            $m->get('foo');
            $this->assertContains(
                $m->getResultCode(),
                [\Memcached::RES_TIMEOUT, \Memcached::RES_HOST_LOOKUP_FAILURE]
            );
        });
    }

    public function testCommandPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.add', 'memcached', 'memcached', 'add')
                ->withExactTags(array_merge(self::baseTags(true), [
                    'memcached.query' => 'add ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'add',
                    Tag::SPAN_KIND => 'client',
                ]))
        ]);
    }

    public function testCommandByKeyPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $this->client->addByKey('my_server', 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.addByKey', 'memcached', 'memcached', 'addByKey')
                ->withExactTags(array_merge(self::baseTags(true), [
                    'memcached.query' => 'addByKey ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'addByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ]))
        ]);
    }

    public function testMultiPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $this->client->add('key1', 'value1');
            $this->client->add('key2', 'value2');

            $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $this->client->getMulti(['key1', 'key2']));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::build('Memcached.getMulti', 'memcached', 'memcached', 'getMulti')
                ->withExactTags(array_merge($this->baseTags(true), [
                    'memcached.query' => 'getMulti ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'getMulti',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 2,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testMultiByKeyPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
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
                ->withExactTags(array_merge(self::baseTags(true), [
                    'memcached.query' => 'getMultiByKey ' . Obfuscation::toObfuscatedString(['key1', 'key2'], ','),
                    'memcached.command' => 'getMultiByKey',
                    'memcached.server_key' => 'my_server',
                    Tag::SPAN_KIND => 'client',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 2,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testFlushPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');

            $this->assertSame('value', $this->client->get('key'));

            $this->client->flush();

            $this->assertFalse($this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcached.add'),
            SpanAssertion::exists('Memcached.get'),
            SpanAssertion::build('Memcached.flush', 'memcached', 'memcached', 'flush')
                ->withExactTags(array_merge(self::baseTags(true), [
                    'memcached.command' => 'flush',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcached.get'),
        ]);
    }


    public function testCasPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $this->client->set('ip_block', 'some_value');
        $result = $this->client->get('ip_block', null, \Memcached::GET_EXTENDED);
        $cas = $result['cas'];
        $traces = $this->isolateTracer(function () use ($cas) {
            $this->client->cas($cas, 'key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.cas', 'memcached', 'memcached', 'cas')
                ->withExactTags(array_merge(self::baseTags(true), [
                    'memcached.query' => 'cas ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'cas',
                    Tag::SPAN_KIND => 'client',
                ]))
                ->withExistingTagsNames(['memcached.cas_token']),
        ]);
    }

    private static function baseTags($expectPeerService = false)
    {
        $tags = [
            'out.host' => self::$host,
            'out.port' => self::$port,
            Tag::SPAN_KIND => 'client',
            Tag::COMPONENT => 'memcached',
            Tag::DB_SYSTEM => 'memcached',
        ];

        if ($expectPeerService) {
            $tags['peer.service'] = 'memcached_integration';
            $tags['_dd.peer.service.source'] = 'out.host';
        }

        return $tags;
    }

    public function testNoFakeServices()
    {
        $this->putEnvAndReloadConfig([
            'DD_SERVICE=configured_service',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED=true',
            'DD_TRACE_GENERATE_ROOT_SPAN=true'
        ]);

        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('Memcached.add', 'configured_service', 'memcached', 'add')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcached.query' => 'add ' . Obfuscation::toObfuscatedString('key'),
                    'memcached.command' => 'add',
                    Tag::SPAN_KIND => 'client',
                ]))
        ], false);
    }
}
