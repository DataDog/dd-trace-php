<?php

namespace DDTrace\Tests\Integrations\Elasticsearch\V8;

use DDTrace\Tag;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

// also drops empty arrays
function keep_non_guzzle_and_curl_spans($span)
{
    if (!\is_array($span)) {
        return true;
    }
    if (isset($span['name'])) {
        return \stripos($span['name'], 'guzzle') !== 0 && \strpos($span['name'], 'curl') !== 0 ;
    }
    return !empty($span);
}

function array_filter_recursive(callable $keep_fn, array $input)
{
    foreach ($input as &$value) {
        if (\is_array($value)) {
            $value = array_filter_recursive($keep_fn, $value);
        }
    }
    return \array_filter($input, $keep_fn);
}

/**
 * Tests for Elasticsearch Client. We test specifically only most commonly used tests, for the other tests we just make
 * sure that if a non existing method is provided, that for example does not exists for the used client version
 * the integration does not throw an exception.
 */
class ElasticSearchIntegrationTest extends IntegrationTestCase
{
    protected static $lockedResource = "elasticsearch";

    const HOST7 = 'http://elasticsearch7_integration:9200';

    public function testNamespaceMethodNotExistsDoesNotCrashApps()
    {
        $integration = new ElasticSearchIntegration();
        $integration->traceNamespaceMethod('\Wrong\Namespace', 'wrong_method');
        $this->addToAssertionCount(1);
    }

    public function testMethodNotExistsDoesNotCrashApps()
    {
        $integration = new ElasticSearchIntegration();
        $integration->traceSimpleMethod('\Wrong\Class', 'wrong_method');
        $this->addToAssertionCount(1);
    }

    public function testConstructor()
    {
        $traces = $this->isolateTracer(function () {
            $this->client();
        });

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.__construct',
                'elasticsearch',
                'elasticsearch',
                '__construct'
            )->withExactTags([
                Tag::COMPONENT => 'elasticsearch'
            ]),
        ]);
    }

    public function testCount()
    {
        $client = $this->client();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('count', $client->count());
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.count',
                'elasticsearch',
                'elasticsearch',
                'count'
            )->withExactTags([
                Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'elasticsearch'
            ])->withChildren([
                SpanAssertion::exists('Elasticsearch.Endpoint.performRequest', 'performRequest')->withChildren([
                    SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                ]),
            ]),
            SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
        ]);
    }

    public function testDelete()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertSame('deleted', $client->delete([
                'id' => 1,
                'index' => 'my_index8',
                'type' => 'my_type',
            ])["result"]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.delete',
                'elasticsearch',
                'elasticsearch',
                'delete index:my_index8 type:my_type'
            )->withExactTags([
                Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'elasticsearch'
            ])->withChildren([
                SpanAssertion::exists('Elasticsearch.Endpoint.performRequest', 'performRequest')->withChildren([
                    SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                ]),
            ]),
            SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
        ]);
    }

    public function testExists()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertTrue($client->exists([
                'id' => 1,
                'index' => 'my_index8',
                'type' => 'my_type',
            ])->asBool());
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.exists',
                'elasticsearch',
                'elasticsearch',
                'exists index:my_index8 type:my_type'
            )->withExactTags([
                Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'elasticsearch'
            ])->withChildren([
                SpanAssertion::exists('Elasticsearch.Endpoint.performRequest', 'performRequest')->withChildren([
                    SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                ]),
            ]),
        ]);
    }

    public function testExplain()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        if (PHP_VERSION_ID >= 70300) {
            sleep(1); // to flush it fully
        }
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('explanation', $client->explain([
                'id' => 1,
                'index' => 'my_index8',
                'type' => 'my_type',
                'body' => [
                    'query' => [
                        'match' => [ 'my' => 'elasticsearch' ],
                    ],
                ],
            ]));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.explain',
                'elasticsearch',
                'elasticsearch',
                'explain index:my_index8 type:my_type'
            )->withExactTags([
                Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'elasticsearch'
            ])->withChildren([
                SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.serialize', 'Elastic.Transport.Serializer.JsonSerializer.serialize'),
                SpanAssertion::exists('Elasticsearch.Endpoint.performRequest', 'performRequest')->withChildren([
                    SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                ]),
            ]),
            SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
        ]);
    }

    public function testGet()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('found', $client->get([
                'id' => 1,
                'index' => 'my_index8',
                'type' => 'my_type',
            ]));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.get',
                'elasticsearch',
                'elasticsearch',
                'get index:my_index8 type:my_type'
            )->withExactTags([
                Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'elasticsearch'
            ])
            ->withChildren([
                SpanAssertion::exists('Elasticsearch.Endpoint.performRequest', 'performRequest')->withChildren([
                    SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                ]),
            ]),
            SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
        ]);
    }

    public function testIndex()
    {
        $client = $this->client();
        $traces = $this->isolateTracer(function () use ($client) {
            $response = $client->index([
                'id' => 1,
                'index' => 'my_index8',
                'type' => 'my_type',
                'body' => ['my' => 'body'],
            ]);
            $this->assertTrue(empty($response['created']) || $response['result'] === 'updated');
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.index',
                'elasticsearch',
                'elasticsearch',
                'index index:my_index8 type:my_type'
            )->withExactTags([
                Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'elasticsearch'
            ])->withChildren([
                SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.serialize', 'Elastic.Transport.Serializer.JsonSerializer.serialize'),
                SpanAssertion::exists('Elasticsearch.Endpoint.performRequest', 'performRequest')->withChildren([
                    SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                ]),
            ]),
            SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
        ]);
    }

    public function testLimitedTracer()
    {
        $client = $this->client();
        $traces = $this->isolateLimitedTracer(function () use ($client) {
            $client->indices()->delete(['index' => 'my_index8']);
            $client->index([
                'id' => 1,
                'index' => 'my_index8',
                'type' => 'my_type',
                'body' => ['my' => 'body'],
            ]);
            $client->indices()->flush();
            $docs = $client->search([
                'scroll' => '1s',
                'size' => 1,
                'index' => 'my_index8',
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass(),
                    ],
                ],
            ]);

            // Now we loop until the scroll "cursors" are exhausted
            $scroll_id = $docs['_scroll_id'];
            while (\true) {
                // Execute a Scroll request
                $response = $client->scroll(
                    [
                        "scroll_id" => $scroll_id,
                        "scroll" => "1s",
                    ]
                );

                // Check to see if we got any search hits from the scroll
                if (count($response['hits']['hits']) > 0) {
                    // If yes, Do Work Here

                    // Get new scroll_id
                    // Must always refresh your _scroll_id!  It can change sometimes
                    $scroll_id = $response['_scroll_id'];
                } else {
                    // No results, scroll cursor is empty.  You've exported all the data
                    break;
                }
            }
        });

        $this->assertEmpty($traces);
    }


    public function testScroll()
    {
        $client = $this->client();
        $client->indices()->delete(['index' => 'my_index8']);
        $client->index([
            'id' => 1,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->index([
            'id' => 2,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'second'],
        ]);
        $client->indices()->flush();
        if (PHP_VERSION_ID >= 70300) {
            sleep(1); // to flush it fully
        }
        $docs = $client->search([
            'scroll' => '1s',
            'size' => 1,
            'index' => 'my_index8',
            'body' => [
                'query' => [
                    'match_all' => new \stdClass(),
                ],
            ],
        ]);
        $traces = $this->isolateTracer(function () use ($client, $docs) {
            $root = \DDTrace\start_trace_span();
            $root->name = "root";
            // Now we loop until the scroll "cursors" are exhausted
            $scroll_id = $docs['_scroll_id'];
            while (\true) {
                // Execute a Scroll request
                $response = $client->scroll(
                    [
                        "scroll_id" => $scroll_id,
                        "scroll" => "1s",
                    ]
                );

                // Check to see if we got any search hits from the scroll
                if (count($response['hits']['hits']) > 0) {
                    // If yes, Do Work Here

                    // Get new scroll_id
                    // Must always refresh your _scroll_id!  It can change sometimes
                    $scroll_id = $response['_scroll_id'];
                } else {
                    // No results, scroll cursor is empty.  You've exported all the data
                    break;
                }
            }
            \DDTrace\close_span();
        });


        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('root')->withChildren([
                SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
                SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
                SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
                SpanAssertion::build(
                    'Elasticsearch.Client.scroll',
                    'elasticsearch',
                    'elasticsearch',
                    'scroll'
                )->withExactTags([
                    Tag::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'elasticsearch',
                    '_dd.base_service' => 'phpunit',
                ])->withChildren([
                    SpanAssertion::exists('Elasticsearch.Endpoint.performRequest')->withChildren([
                        SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                    ]),
                ]),
                SpanAssertion::build(
                    'Elasticsearch.Client.scroll',
                    'elasticsearch',
                    'elasticsearch',
                    'scroll'
                )->withExactTags([
                    Tag::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'elasticsearch',
                    '_dd.base_service' => 'phpunit',
                ])->withChildren([
                    SpanAssertion::exists('Elasticsearch.Endpoint.performRequest')->withChildren([
                        SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                    ]),
                ])
            ])
        ]);
    }

    public function testSearch()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $client->search([
                'index' => 'my_index8',
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass(),
                    ],
                ],
            ]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.search',
                'elasticsearch',
                'elasticsearch',
                'search index:' . 'my_index8'
            )->withExactTags([
                Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'elasticsearch'
            ])
            ->withChildren([
                SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.serialize', 'Elastic.Transport.Serializer.JsonSerializer.serialize'),
                SpanAssertion::exists('Elasticsearch.Endpoint.performRequest', 'performRequest')->withChildren([
                    SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                ]),
            ]),
        ]);
    }

    public function testPerformRequest()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $client->search([
                'index' => 'my_index8',
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass(),
                    ],
                ],
            ]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Elasticsearch.Client.search', 'search index:my_index8')
                ->withChildren([
                    SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.serialize', 'Elastic.Transport.Serializer.JsonSerializer.serialize'),
                    SpanAssertion::build(
                        'Elasticsearch.Endpoint.performRequest',
                        'elasticsearch',
                        'elasticsearch',
                        'performRequest'
                    )->withExactTags([
                        'elasticsearch.url' => '/my_index8/_search',
                        'elasticsearch.method' => 'POST',
                        'elasticsearch.body' => '{"query":{"match_all":{}}}',
                        Tag::SPAN_KIND => 'client',
                        Tag::COMPONENT => 'elasticsearch'
                    ])->withChildren([
                        SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                    ]),
                ]),
        ]);
    }

    public function testUpdate()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index8',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('_type', $client->update([
                'id' => 1,
                'index' => 'my_index8',
                'type' => 'my_type',
                'body' => ['doc' => ['my' => 'body']],
            ]));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'Elasticsearch.Client.update',
                'elasticsearch',
                'elasticsearch',
                'update index:my_index8 type:my_type'
            )->withExactTags([
                Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'elasticsearch'
            ])->withChildren([
                SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.serialize', 'Elastic.Transport.Serializer.JsonSerializer.serialize'),
                SpanAssertion::exists('Elasticsearch.Endpoint.performRequest', 'performRequest')->withChildren([
                    SpanAssertion::exists('Psr\Http\Client\ClientInterface.sendRequest', 'sendRequest'),
                ]),
            ]),
            SpanAssertion::exists('Elastic.Transport.Serializer.JsonSerializer.unserialize', 'Elastic.Transport.Serializer.JsonSerializer.unserialize'),
        ]);
    }

    /**
     * In the case of namespaces we are not storing any additional info in the spans, so we only want
     * to make sure that the appropriate methods are traced.
     *
     * @dataProvider namespacesDataProvider
     * @param string $namespace
     * @param string $method
     */
    public function testNamespaces($namespace, $method)
    {
        $client = $this->client();

        $traces = $this->isolateTracer(function () use ($client, $namespace, $method) {
            try {
                $client->$namespace()->$method([]);
            } catch (\Elastic\Elasticsearch\Exception\ElasticsearchException $ex) {
            }
        });

        $fragment = ucfirst($namespace);
        $this->assertOneSpan($traces, SpanAssertion::exists("Elasticsearch.{$fragment}.$method"));
    }

    public function namespacesDataProvider()
    {
        return [
            // indices operations
            ['indices', 'analyze'],
            ['indices', 'analyze'],
            ['indices', 'clearCache'],
            ['indices', 'close'],
            ['indices', 'create'],
            ['indices', 'delete'],
            ['indices', 'deleteAlias'],
            ['indices', 'deleteTemplate'],
            ['indices', 'exists'],
            ['indices', 'existsAlias'],
            ['indices', 'existsTemplate'],
            ['indices', 'flush'],
            ['indices', 'getAlias'],
            ['indices', 'getFieldMapping'],
            ['indices', 'getMapping'],
            ['indices', 'getSettings'],
            ['indices', 'getTemplate'],
            ['indices', 'open'],
            ['indices', 'putAlias'],
            ['indices', 'putMapping'],
            ['indices', 'putSettings'],
            ['indices', 'putTemplate'],
            ['indices', 'recovery'],
            ['indices', 'refresh'],
            ['indices', 'segments'],
            ['indices', 'stats'],
            ['indices', 'updateAliases'],
            ['indices', 'validateQuery'],

            // cat operations
            ['cat', 'aliases'],
            ['cat', 'allocation'],
            ['cat', 'count'],
            ['cat', 'fielddata'],
            ['cat', 'health'],
            ['cat', 'help'],
            ['cat', 'indices'],
            ['cat', 'master'],
            ['cat', 'nodes'],
            ['cat', 'pendingTasks'],
            ['cat', 'recovery'],
            ['cat', 'shards'],
            ['cat', 'threadPool'],

            // snapshot operations
            ['snapshot', 'create'],
            ['snapshot', 'createRepository'],
            ['snapshot', 'delete'],
            ['snapshot', 'deleteRepository'],
            ['snapshot', 'get'],
            ['snapshot', 'getRepository'],
            ['snapshot', 'restore'],
            ['snapshot', 'status'],

            // cluster operations
            ['cluster', 'getSettings'],
            ['cluster', 'health'],
            ['cluster', 'pendingTasks'],
            ['cluster', 'putSettings'],
            ['cluster', 'reroute'],
            ['cluster', 'state'],
            ['cluster', 'stats'],

            // nodes operations
            ['nodes', 'hotThreads'],
            ['nodes', 'info'],
            ['nodes', 'stats'],
        ];
    }

    /**
     * @return Client
     */
    protected function client()
    {
        return ClientBuilder::create()->setHosts([self::HOST7])->build();
    }

    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function isolateLimitedTracer($fn, $tracer = null)
    {
        $traces = parent::isolateLimitedTracer($fn, $tracer);
        return array_filter_recursive(__NAMESPACE__ . '\\keep_non_guzzle_and_curl_spans', $traces);
    }

    /**
     * @param $fn
     * @param null $tracer
     * @param array $config
     * @return array[]
     */
    public function isolateTracer($fn, $tracer = null, $config = [])
    {
        $traces = parent::isolateTracer($fn, $tracer);
        return array_filter_recursive(__NAMESPACE__ . '\\keep_non_guzzle_and_curl_spans', $traces);
    }
}
