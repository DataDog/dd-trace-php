<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tests\Frameworks\Util\CommonScenariosDataProviderTrait;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Tests\WebServer;

/**
 * A basic class to be extended when testing web frameworks integrations.
 */
abstract class WebFrameworkTestCase extends IntegrationTestCase
{
    use CommonScenariosDataProviderTrait;

    const PORT = 9999;

    private $dumpFilePath = __DIR__ . '/../../.request_dumper_data/dump.json';

    /**
     * @var WebServer|null
     */
    private static $appServer;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        static::setUpWebServer();
    }

    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        static::tearDownWebServer();
    }

    protected static function getAppIndexScript()
    {
        return null;
    }

    protected static function getEnvs()
    {
        return [];
    }

    protected function setUp()
    {
        parent::setUp();
        if (file_exists($this->dumpFilePath)) {
            unlink($this->dumpFilePath);
        }
    }

    protected static function setUpWebServer()
    {
        $rootPath = static::getAppIndexScript();
        if ($rootPath) {
            self::$appServer = new WebServer($rootPath, '0.0.0.0', self::PORT);
            self::$appServer->setEnvs(static::getEnvs());
            self::$appServer->start();
        }
    }

    private static function tearDownWebServer()
    {
        if (self::$appServer) {
            self::$appServer->stop();
        }
    }

    protected function call(RequestSpec $spec)
    {
        $url = 'http://127.0.0.1:' . self::PORT . $spec->getPath();
        if ($spec instanceof GetSpec) {
            $response = $this->sendRequest('GET', $url);
            return $response;
        } else {
            $this->fail('Unhandled request spec type');
        }
    }

    protected function sendRequest($method, $url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        if ($response === false) {
            $message = sprintf(
                'Failed web request to \'%s\': %s, error code %s',
                $url,
                curl_error($ch),
                curl_errno($ch)
            );
            curl_close($ch);
            $this->fail($message);
            return null;
        }

        curl_close($ch);

        return $response;
    }
}
