<?php

namespace DDTrace\Tests\Integrations\Mongo;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use Exception;
use MongoDB\Client;
use stdClass;

class AQuery
{
    public $brand;

    public function __construct($brand = 'ferrari')
    {
        $this->brand = $brand;
    }
}

class AnObject
{
}

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
            $this->markTestSkipped('Mongodb Integration only enabled on 7+');
        }

        $this->client()->test_db->cars->drop();
        $this->client()->test_db->my_collection->drop();

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

    public function testFilterNormalizationRegex()
    {
        $expected = [
            SpanAssertion::build('mongodb.cmd', 'mongodb', 'mongodb', 'find test_db cars {"brand":"?"}')
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'mongodb.query' => '{"brand":"?"}',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                    Tag::COMPONENT => 'mongodb',
                    'db.system' => 'mongodb',
                ])->withChildren([
                    SpanAssertion::exists('mongodb.driver.cmd')
                ]),
        ];

        // As array
        $traces = $this->isolateTracer(function () {
            $this->client()->test_db->cars->find(['brand' => new \MongoDB\BSON\Regex('^ford$', 'i')]);
        });
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(function () {
            $query = new \stdClass();
            $query->brand = new \MongoDB\BSON\Regex('^ford$', 'i');
            $this->client()->test_db->cars->find($query);
        });
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(function () {
            $query = new AQuery(new \MongoDB\BSON\Regex('^ford$', 'i'));
            $this->client()->test_db->cars->find($query);
        });
        $this->assertFlameGraph($traces, $expected);
    }

    public function testFilterAggregation()
    {
        $expected = [
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
                Tag::COMPONENT => 'mongodb',
                'db.system' => 'mongodb',
            ])->withChildren([
                SpanAssertion::exists('mongodb.driver.cmd')
            ]),
        ];

        $pipeline = [
            ['$group' => ['_id' => '$brand', 'count' => ['$sum' => 1]]],
            ['$sort' => ['count' => -1]],
            ['$limit' => 5],
        ];

        // As array
        $traces = $this->isolateTracer(function () use ($pipeline) {
            $this->client()->test_db->cars->aggregate($pipeline);
        });
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(function () use ($pipeline) {
            $this->client()->test_db->cars->aggregate(
                \array_map('\DDTrace\Tests\Integrations\Mongo\MongoDBTest::arrayToStdClass', $pipeline)
            );
        });
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(function () use ($pipeline) {
            $this->client()->test_db->cars->aggregate(
                \array_map('\DDTrace\Tests\Integrations\Mongo\MongoDBTest::arrayToObject', $pipeline)
            );
        });
        $this->assertFlameGraph($traces, $expected);
    }

    public function testCollectionBulkWrite()
    {
        $traces = $this->isolateTracer(function () {
            // These are actually expected to be array, stdClass and objects are not supported.
            $this->client()->test_db->cars->bulkWrite([
                ['deleteMany' => [['brand' => 'ferrari'], []]],
                ['insertOne'  => [['brand' => 'maserati']]],
            ]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'mongodb.cmd',
                'mongodb',
                'mongodb',
                'bulkWrite test_db cars'
            )->withExactTags([
                'mongodb.db' => self::DATABASE,
                'mongodb.collection' => 'cars',
                'span.kind' => 'client',
                'out.host' => self::HOST,
                'out.port' => self::PORT,
                Tag::COMPONENT => 'mongodb',
                'db.system' => 'mongodb',
            ])->withChildren([
                SpanAssertion::exists('mongodb.driver.cmd')
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
                    Tag::COMPONENT => 'mongodb',
                    'db.system' => 'mongodb',
                ])->setError('MongoDB\Exception\InvalidArgumentException')
                ->withExistingTagsNames([Tag::ERROR_MSG, 'error.stack']),
        ]);
    }

    /**
     * @dataProvider dataProviderMethodsWithFilter
     */
    public function testMethodsWithFilter($method, $args)
    {
        $expected = [
            SpanAssertion::build('mongodb.cmd', 'mongodb', 'mongodb', "$method test_db cars {\"brand\":\"?\"}")
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'mongodb.query' => '{"brand":"?"}',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                    Tag::COMPONENT => 'mongodb',
                    'db.system' => 'mongodb',
                ])->withChildren([
                    SpanAssertion::exists('mongodb.driver.cmd')
                ]),
        ];

        // As array
        $traces = $this->isolateTracer(
            function () use ($method, $args) {
                if (\count($args) === 1) {
                    $this->client()->test_db->cars->$method(['brand' => 'ferrari'], $args[0]);
                } else {
                    $this->client()->test_db->cars->$method(['brand' => 'ferrari']);
                }
            }
        );
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(
            function () use ($method, $args) {
                if (\count($args) === 1) {
                    $this->client()->test_db->cars->$method(
                        $this->arrayToStdClass(['brand' => 'ferrari']),
                        $this->arrayToStdClass($args[0])
                    );
                } else {
                    $this->client()->test_db->cars->$method($this->arrayToStdClass(['brand' => 'ferrari']));
                }
            }
        );
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(
            function () use ($method, $args) {
                if (\count($args) === 1) {
                    $this->client()->test_db->cars->$method(
                        $this->arrayToObject(['brand' => 'ferrari']),
                        $this->arrayToObject($args[0])
                    );
                } else {
                    $this->client()->test_db->cars->$method($this->arrayToObject(['brand' => 'ferrari']));
                }
            }
        );
        $this->assertFlameGraph($traces, $expected);
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
        $expected = [
            SpanAssertion::build('mongodb.cmd', 'mongodb', 'mongodb', "$method test_db cars")
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                    Tag::COMPONENT => 'mongodb',
                    'db.system' => 'mongodb',
                ])->withChildren([
                    SpanAssertion::exists('mongodb.driver.cmd')
                ]),
        ];

        // As array
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
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(
            function () use ($method, $args) {
                if (\count($args) === 1) {
                    $this->client()->test_db->cars->$method($this->arrayToStdClass($args[0]));
                } elseif (\count($args) === 2) {
                    $this->client()->test_db->cars->$method(
                        $this->arrayToStdClass($args[0]),
                        $this->arrayToStdClass($args[1])
                    );
                } elseif (\count($args) === 3) {
                    $this->client()->test_db->cars->$method(
                        $this->arrayToStdClass($args[0]),
                        $this->arrayToStdClass($args[1]),
                        $this->arrayToStdClass($args[2])
                    );
                } else {
                    $this->client()->test_db->cars->$method();
                }
            }
        );
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(
            function () use ($method, $args) {
                if (\count($args) === 1) {
                    $this->client()->test_db->cars->$method($this->arrayToObject($args[0]));
                } elseif (\count($args) === 2) {
                    $this->client()->test_db->cars->$method(
                        $this->arrayToObject($args[0]),
                        $this->arrayToObject($args[1])
                    );
                } elseif (\count($args) === 3) {
                    $this->client()->test_db->cars->$method(
                        $this->arrayToObject($args[0]),
                        $this->arrayToObject($args[1]),
                        $this->arrayToObject($args[2])
                    );
                } else {
                    $this->client()->test_db->cars->$method();
                }
            }
        );
        $this->assertFlameGraph($traces, $expected);
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
        ];
    }

    public function testMapReduce()
    {
        $expected = [
            SpanAssertion::build('mongodb.cmd', 'mongodb', 'mongodb', "mapReduce test_db cars")
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                    Tag::COMPONENT => 'mongodb',
                    'db.system' => 'mongodb',
                ])->withChildren([
                    SpanAssertion::exists('mongodb.driver.cmd')
                ]),
        ];

        // As array
        $traces = $this->isolateTracer(
            function () {
                $this->client()->test_db->cars->mapReduce(
                    new \MongoDB\BSON\Javascript('function() { emit(this.state, this.pop); }'),
                    new \MongoDB\BSON\Javascript('function(key, values) { return Array.sum(values) }'),
                    ['inline' => 1]
                );
            }
        );
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(
            function () {
                $this->client()->test_db->cars->mapReduce(
                    new \MongoDB\BSON\Javascript('function() { emit(this.state, this.pop); }'),
                    new \MongoDB\BSON\Javascript('function(key, values) { return Array.sum(values) }'),
                    $this->arrayToStdClass(['inline' => 1])
                );
            }
        );
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(
            function () {
                $this->client()->test_db->cars->mapReduce(
                    new \MongoDB\BSON\Javascript('function() { emit(this.state, this.pop); }'),
                    new \MongoDB\BSON\Javascript('function(key, values) { return Array.sum(values) }'),
                    $this->arrayToObject(['inline' => 1])
                );
            }
        );
        $this->assertFlameGraph($traces, $expected);
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
        $stdQuery = new stdClass();
        $stdQuery->field = 'some_value';

        $objQuery = new AQuery('ford');

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
            ],
            [$stdQuery, '{"field":"?"}'],
            [$objQuery, '{"brand":"?"}'],
        ];
    }

    public function testManagerExecuteQuery()
    {
        $expected = [
            SpanAssertion::build('mongodb.driver.cmd', 'mongodb', 'mongodb', 'executeQuery test_db cars {"brand":"?"}')
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'cars',
                    'mongodb.query' => '{"brand":"?"}',
                    'span.kind' => 'client',
                    'out.host' => self::HOST,
                    'out.port' => self::PORT,
                    Tag::COMPONENT => 'mongodb',
                    'db.system' => 'mongodb',
                ]),
        ];

        // As array
        $traces = $this->isolateTracer(function () {
            $query = new \MongoDB\Driver\Query(['brand' => 'ferrari']);
            $this->manager()->executeQuery('test_db.cars', $query);
        });
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(function () {
            $query = new \MongoDB\Driver\Query($this->arrayToStdClass(['brand' => 'ferrari']));
            $this->manager()->executeQuery('test_db.cars', $query);
        });
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(function () {
            $query = new \MongoDB\Driver\Query($this->arrayToObject(['brand' => 'ferrari']));
            $this->manager()->executeQuery('test_db.cars', $query);
        });
        $this->assertFlameGraph($traces, $expected);
    }

    public function testManagerExecuteCommand()
    {
        $collectionName = 'my_collection_' . \rand(1, 10000);

        $expected = [
            SpanAssertion::build(
                'mongodb.driver.cmd',
                'mongodb',
                'mongodb',
                'executeCommand test_db ' . $collectionName . ' create'
            )->withExactTags([
                'mongodb.db' => self::DATABASE,
                'mongodb.collection' => $collectionName,
                'span.kind' => 'client',
                'out.host' => self::HOST,
                'out.port' => self::PORT,
                Tag::COMPONENT => 'mongodb',
                'db.system' => 'mongodb',
            ]),
        ];

        // As array
        $traces = $this->isolateTracer(function () use ($collectionName) {
            $command = new \MongoDB\Driver\Command(['create' => $collectionName]);
            $this->manager()->executeCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);
        $this->client()->test_db->$collectionName->drop();

        // As stdClass
        $traces = $this->isolateTracer(function () use ($collectionName) {
            $command = new \MongoDB\Driver\Command($this->arrayToStdClass(['create' => $collectionName]));
            $this->manager()->executeCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);
        $this->client()->test_db->$collectionName->drop();

        // As object
        $traces = $this->isolateTracer(function () use ($collectionName) {
            $command = new \MongoDB\Driver\Command($this->arrayToObject(['create' => $collectionName]));
            $this->manager()->executeCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);
        $this->client()->test_db->$collectionName->drop();
    }

    public function testManagerExecuteReadCommand()
    {
        $expected = [
            SpanAssertion::build(
                'mongodb.driver.cmd',
                'mongodb',
                'mongodb',
                'executeReadCommand test_db cars find'
            )->withExactTags([
                'mongodb.db' => self::DATABASE,
                'mongodb.collection' => 'cars',
                'span.kind' => 'client',
                'out.host' => self::HOST,
                'out.port' => self::PORT,
                Tag::COMPONENT => 'mongodb',
                'db.system' => 'mongodb',
            ]),
        ];

        // As array
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command(
                [
                    'find' => 'cars',
                    'filter' => ['brand' => 'ferrari'],
                ]
            );
            $this->manager()->executeReadCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command($this->arrayToStdClass(
                [
                    'find' => 'cars',
                    'filter' => $this->arrayToStdClass(['brand' => 'ferrari']),
                ]
            ));
            $this->manager()->executeReadCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command($this->arrayToObject(
                [
                    'find' => 'cars',
                    'filter' => $this->arrayToObject(['brand' => 'ferrari']),
                ]
            ));
            $this->manager()->executeReadCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);
    }

    public function testManagerExecuteWriteCommand()
    {
        $expected = [
            SpanAssertion::build(
                'mongodb.driver.cmd',
                'mongodb',
                'mongodb',
                'executeWriteCommand test_db cars insert'
            )->withExactTags([
                'mongodb.db' => self::DATABASE,
                'mongodb.collection' => 'cars',
                'span.kind' => 'client',
                'out.host' => self::HOST,
                'out.port' => self::PORT,
                Tag::COMPONENT => 'mongodb',
                'db.system' => 'mongodb',
            ]),
        ];

        // As array
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command(
                [
                    'insert' => 'cars',
                    'documents' => [['brand' => 'ferrari']],
                ]
            );
            $this->manager()->executeWriteCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command($this->arrayToStdClass(
                [
                    'insert' => 'cars',
                    'documents' => [['brand' => 'ferrari']],
                ]
            ));
            $this->manager()->executeWriteCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command($this->arrayToObject(
                [
                    'insert' => 'cars',
                    'documents' => [['brand' => 'ferrari']],
                ]
            ));
            $this->manager()->executeWriteCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);
    }

    public function testManagerExecuteReadWriteCommand()
    {
        $expected = [
            SpanAssertion::build(
                'mongodb.driver.cmd',
                'mongodb',
                'mongodb',
                'executeReadWriteCommand test_db cars insert'
            )->withExactTags([
                'mongodb.db' => self::DATABASE,
                'mongodb.collection' => 'cars',
                'span.kind' => 'client',
                'out.host' => self::HOST,
                'out.port' => self::PORT,
                Tag::COMPONENT => 'mongodb',
                'db.system' => 'mongodb',
            ])
        ];

        // As array
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command(
                [
                    'insert' => 'cars',
                    'documents' => [['brand' => 'ferrari']],
                ]
            );
            $this->manager()->executeReadWriteCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);

        // As stdClass
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command($this->arrayToStdClass(
                [
                    'insert' => 'cars',
                    'documents' => [['brand' => 'ferrari']],
                ]
            ));
            $this->manager()->executeReadWriteCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);

        // As object
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command($this->arrayToObject(
                [
                    'insert' => 'cars',
                    'documents' => [['brand' => 'ferrari']],
                ]
            ));
            $this->manager()->executeReadWriteCommand('test_db', $command);
        });
        $this->assertFlameGraph($traces, $expected);
    }

    public function testManagerExecuteBulkWrite()
    {
        $traces = $this->isolateTracer(function () {
            // These are actually expected to be array, stdClass and objects are not supported.
            $bulkWrite = new \MongoDB\Driver\BulkWrite();
            $bulkWrite->delete(['brand' => 'ferrari']);
            $bulkWrite->delete(['brand' => 'chevy']);
            $bulkWrite->insert(['brand' => 'ford']);
            $bulkWrite->insert(['brand' => 'maserati']);
            $bulkWrite->update(['brand' => 'jaguar'], ['brand' => 'gm']);
            $this->manager()->executeBulkWrite('test_db.cars', $bulkWrite);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'mongodb.driver.cmd',
                'mongodb',
                'mongodb',
                'executeBulkWrite test_db cars'
            )->withExactTags([
                'mongodb.db' => self::DATABASE,
                'mongodb.collection' => 'cars',
                'span.kind' => 'client',
                'out.host' => self::HOST,
                'out.port' => self::PORT,
                'mongodb.deletes.0.filter' => '{"brand":"?"}',
                'mongodb.deletes.1.filter' => '{"brand":"?"}',
                'mongodb.updates.0.filter' => '{"brand":"?"}',
                'mongodb.insertsCount' => 2,
                Tag::COMPONENT => 'mongodb',
                'db.system' => 'mongodb',
            ]),
        ]);
    }

    public function testManagerFailure()
    {
        $traces = $this->isolateTracer(function () {
            $command = new \MongoDB\Driver\Command(
                [
                    'insert' => [], // this should be a collection instead
                ]
            );
            try {
                $this->manager()->executeWriteCommand('test_db', $command);
            } catch (\MongoDB\Driver\Exception\CommandException $e) {
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'mongodb.driver.cmd',
                'mongodb',
                'mongodb',
                'executeWriteCommand test_db insert'
            )->withExactTags([
                'mongodb.db' => self::DATABASE,
                'span.kind' => 'client',
                'out.host' => self::HOST,
                'out.port' => self::PORT,
                Tag::COMPONENT => 'mongodb',
                'db.system' => 'mongodb',
            ])->setError()
                ->withExistingTagsNames([Tag::ERROR_MSG, 'error.stack']),
        ]);
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

    private function manager()
    {
        return new \MongoDB\Driver\Manager(
            'mongodb://' . self::HOST . ':' . self::PORT,
            [
                'username' => self::USER,
                'password' => self::PASSWORD,
            ]
        );
    }

    private function arrayToStdClass(array $array)
    {
        $obj = new stdClass();
        if (self::isListArray($array)) {
            return \array_map('\DDTrace\Tests\Integrations\Mongo\MongoDBTest::' . __FUNCTION__, $array);
        }

        foreach ($array as $name => $value) {
            $obj->$name = $value;
        }
        return $obj;
    }

    private function arrayToObject(array $array)
    {
        $obj = new AnObject();
        if (self::isListArray($array)) {
            return \array_map('\DDTrace\Tests\Integrations\Mongo\MongoDBTest::' . __FUNCTION__, $array);
        }

        foreach ($array as $name => $value) {
            $obj->$name = $value;
        }
        return $obj;
    }
}
