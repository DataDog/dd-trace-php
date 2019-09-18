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

    /**
     * Returns the application index.php file full path.
     *
     * @return string|null
     */
    protected static function getAppIndexScript()
    {
        return null;
    }

    /**
     * Get additional envs to be set in the web server.
     * @return array
     */
    protected static function getEnvs()
    {
        $envs = [
            'DD_TEST_INTEGRATION' => 'true',
            'DD_TRACE_ENCODER' => 'json',
            'DD_TRACE_AGENT_TIMEOUT' => '10000',
            'DD_TRACE_AGENT_CONNECT_TIMEOUT' => '10000',
            'DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED' => 'true',
        ];

        if (!self::isSandboxed()) {
            $envs['DD_TRACE_SANDBOX_ENABLED'] = 'false';
        }

        return $envs;
    }

    /**
     * Get additional inis to be set in the web server.
     * @return array
     */
    protected static function getInis()
    {
        return [
            'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
            // The following values should be made configurable from the outside. I could not get env XDEBUG_CONFIG
            // to work setting it both in docker-compose.yml and in `getEnvs()` above, but that should be the best
            // option.
            'xdebug.remote_enable' => 1,
            'xdebug.remote_host' => 'host.docker.internal',
            'xdebug.remote_autostart' => 1,
        ];
    }

    /**
     * Sets up a web server.
     */
    protected static function setUpWebServer()
    {
        $rootPath = static::getAppIndexScript();
        if ($rootPath) {
            self::$appServer = new WebServer($rootPath, '0.0.0.0', self::PORT);
            self::$appServer->setEnvs(static::getEnvs());
            self::$appServer->setInis(static::getInis());
            self::$appServer->start();
        }
    }

    /**
     * Tear down the  web server.
     */
    private static function tearDownWebServer()
    {
        if (self::$appServer) {
            self::$appServer->stop();
        }
    }

    /**
     * Executed a call to the test web server.
     *
     * @param RequestSpec $spec
     * @param bool $logResponseData
     * @return mixed|null
     */
    protected function call(RequestSpec $spec, $logResponseData = false)
    {
        $url = 'http://localhost:' . self::PORT . $spec->getPath();
        if ($spec instanceof GetSpec) {
            return $this->sendRequest('GET', $url, $logResponseData);
        }

        $this->fail('Unhandled request spec type');
    }

    /**
     * Sends an actual requests to the test web server.
     *
     * @param string $method
     * @param string $url
     * @param bool $logResponseData
     * @return mixed|null
     */
    protected function sendRequest($method, $url, $logResponseData = false)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $response = curl_exec($ch);

        if ($logResponseData) {
            error_log("Response: " . print_r($response, 1));
            error_log("Response code: " . print_r(curl_getinfo($ch, CURLINFO_HTTP_CODE), 1));
        }

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
