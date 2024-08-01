<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use datadog\appsec\AppsecStatus;

/**
 * A basic class to be extended when testing web frameworks integrations.
 */
abstract class AppsecTestCase extends WebFrameworkTestCase
{
    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APPSEC_MOCK_ENABLED' => true
        ]);
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }

    protected function databaseDump()
    {
        return "";
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $dump = $this->databaseDump();
        if (!empty($dump)) {
            $this->connection()->exec($dump);
        }
        AppsecStatus::getInstance()->setDefaults();
    }

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        AppsecStatus::getInstance()->init();
    }

    public static function ddTearDownAfterClass()
    {
        AppsecStatus::getInstance()->setDefaults();
        parent::ddTearDownAfterClass();
    }

}
