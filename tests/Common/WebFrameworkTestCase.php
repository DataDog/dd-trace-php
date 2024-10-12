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

    // host and port for the testing framework
    const HOST = 'http://localhost';
    const HOST_WITH_CREDENTIALS = 'http://my_user:my_password@localhost';
    const PORT = 9999 - GLOBAL_PORT_OFFSET;

    const ERROR_LOG_NAME = 'phpunit_error.log';
    const COOKIE_JAR = 'cookies.txt';

    /**
     * @var WebServer|null
     */
    private static $appServer;
    protected $checkWebserverErrors = true;
    protected $cookiesFile;
    protected $maintainSession = false;

    protected function ddSetUp()
    {
        parent::ddSetUp();
    }

    protected function enableSession()
    {
        $this->maintainSession = true;
        $this->cookiesFile = realpath(dirname(static::getAppIndexScript())) . '/' . static::COOKIE_JAR;
        $f = @fopen($this->cookiesFile, "r+");
        if ($f !== false) {
            ftruncate($f, 0);
            fclose($f);
        }
    }

    protected function disableSession()
    {
        $this->maintainSession = false;
    }

    public static function ddSetUpBeforeClass()
    {
        $index = static::getAppIndexScript();
        if ($index) {
            ini_set('error_log', dirname($index) . '/' . static::ERROR_LOG_NAME);
        }
        parent::ddSetUpBeforeClass();
        static::setUpWebServer();
    }

    public static function ddTearDownAfterClass()
    {
        parent::ddTearDownAfterClass();
        static::tearDownWebServer();
    }

    protected function ddTearDown()
    {
        if (self::$appServer && $this->checkWebserverErrors && ($error = self::$appServer->checkErrors())) {
            $this->fail("Got error from webserver:\n$error");
        }
        parent::ddTearDown();
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

    protected static function getRoadrunnerVersion()
    {
        return null;
    }

    protected static function isSwoole()
    {
        return false;
    }

    protected static function isOctane()
    {
        return false;
    }

    protected static function isFrankenphp()
    {
        return false;
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
            'DD_AUTOLOAD_NO_COMPILE' => getenv('DD_AUTOLOAD_NO_COMPILE'),
            'DD_TRACE_DEBUG' => ini_get("datadog.trace.debug"),
            'DD_TRACE_EXEC_ENABLED' => 'false',
            'DD_TRACE_SHUTDOWN_TIMEOUT' => '666666', // Arbitrarily high value to avoid flakiness
            'DD_TRACE_AGENT_RETRIES' => '3',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
        ];

        return $envs;
    }

    /**
     * Get additional inis to be set in the web server.
     * @return array
     */
    protected static function getInis()
    {
        $enableOpcache = \extension_loaded("Zend OpCache");

        return [
            'datadog.trace.sources_path' => realpath(__DIR__ . '/../../src'),
            // The following values should be made configurable from the outside. I could not get env XDEBUG_CONFIG
            // to work setting it both in docker-compose.yml and in `getEnvs()` above, but that should be the best
            // option.
            'xdebug.remote_enable' => 1,
            'xdebug.remote_host' => 'host.docker.internal',
            'xdebug.remote_autostart' => 1,
        ] + ($enableOpcache ? ["zend_extension" => "opcache.so"] : []);
    }

    /**
     * Sets up a web server.
     */
    protected static function setUpWebServer(array $additionalEnvs = [], array $additionalInis = [])
    {
        $rootPath = static::getAppIndexScript();
        if ($rootPath) {
            self::$appServer = new WebServer($rootPath, '0.0.0.0', self::PORT);

            $envs = static::getEnvs();
            if (!empty($additionalEnvs)) {
                $envs = array_merge($envs, $additionalEnvs);
            }
            self::$appServer->mergeEnvs($envs);

            $inis = static::getInis();
            if (!empty($additionalInis)) {
                $inis = array_merge($inis, $additionalInis);
            }
            self::$appServer->mergeInis($inis);
            if ($version = static::getRoadrunnerVersion()) {
                self::$appServer->setRoadrunner($version);
            }
            if (static::isOctane()) {
                self::$appServer->setOctane();
            }
            if (static::isSwoole()) {
                self::$appServer->setSwoole();
            }
            if (static::isFrankenphp()) {
                if (!ZEND_THREAD_SAFE) {
                    self::markTestSkipped("The Frankenphp testsuite needs ZTS");
                }
                self::$appServer->setFrankenphp();
            }
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

    protected function reloadAppServer()
    {
        if (\method_exists(self::$appServer, "reload")) {
            self::$appServer->reload();
        } else {
            $this->markTestSkipped("Webserver reload not supported");
        }
    }

    /**
     * Executed a call to the test web server.
     *
     * @param RequestSpec $spec
     * @return mixed|null
     */
    protected function call(RequestSpec $spec, $options = [])
    {
        $response = $this->sendRequest(
            $spec->getMethod(),
            self::HOST . $spec->getPath(),
            $spec->getHeaders(),
            $spec->getBody(),
            $options
        );
        return $response;
    }

    /**
     * Sends an actual requests to the test web server.
     *
     * @param string $method
     * @param string $url
     * @param string[] $headers
     * @param array|string $body
     * @return mixed|null
     */
    protected function sendRequest($method, $url, $headers = [], $body = [], $changedOptions = [])
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ];

        foreach ($changedOptions as $key => $value) {
            $options[$key] = $value;
        }

        for ($i = 0; $i < 10; ++$i) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CONNECT_TO, ["localhost:80:localhost:" . self::PORT]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, $options[CURLOPT_RETURNTRANSFER]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $options[CURLOPT_FOLLOWLOCATION]);
            if ($this->maintainSession) {
                curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookiesFile);
                curl_setopt ($ch, CURLOPT_COOKIEFILE, $this->cookiesFile);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HEADER, 1);
            }
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
            }
            if ($headers) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            $response = curl_exec($ch);
            if ($response === false && $i < 9) {
                \curl_close($ch);
                // sleep for 100 milliseconds before trying again
                \usleep(100 * 1000);
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                // See phpunit_error.log in CircleCI artifacts
                error_log("[request] '{$method} {$url}' (attempt #{$i})");
                error_log("[response] code:{$statusCode} - body:{$response}");
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
