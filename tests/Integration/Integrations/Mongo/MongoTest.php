<?php

namespace DDTrace\Tests\Integration\Integrations\Mongo;

use MongoClient;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Tests\Integration\Common\IntegrationTestCase;

final class MongoTest extends IntegrationTestCase
{
    const HOST = 'mongodb_integration';
    const PORT = '27017';
    const USER = 'test';
    const PASSWORD = 'test';
    const DATABASE = 'test';

    public static function setUpBeforeClass()
    {
        if (!extension_loaded('mongo')) {
            self::markTestSkipped('The mongo extension is required to run the MongoDB tests.');
        }
        parent::setUpBeforeClass();
        MongoIntegration::load();
    }

    protected function tearDown()
    {
        $this->clearDatabase();
        parent::tearDown();
    }

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
                    'mongodb.db' => 'test',
                ]),
        ]);
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
