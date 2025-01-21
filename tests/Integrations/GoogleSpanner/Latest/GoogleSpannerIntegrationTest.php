<?php

namespace DDTrace\Tests\Integrations\GoogleSpanner\Latest;


use DDTrace\Tests\Common\IntegrationTestCase;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Transaction;

class GoogleSpannerIntegrationTest extends IntegrationTestCase
{
    public static function hasInstance($spanner, $instance_name)
    {
        $instances = $spanner->instances();
        foreach($instances as $key => $value) {
            if ($value->name() == $instance_name) {
                return true;
            }
        }
        return false;
    }

    public static function getBaseConfig($spanner) {
        $configs = $spanner->instanceConfigurations();
        foreach ($configs as $config) {
            return $config;
        }
        return null;
    }

    public static function spannerSetup()
    {
        putenv('SPANNER_EMULATOR_HOST=googlespanner_integration:9010');
        putenv('GOOGLE_APPLICATION_CREDENTIALS=./tests/Integrations/GoogleSpanner/Latest/dummy_credentials.json');
        $projectId = 'emulator-project';
        $spanner = new SpannerClient([
            'projectId' => $projectId
        ]);

        if (!self::hasInstance($spanner, 'projects/emulator-project/instances/test-instance')) {
            $baseInstanceConfig = self::getBaseConfig($spanner);

            $spanner->createInstance($baseInstanceConfig, 'test-instance', [
                'config' => 'emulator-config',
                'displayName' => 'Test Instance',
                'nodeCount' => 1
            ]);

            $instance = $spanner->instance('test-instance');
            $instance->createDatabase('test-database', [
                'statements' => [
                    'CREATE TABLE users ( UserId INT64 NOT NULL, FirstName STRING(1024), LastName STRING(1024), UserInfo BYTES(MAX) ) PRIMARY KEY (UserId)'
                ]
            ]);
        }
        return $spanner;
    }

    public function testInstance()
    {
        $spanner = self::spannerSetup();
        $this->isolateTracerSnapshot(fn: function () use ($spanner){
            $instance = $spanner->instance('test-instance');
        });
    }

    public function testDatabase()
    {
        $spanner = self::spannerSetup();
        $instance = $spanner->instance('test-instance');
        $this->isolateTracerSnapshot(fn: function () use ($instance){
            $db = $instance->database('test-database');
        });
    }

    public function testQuery()
    {
        $spanner = self::spannerSetup();
        $db = $spanner->connect('test-instance', 'test-database');
        $this->isolateTracerSnapshot(fn: function () use ($db){
            $db->execute('SELECT * FROM users');
        });
    }

    public function testTransaction()
    {
        $spanner = self::spannerSetup();
        $db = $spanner->connect('test-instance', 'test-database');
        $this->isolateTracerSnapshot(fn: function () use ($db){
            $db->runTransaction(function (Transaction $t) {
                $rowCount = $t->executeUpdate('DELETE FROM Users where UserId = 3');
                $rowCount = $t->executeUpdate("INSERT Users (UserId, FirstName, LastName) VALUES (3, 'Dylan', 'Shaw')");
                $t->commit();
            });
        });
    }
}
