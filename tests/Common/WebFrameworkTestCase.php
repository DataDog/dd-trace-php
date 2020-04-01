<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tests\Frameworks\Util\CommonScenariosDataProviderTrait;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Tests\WebServer;

/**
 * A basic class to be extended when testing web frameworks integrations.
 */
abstract class WebFrameworkTestCase extends IntegrationTestCase
{
    use CommonScenariosDataProviderTrait;

    const FLUSH_INTERVAL_MS = 333;

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
            'DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS' => 1,
            // Short flush interval by default or our tests will take all day
            'DD_TRACE_AGENT_FLUSH_INTERVAL' => static::FLUSH_INTERVAL_MS,
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
            self::$appServer->mergeEnvs(static::getEnvs());
            self::$appServer->mergeInis(static::getInis());
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
     * @return mixed|null
     */
    protected function call(RequestSpec $spec)
    {
        $response = $this->sendRequest(
            $spec->getMethod(),
            'http://localhost:' . self::PORT . $spec->getPath(),
            $spec->getHeaders()
        );
        return $response;
    }

    /**
     * Sends an actual requests to the test web server.
     *
     * @param string $method
     * @param string $url
     * @param string[] $headers
     * @return mixed|null
     */
    protected function sendRequest($method, $url, $headers = [])
    {
        for ($i = 0; $i < 10; ++$i) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($headers) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            $response = curl_exec($ch);
            if ($response === false && $i < 9) {
                \curl_close($ch);
                // sleep for 100 milliseconds before trying again
                \usleep(100 * 1000);
            } else {
                break;
            }
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
