<?php

namespace DDTrace\Tests\Integrations\Mongo;

use MongoId;
use MongoCode;
use MongoClient;
use MongoCollection;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Configuration;

final class MongoTest extends IntegrationTestCase
{
    const HOST = 'mongodb_integration';
    const PORT = '27017';
    const USER = 'test';
    const PASSWORD = 'test';
    const DATABASE = 'admin';

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        IntegrationsLoader::load();
    }

    protected function tearDown()
    {
        $this->clearDatabase();
        parent::tearDown();
    }

    // MongoClient tests
    public function testClientConnectAndClose()
    {
        $traces = $this->isolateTracer(function () {
            $mongo = self::getClient();
            $mongo->close(true);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoClient.__construct', 'mongo', 'mongodb', '__construct')
                ->withExactTags([
                    'mongodb.server' => 'mongodb://mongodb_integration:27017',
                    'mongodb.db' => self::DATABASE,
                ]),
        ]);
    }

    public function testSecretsAreSanitizedFromDsnString()
    {
        $traces = $this->isolateTracer(function () {
            $mongo = new MongoClient(
                sprintf(
                    'mongodb://%s:%s@%s:%s',
                    self::USER,
                    self::PASSWORD,
                    self::HOST,
                    self::PORT
                ),
                [
                    'db' => self::DATABASE,
                ]
            );
            $mongo->close(true);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoClient.__construct', 'mongo', 'mongodb', '__construct')
                ->withExactTags([
                    'mongodb.server' => 'mongodb://?:?@mongodb_integration:27017',
                    'mongodb.db' => self::DATABASE,
                ]),
        ]);
    }

    public function testDatabaseNameExtractedFromDsnString()
    {
        $traces = $this->isolateTracer(function () {
            $mongo = new MongoClient(
                sprintf(
                    'mongodb://%s:%s/%s',
                    self::HOST,
                    self::PORT,
                    self::DATABASE
                ),
                [
                    'username' => self::USER,
                    'password' => self::PASSWORD,
                ]
            );
            $mongo->close(true);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoClient.__construct', 'mongo', 'mongodb', '__construct')
                ->withExactTags([
                    'mongodb.server' => 'mongodb://mongodb_integration:27017/' . self::DATABASE,
                    'mongodb.db' => self::DATABASE,
                ]),
        ]);
    }

    public function testClientSelectCollection()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->selectCollection(self::DATABASE, 'foo_collection');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoClient.selectCollection', 'mongo', 'mongodb', 'selectCollection')
                ->withExactTags([
                    'mongodb.collection' => 'foo_collection',
                    'mongodb.db' => self::DATABASE,
                ]),
        ]);
    }

    public function testSelectDB()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->selectDB(self::DATABASE);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoClient.selectDB', 'mongo', 'mongodb', 'selectDB')
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                ]),
        ]);
    }

    public function testClientSetReadPreference()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->setReadPreference(MongoClient::RP_NEAREST);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoClient.setReadPreference', 'mongo', 'mongodb', 'setReadPreference')
                ->withExactTags([
                    'mongodb.read_preference' => MongoClient::RP_NEAREST,
                ]),
        ]);
    }

    public function testClientSetWriteConcern()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->setWriteConcern('majority');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoClient.setWriteConcern', 'mongo', 'mongodb', 'setWriteConcern'),
        ]);
    }

    /**
     * @dataProvider clientMethods
     */
    public function testClientMethods($method)
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) use ($method) {
            $mongo->{$method}();
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoClient.' . $method, 'mongo', 'mongodb', $method),
        ]);
    }

    public function clientMethods()
    {
        return [
            ['getHosts'],
            ['getReadPreference'],
            ['getWriteConcern'],
            ['listDBs'],
        ];
    }

    // MongoDB tests
    public function testCommandWithQueryAndTimeout()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->command([
                'distinct' => 'people',
                'key' => 'age',
                'query' => ['age' => ['$gte' => 18]]
            ], ['socketTimeoutMS' => 500]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.command', 'mongo', 'mongodb', 'command')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'mongodb.query' => '{"age":{"$gte":18}}',
                    'mongodb.timeout' => '500',
                ]),
        ]);
    }

    public function testCreateDBRef()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->createDBRef('foo_collection', new MongoId('47cc67093475061e3d9536d2'));
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.createDBRef', 'mongo', 'mongodb', 'createDBRef')
                ->withExactTags([
                    'mongodb.collection' => 'foo_collection',
                    'mongodb.bson.id' => '47cc67093475061e3d9536d2',
                ]),
        ]);
    }

    public function testCreateCollection()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->createCollection('foo_collection');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.createCollection', 'mongo', 'mongodb', 'createCollection')
                ->withExactTags([
                    'mongodb.collection' => 'foo_collection',
                ]),
        ]);
    }

    public function testExecute()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->execute('"foo";');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.execute', 'mongo', 'mongodb', 'execute'),
        ]);
    }

    public function testGetDBRef()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->getDBRef([
                '$ref' => 'foo_collection',
                '$id' => new MongoId('47cc67093475061e3d9536d2'),
            ]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.getDBRef', 'mongo', 'mongodb', 'getDBRef')
                ->withExactTags([
                    'mongodb.collection' => 'foo_collection',
                ]),
        ]);
    }

    public function testSelectCollection()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->selectCollection('foo_collection');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.selectCollection', 'mongo', 'mongodb', 'selectCollection')
                ->withExactTags([
                    'mongodb.collection' => 'foo_collection',
                ]),
        ]);
    }

    public function testSetProfilingLevel()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->setProfilingLevel(2);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.setProfilingLevel', 'mongo', 'mongodb', 'setProfilingLevel')
                ->withExactTags([
                    'mongodb.profiling_level' => '2',
                ]),
        ]);
    }

    public function testSetReadPreference()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->setReadPreference(MongoClient::RP_NEAREST);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.setReadPreference', 'mongo', 'mongodb', 'setReadPreference')
                ->withExactTags([
                    'mongodb.read_preference' => MongoClient::RP_NEAREST,
                ]),
        ]);
    }

    public function testSetWriteConcern()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            $mongo->{self::DATABASE}->setWriteConcern('foo');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.setWriteConcern', 'mongo', 'mongodb', 'setWriteConcern'),
        ]);
    }

    /**
     * @dataProvider dbMethods
     */
    public function testDBWithDefaultTags($method)
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) use ($method) {
            $mongo->{self::DATABASE}->{$method}();
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoDB.' . $method, 'mongo', 'mongodb', $method),
        ]);
    }

    public function dbMethods()
    {
        return [
            ['drop'],
            ['getCollectionInfo'],
            ['getCollectionNames'],
            ['getGridFS'],
            ['getProfilingLevel'],
            ['getReadPreference'],
            ['getWriteConcern'],
            ['lastError'],
            ['listCollections'],
            ['repair'],
        ];
    }

    // MongoCollection tests
    public function testCollection()
    {
        $traces = $this->isolateClient(function (MongoClient $mongo) {
            new MongoCollection($mongo->{self::DATABASE}, 'foo_collection');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.__construct', 'mongo', 'mongodb', '__construct')
                ->withExactTags([
                    'mongodb.db' => self::DATABASE,
                    'mongodb.collection' => 'foo_collection',
                ]),
        ]);
    }

    public function testCollectionAggregate()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->aggregate([], ['explain' => true]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.aggregate', 'mongo', 'mongodb', 'aggregate'),
        ]);
    }

    public function testCollectionAggregateCursor()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->aggregateCursor([], ['explain' => true]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.aggregateCursor', 'mongo', 'mongodb', 'aggregateCursor'),
        ]);
    }

    public function testCollectionBatchInsert()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->batchInsert([
                ['title' => 'Foo'],
                ['title' => 'Bar'],
            ]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.batchInsert', 'mongo', 'mongodb', 'batchInsert'),
        ]);
    }

    public function testCollectionCount()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->count(['title' => 'Foo']);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.count', 'mongo', 'mongodb', 'count')
                ->withExactTags([
                    'mongodb.query' => '{"title":"Foo"}',
                ]),
        ]);
    }

    public function testCollectionCreateDBRef()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->createDBRef(new MongoId('47cc67093475061e3d9536d2'));
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.createDBRef', 'mongo', 'mongodb', 'createDBRef')
                ->withExactTags([
                    'mongodb.bson.id' => '47cc67093475061e3d9536d2',
                    'mongodb.collection' => 'foo_collection',
                ]),
        ]);
    }

    public function testCollectionCreateIndex()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->createIndex(['foo' => 1]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.createIndex', 'mongo', 'mongodb', 'createIndex'),
        ]);
    }

    public function testCollectionDeleteIndex()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->deleteIndex('foo');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.deleteIndex', 'mongo', 'mongodb', 'deleteIndex'),
        ]);
    }

    public function testCollectionDistinct()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->distinct('foo', ['foo' => 'bar']);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.distinct', 'mongo', 'mongodb', 'distinct')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'mongodb.query' => '{"foo":"bar"}',
                ]),
        ]);
    }

    public function testCollectionFind()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->find(['foo' => 'bar']);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.find', 'mongo', 'mongodb', 'find')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'mongodb.query' => '{"foo":"bar"}',
                ]),
        ]);
    }

    public function testCollectionFindAndModify()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->findAndModify(
                ['foo' => 'bar'],
                [],
                [],
                ['update' => true]
            );
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.findAndModify', 'mongo', 'mongodb', 'findAndModify')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'mongodb.query' => '{"foo":"bar"}',
                ]),
        ]);
    }

    public function testCollectionFindOne()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->findOne(['foo' => 'bar']);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.findOne', 'mongo', 'mongodb', 'findOne')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'mongodb.query' => '{"foo":"bar"}',
                ]),
        ]);
    }

    public function testCollectionGetDBRef()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->getDBRef([
                '$ref' => 'foo_collection',
                '$id' => '47cc67093475061e3d9536d2',
            ]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.getDBRef', 'mongo', 'mongodb', 'getDBRef')
                ->withExactTags([
                    'mongodb.bson.id' => '47cc67093475061e3d9536d2',
                    'mongodb.collection' => 'foo_collection',
                ]),
        ]);
    }

    public function testCollectionGroup()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->group(
                [],
                ['foo' => ''],
                new MongoCode('function (obj, prev) {}')
            );
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.group', 'mongo', 'mongodb', 'group'),
        ]);
    }

    public function testCollectionInsert()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->insert(['foo' => 'bar']);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.insert', 'mongo', 'mongodb', 'insert'),
        ]);
    }

    public function testCollectionParallelCollectionScan()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->parallelCollectionScan(2);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'MongoCollection.parallelCollectionScan',
                'mongo',
                'mongodb',
                'parallelCollectionScan'
            ),
        ]);
    }

    public function testCollectionRemove()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->remove(['foo' => 'bar']);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.remove', 'mongo', 'mongodb', 'remove')
                ->withExactTags([
                    'mongodb.query' => '{"foo":"bar"}',
                ]),
        ]);
    }

    public function testCollectionSave()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->save(['foo' => 'bar']);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.save', 'mongo', 'mongodb', 'save'),
        ]);
    }

    public function testCollectionSetReadPreference()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->setReadPreference(MongoClient::RP_NEAREST);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.setReadPreference', 'mongo', 'mongodb', 'setReadPreference')
                ->withExactTags([
                    'mongodb.read_preference' => MongoClient::RP_NEAREST,
                ]),
        ]);
    }

    public function testCollectionSetWriteConcern()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->setWriteConcern('majority');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.setWriteConcern', 'mongo', 'mongodb', 'setWriteConcern'),
        ]);
    }

    public function testCollectionUpdate()
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->update(
                ['foo' => 'bar'],
                []
            );
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.update', 'mongo', 'mongodb', 'update')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'mongodb.query' => '{"foo":"bar"}',
                ]),
        ]);
    }

    /**
     * @dataProvider collectionMethods
     */
    public function testCollectionMethods($method)
    {
        $traces = $this->isolateCollection(function (MongoCollection $collection) use ($method) {
            $collection->{$method}();
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('MongoCollection.' . $method, 'mongo', 'mongodb', $method),
        ]);
    }


    public function testLimitedTracer()
    {
        putenv('DD_TRACE_SPANS_LIMIT=0');
        dd_trace_internal_fn('ddtrace_reload_config');

        $traces = $this->isolateCollection(function (MongoCollection $collection) {
            $collection->distinct('foo', ['foo' => 'bar']);
            $collection->update(
                ['foo' => 'bar'],
                []
            );
            $collection->setWriteConcern('majority');
            $collection->parallelCollectionScan(2);
            $collection->aggregate([], ['explain' => true]);
        });

        putenv('DD_TRACE_SPANS_LIMIT');
        dd_trace_internal_fn('ddtrace_reload_config');

        $this->assertEmpty($traces);
    }

    public function collectionMethods()
    {
        return [
            ['deleteIndexes'],
            ['drop'],
            ['getIndexInfo'],
            ['getName'],
            ['getReadPreference'],
            ['getWriteConcern'],
            ['validate'],
        ];
    }

    private function isolateClient(\Closure $callback)
    {
        $mongo = self::getClient();
        $traces = $this->isolateTracer(function () use ($mongo, $callback) {
            $callback($mongo);
        });
        $mongo->close(true);
        return $traces;
    }

    private function isolateCollection(\Closure $callback)
    {
        $mongo = self::getClient();
        $collection = $mongo->{self::DATABASE}->createCollection('foo_collection');
        $traces = $this->isolateTracer(function () use ($collection, $callback) {
            $callback($collection);
        });
        $collection->drop();
        $mongo->close(true);
        return $traces;
    }

    private static function getClient()
    {
        return new MongoClient(
            'mongodb://' . self::HOST . ':' . self::PORT,
            [
                'username' => self::USER,
                'password' => self::PASSWORD,
                'db' => self::DATABASE,
            ]
        );
    }

    private function clearDatabase()
    {
        $this->isolateTracer(function () {
            $mongo = self::getClient();
            $mongo->{self::DATABASE}->drop();
            $mongo->close();
        });
    }
}
