<?php

namespace DDTrace\Tests\Integrations\Elasticsearch\V1;

use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use Elasticsearch\Client;

/**
 * Tests for Elasticsearch Client. We test specifically only most commonly used tests, for the other tests we just make
 * sure that if a non existing method is provided, that for example does not exists for the used client version
 * the integration does not throw an exception.
 */
class ElasticSearchSandboxedIntegrationTest extends IntegrationTestCase
{
    const IS_SANDBOX = true;
    const HOST = 'elasticsearch2_integration';

    public function testNamespaceMethodNotExistsDoesNotCrashApps()
    {
        ElasticSearchIntegration::traceNamespaceMethod('\Wrong\Namespace', 'wrong_method');
        $this->addToAssertionCount(1);
    }

    public function testMethodNotExistsDoesNotCrashApps()
    {
        ElasticSearchIntegration::traceSimpleMethod('\Wrong\Class', 'wrong_method');
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
            ),
        ]);
    }

    public function testCount()
    {
        $client = $this->client();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('count', $client->count());
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.count',
                'elasticsearch',
                'elasticsearch',
                'count'
            ),
        ]);
    }

    public function testDelete()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertInternalType('array', $client->delete([
                'id' => 1,
                'index' => 'my_index',
                'type' => 'my_type',
            ]));
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.delete',
                'elasticsearch',
                'elasticsearch',
                'delete index:my_index type:my_type'
            ),
        ]);
    }

    public function testExists()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertTrue($client->exists([
                'id' => 1,
                'index' => 'my_index',
                'type' => 'my_type',
            ]));
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.exists',
                'elasticsearch',
                'elasticsearch',
                'exists index:my_index type:my_type'
            ),
        ]);
    }

    public function testExplain()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('explanation', $client->explain([
                'id' => 1,
                'index' => 'my_index',
                'type' => 'my_type',
                'body' => [
                    'query' => [
                        'match' => [ 'my' => 'elasticsearch' ],
                    ],
                ],
            ]));
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.serialize'),
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.explain',
                'elasticsearch',
                'elasticsearch',
                'explain index:my_index type:my_type'
            ),
        ]);
    }

    public function testGet()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('found', $client->get([
                'id' => 1,
                'index' => 'my_index',
                'type' => 'my_type',
            ]));
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.get',
                'elasticsearch',
                'elasticsearch',
                'get index:my_index type:my_type'
            )->setTraceAnalyticsCandidate(),
        ]);
    }

    public function testIndex()
    {
        $client = $this->client();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('created', $client->index([
                'id' => 1,
                'index' => 'my_index',
                'type' => 'my_type',
                'body' => ['my' => 'body'],
            ]));
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.serialize'),
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.index',
                'elasticsearch',
                'elasticsearch',
                'index index:my_index type:my_type'
            ),
        ]);
    }

    public function testLimitedTracer()
    {
        $client = $this->client();
        $traces = $this->isolateLimitedTracer(function () use ($client) {
            $client->indices()->delete(['index' => 'my_index']);
            $client->index([
                'id' => 1,
                'index' => 'my_index',
                'type' => 'my_type',
                'body' => ['my' => 'body'],
            ]);
            $client->indices()->flush();
            $docs = $client->search([
                'search_type' => 'scan',
                'scroll' => '1s',
                'size' => 1,
                'index' => 'my_index',
                'body' => [
                    'query' => [
                        'match_all' => [],
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
        $client->indices()->delete(['index' => 'my_index']);
        $client->index([
            'id' => 1,
            'index' => 'my_index',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $docs = $client->search([
            'search_type' => 'scan',
            'scroll' => '1s',
            'size' => 1,
            'index' => 'my_index',
            'body' => [
                'query' => [
                    'match_all' => [],
                ],
            ],
        ]);
        $traces = $this->isolateTracer(function () use ($client, $docs) {
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

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.scroll',
                'elasticsearch',
                'elasticsearch',
                'scroll'
            ),
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.scroll',
                'elasticsearch',
                'elasticsearch',
                'scroll'
            ),
        ]);
    }

    public function testSearch()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $client->search([
                'index' => 'my_index',
                'body' => [
                    'query' => [
                        'match_all' => [],
                    ],
                ],
            ]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.serialize'),
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.search',
                'elasticsearch',
                'elasticsearch',
                'search index:' . 'my_index'
            )->setTraceAnalyticsCandidate(),
        ]);
    }

    public function testPerformRequest()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $client->search([
                'index' => 'my_index',
                'body' => [
                    'query' => [
                        'match_all' => [],
                    ],
                ],
            ]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.serialize'),
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::build(
                'Elasticsearch.Endpoint.performRequest',
                'elasticsearch',
                'elasticsearch',
                'performRequest'
            )->withExactTags([
                'elasticsearch.url' => '/my_index/_search',
                'elasticsearch.method' => 'GET',
                'elasticsearch.params' => '[]',
                'elasticsearch.body' => '{"query":{"match_all":[]}}'
            ]),
            SpanAssertion::exists('Elasticsearch.Client.search'),
        ]);
    }

    public function testUpdate()
    {
        $client = $this->client();
        $client->index([
            'id' => 1,
            'index' => 'my_index',
            'type' => 'my_type',
            'body' => ['my' => 'body'],
        ]);
        $client->indices()->flush();
        $traces = $this->isolateTracer(function () use ($client) {
            $this->assertArrayHasKey('_type', $client->update([
                'id' => 1,
                'index' => 'my_index',
                'type' => 'my_type',
                'body' => ['doc' => ['my' => 'body']],
            ]));
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.serialize'),
            SpanAssertion::exists('Elasticsearch.Serializers.SmartSerializer.deserialize'),
            SpanAssertion::exists('Elasticsearch.Endpoint.performRequest'),
            SpanAssertion::build(
                'Elasticsearch.Client.update',
                'elasticsearch',
                'elasticsearch',
                'update index:my_index type:my_type'
            ),
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
            } catch (\Elasticsearch\Common\Exceptions\BadRequest400Exception $ex) {
            } catch (\Elasticsearch\Common\Exceptions\RuntimeException $ex) {
            }
        });

        $fragment = ucfirst($namespace);
        $this->assertOneSpan($traces, SpanAssertion::exists("Elasticsearch.${fragment}Namespace.$method"));
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
            ['indices', 'deleteMapping'],
            ['indices', 'deleteTemplate'],
            ['indices', 'deleteWarmer'],
            ['indices', 'exists'],
            ['indices', 'existsAlias'],
            ['indices', 'existsTemplate'],
            ['indices', 'existsType'],
            ['indices', 'flush'],
            ['indices', 'getAlias'],
            ['indices', 'getAliases'],
            ['indices', 'getFieldMapping'],
            ['indices', 'getMapping'],
            ['indices', 'getSettings'],
            ['indices', 'getTemplate'],
            ['indices', 'getWarmer'],
            ['indices', 'open'],
            ['indices', 'optimize'],
            ['indices', 'putAlias'],
            ['indices', 'putMapping'],
            ['indices', 'putSettings'],
            ['indices', 'putTemplate'],
            ['indices', 'putWarmer'],
            ['indices', 'recovery'],
            ['indices', 'refresh'],
            ['indices', 'segments'],
            ['indices', 'snapshotIndex'],
            ['indices', 'stats'],
            ['indices', 'status'],
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
            ['nodes', 'shutdown'],
            ['nodes', 'stats'],
        ];
    }

    /**
     * @return Client
     */
    protected function client()
    {
        return new Client([
            'hosts' => [
                'elasticsearch2_integration',
            ],
        ]);
    }
}
