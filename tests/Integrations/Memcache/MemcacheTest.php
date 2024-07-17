<?php

namespace DDTrace\Tests\Integrations\Memcache;

use DDTrace\Integrations\SpanTaxonomy;
use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Util\Obfuscation;

final class MemcacheTest extends IntegrationTestCase
{
    /**
     * @var \Memcache
     */
    private $client;

    private static $host = 'memcached_integration';
    private static $port = '11211';


    protected function ddSetUp()
    {
        parent::ddSetUp();

        $this->client = new \Memcache();
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
            SpanAssertion::build('Memcache.add', 'memcache', 'memcached', 'add')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'add ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'add',
                    Tag::SPAN_KIND => 'client',
                ]))
        ]);
    }

    public function testAddNoObfuscation()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_MEMCACHED_OBFUSCATION=false']);
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcache.add', 'memcache', 'memcached', 'add')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'add ' . 'key',
                    'memcache.command' => 'add',
                    Tag::SPAN_KIND => 'client',
                ]))
        ]);
    }

    public function testAppend()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->append('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcache.append', 'memcache', 'memcached', 'append')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'append ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'append',
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
            SpanAssertion::exists('Memcache.add'),
            SpanAssertion::exists('Memcache.get'),
            SpanAssertion::build('Memcache.delete', 'memcache', 'memcached', 'delete')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'delete ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'delete',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcache.get'),
        ]);
    }

    public function testDecrement()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 100);

            $this->client->decrement('key', 2);

            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(98, $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcache.add'),
            SpanAssertion::build('Memcache.decrement', 'memcache', 'memcached', 'decrement')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'decrement ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'decrement',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcache.get'),
        ]);
    }

    public function testIncrementNonBinaryProtocol()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 0);
            $this->client->increment('key', 2);

            // Note that default value is set as 'string' instead of int. This is not a side effect
            // of our instrumentation, as it happens also not loading the integration.
            $this->assertSame(2, $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcache.add'),
            SpanAssertion::build('Memcache.increment', 'memcache', 'memcached', 'increment')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'increment ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'increment',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcache.get'),
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
            SpanAssertion::exists('Memcache.add'),
            SpanAssertion::exists('Memcache.get'),
            SpanAssertion::build('Memcache.flush', 'memcache', 'memcached', 'flush')
                ->withExactTags([
                    'memcache.command' => 'flush',
                    Tag::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'memcache',
                    Tag::DB_SYSTEM => 'memcached',
                ]),
            SpanAssertion::exists('Memcache.get'),
        ]);
    }

    public function testGet()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');

            $this->assertSame('value', $this->client->get('key'));
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('Memcache.add'),
            SpanAssertion::build('Memcache.get', 'memcache', 'memcached', 'get')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'get ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'get',
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
            SpanAssertion::exists('Memcache.add'),
            SpanAssertion::build('Memcache.get', 'memcache', 'memcached', 'get')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'get ' . Obfuscation::toObfuscatedString('missing_key'),
                    'memcache.command' => 'get',
                    Tag::SPAN_KIND => 'client',
                ]))->withExactMetrics([
                    Tag::DB_ROW_COUNT => 0,
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
            SpanAssertion::exists('Memcache.add'),
            SpanAssertion::build('Memcache.replace', 'memcache', 'memcached', 'replace')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'replace ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'replace',
                    Tag::SPAN_KIND => 'client',
                ])),
            SpanAssertion::exists('Memcache.get'),
        ]);
    }

    public function testSet()
    {
        $traces = $this->isolateTracer(function () {
            $this->client->set('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcache.set', 'memcache', 'memcached', 'set')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'set ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'set',
                    Tag::SPAN_KIND => 'client',
                ])),
        ]);
    }

    public function testCas()
    {
        $this->client->set('ip_block', 'some_value');
        $flags = 0;
        $result = $this->client->get('ip_block', $flags, $cas);
        $traces = $this->isolateTracer(function () use ($cas) {
            $this->client->cas('ip_block', 'value', 0, 0, $cas);
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcache.cas', 'memcache', 'memcached', 'cas')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'cas ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'cas',
                    Tag::SPAN_KIND => 'client',
                ]))
                ->withExistingTagsNames(['memcache.cas_token']),
        ]);
    }

    public function testCommandPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcache.add', 'memcache', 'memcached', 'add')
                ->withExactTags(array_merge(self::baseTags(true), [
                    'memcache.query' => 'add ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'add',
                    Tag::SPAN_KIND => 'client',
                ]))
        ]);
    }

    public function testCasPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $this->client->set('ip_block', 'some_value');
        $flags = 0;
        $result = $this->client->get('ip_block', $flags, $cas);
        $traces = $this->isolateTracer(function () use ($cas) {
            $this->client->cas('ip_block', 'value', 0, 0, $cas);
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('Memcache.cas', 'memcache', 'memcached', 'cas')
                ->withExactTags(array_merge(self::baseTags(true), [
                    'memcache.query' => 'cas ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'cas',
                    Tag::SPAN_KIND => 'client',
                ]))
                ->withExistingTagsNames(['memcache.cas_token']),
        ]);
    }

    private static function baseTags($expectPeerService = false)
    {
        $tags = [
            'out.host' => self::$host,
            'out.port' => self::$port,
            Tag::COMPONENT => 'memcache',
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
        ]);

        $traces = $this->isolateTracer(function () {
            $this->client->add('key', 'value');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('Memcache.add', 'configured_service', 'memcached', 'add')
                ->withExactTags(array_merge(self::baseTags(), [
                    'memcache.query' => 'add ' . Obfuscation::toObfuscatedString('key'),
                    'memcache.command' => 'add',
                    Tag::SPAN_KIND => 'client',
                ]))
        ]);
    }
}
