<?php

namespace DDTrace\Tests\Integrations\Elasticsearch\V1;

use DDTrace\Tests\Common\SpanAssertion;

/**
 * Tests for Elasticsearch Client. We test specifically only most commonly used tests, for the other tests we just make
 * sure that if a non existing method is provided, that for example does not exists for the used client version
 * the integration does not throw an exception.
 */
class ElasticSearchSandboxedIntegrationTest extends ElasticSearchIntegrationTest
{
    const IS_SANDBOX = true;

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
}
