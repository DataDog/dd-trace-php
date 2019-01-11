<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tests\Frameworks\Util\CommonScenariosDataProviderTrait;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Tests\WebServer;
use Symfony\Component\Process\Process;

/**
 * A basic class to be extended when testing web frameworks integrations.
 */
abstract class WebFrameworkTestCase extends IntegrationTestCase
{
    use CommonScenariosDataProviderTrait;

    const PORT = 9999;

//    /**
//     * @var Process
//     */
//    private static $fakeAgent;

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

    protected static function getAppRootPath()
    {
        return null;
    }

    protected static function setUpWebServer()
    {
        $rootPath = static::getAppRootPath();
        error_log("Root path....$rootPath");
        if ($rootPath) {
            self::$appServer = new WebServer($rootPath, 'localhost', self::PORT);
            self::$appServer->start();
            error_log("Server started....");
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
        $url = 'localhost:' . self::PORT . $spec->getPath();
        if ($spec instanceof GetSpec) {
            error_log("Url: $url");
            $response = $this->sendRequest('GET', $url);
            error_log("Done: $url");
            // TODO: restore ....
            // $this->assertSame($spec->getStatusCode(), $response->getStatusCode());
        } else {
            $this->fail('Unhandled request spec type');
        }
    }

    protected function sendRequest($method, $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        if ($response === false) {
            $this->fail(sprintf(
                'Failed web request to \'%s\': %s, error code %s',
                $url,
                curl_error($ch),
                curl_errno($ch)
            ));
        }

        return $response;
    }
}
