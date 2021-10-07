<?php

namespace DDTrace\Tests\Integrations\Mongo;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Integrations\MongoDB\MongoDBSubscriber;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use Exception;
use MongoDB\Client;

class MongoDBTest extends IntegrationTestCase
{
    const HOST = 'mongodb_integration';
    const PORT = '27017';
    const USER = 'test';
    const PASSWORD = 'test';
    const DATABASE = 'test_db';

    protected function ddSetUp()
    {
        parent::ddSetUp();

        if (\PHP_VERSION_ID < 70000) {
            $this->markTestAsSkipped('Mongodb Integration only enabled on 7+');
        }

        $this->client()->test_db->cars->insertMany(
            [
                [
                    'brand' => 'ford',
                ],
                [
                    'brand' => 'toyota',
                ],
            ]
        );
    }

    protected function ddTearDown()
    {
        parent::ddTearDown();
        $this->client()->test_db->cars->drop();
    }

    public function testFilterNormalizationRegex()
    {
        $traces = $this->isolateTracer(function () {
            $this->client()->test_db->cars->find(['brand' => new \MongoDB\BSON\Regex('^for.*', 'i')]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mongodb.cmd', 'mongodb', 'mongodb', 'find test_db cars {"brand":"?"}')
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'mongodb.query' => '{"brand":"?"}',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                ]),
        ]);
    }

    public function testFilterAggregation()
    {
        $traces = $this->isolateTracer(function () {
            $this->client()->test_db->cars->aggregate(
                [
                    ['$group' => ['_id' => '$brand', 'count' => ['$sum' => 1]]],
                    ['$sort' => ['count' => -1]],
                    ['$limit' => 5],
                ]
            );
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'mongodb.cmd',
                'mongodb',
                'mongodb',
                'aggregate test_db cars [{"$group":{"_id":"?","count":{"$sum":"?"}}},{"$sort":{"count":"?"}},{"$limit":"?"}]'
            )->withExactTags([
                'mongodb.db' => self::DATABASE,
                'mongodb.collection' => 'cars',
                'mongodb.query' => '[{"$group":{"_id":"?","count":{"$sum":"?"}}},{"$sort":{"count":"?"}},{"$limit":"?"}]',
                'span.kind' => 'client',
                'out.host' => self::HOST,
                'out.port' => self::PORT,
            ]),
        ]);
    }

    public function testException()
    {
        $traces = $this->isolateTracer(function () {
            try {
                $this->client()->test_db->cars->find(20);
            } catch (Exception $e) {
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mongodb.cmd', 'mongodb', 'mongodb', 'find test_db cars "?"')
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'mongodb.query' => '"?"',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                ])->setError('MongoDB\Exception\InvalidArgumentException')
                ->withExistingTagsNames(['error.msg', 'error.stack']),
        ]);
    }

    /**
     * @dataProvider dataProviderMethodsWithFilter
     */
    public function testMethodsWithFilter($method, $args)
    {
        $traces = $this->isolateTracer(
            function () use ($method, $args) {
                if (\count($args) === 1) {
                    $this->client()->test_db->cars->$method(['brand' => 'ferrari'], $args[0]);
                } else {
                    $this->client()->test_db->cars->$method(['brand' => 'ferrari']);
                }
            }
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mongodb.cmd', 'mongodb', 'mongodb', "$method test_db cars {\"brand\":\"?\"}")
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'mongodb.query' => '{"brand":"?"}',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                ]),
        ]);
    }

    public function dataProviderMethodsWithFilter()
    {
        return [
            ['count', []],
            ['countDocuments', []],
            ['deleteMany', []],
            ['deleteOne', []],
            ['find', []],
            ['findOne', []],
            ['findOneAndDelete', []],
            ['findOneAndReplace', [[]]],
            ['findOneAndUpdate', [['$set' => ['brand' => 'chevy']]]],
            ['replaceOne', [[]]],
            ['updateOne', [['$set' => ['brand' => 'chevy']]]],
            ['updateMany', [['$set' => ['brand' => 'chevy']]]],
        ];
    }

    /**
     * @dataProvider dataProviderMethodsNoArgs
     */
    public function testMethodsNoArgs($method, $args)
    {
        $traces = $this->isolateTracer(
            function () use ($method, $args) {
                if (\count($args) === 1) {
                    $this->client()->test_db->cars->$method($args[0]);
                } elseif (\count($args) === 2) {
                    $this->client()->test_db->cars->$method($args[0], $args[1]);
                } elseif (\count($args) === 3) {
                    $this->client()->test_db->cars->$method($args[0], $args[1], $args[2]);
                } else {
                    $this->client()->test_db->cars->$method();
                }
            }
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mongodb.cmd', 'mongodb', 'mongodb', "$method test_db cars")
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                ]),
        ]);
    }

    public function dataProviderMethodsNoArgs()
    {
        return [
            ['drop', []],
            ['dropIndexes', []],
            ['estimatedDocumentCount', []],
            ['insertMany', [[['brand' => 'chevy'], ['brand' => 'ferrari']]]],
            ['insertOne', [['brand' => 'chevy']]],
            ['listIndexes', []],
            [
                'mapReduce', [
                    /* map */
                    new \MongoDB\BSON\Javascript('function() { emit(this.state, this.pop); }'),
                    /* reduce */
                    new \MongoDB\BSON\Javascript('function(key, values) { return Array.sum(values) }'),
                    /* out */
                    ['inline' => 1],
                ]
            ],
        ];
    }

    /**
     * @dataProvider dataProviderQueryNormalization
     */
    public function testQueryNormalization($query, $expected)
    {
        $traces = $this->isolateTracer(function () use ($query) {
            $this->client()->test_db->cars->find($query);
        });

        if (null === $expected) {
            $this->assertArrayNotHasKey('mongodb.query', $traces[0][0]['meta']);
        } else {
            $this->assertSame($expected, $traces[0][0]['meta']['mongodb.query']);
        }
    }

    public function dataProviderQueryNormalization()
    {
        // Scenarios inspired by https://github.com/DataDog/dd-trace-py/blob/30ab370c06e957e0c5094687152f3040f07e9e4e/tests/contrib/pymongo/test.py#L26-L62
        return [
            [['team' => 'leafs'], '{"team":"?"}'],
            [['age' => ['$gt' => 20]], '{"age":{"$gt":"?"}}'],
            [['_id' => ['$in' => [1, 2, 3]]], '{"_id":{"$in":"?"}}'],
            [['_id' => ['$nin' => [1, 2, 3]]], '{"_id":{"$nin":"?"}}'],
            [
                [
                    'status' => 'A',
                    '$or' => [
                        ['age' => ['$gt' => 20]],
                        ['type' => 1],
                    ],
                ],
                '{"status":"?","$or":[{"age":{"$gt":"?"}},{"type":"?"}]}',
            ],
            [
                [
                    ['team' => 'leafs'],
                    ['server' => 'apache'],
                ],
                '[{"team":"?"},{"server":"?"}]',
            ]
        ];
    }

    private function client()
    {
        return new Client(
            'mongodb://' . self::HOST . ':' . self::PORT,
            [
                'username' => self::USER,
                'password' => self::PASSWORD,
            ]
        );
    }
}
