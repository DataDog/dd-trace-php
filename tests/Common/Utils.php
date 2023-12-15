<?php

declare(strict_types=1);

namespace DDTrace\Tests\Common;

use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Tests\WebServer;

abstract class Utils
{
    const FLUSH_INTERVAL_MS = 333;

    // host and port for the testing framework
    const HOST = 'http://localhost';
    const PORT = 9999;

    const ERROR_LOG_NAME = 'phpbench_error.log';

    /**
     * @var WebServer|null
     */
    private static $appServer;

    public static function setUpBeforeClass()
    {
        $index = static::getAppIndexScript();
        if ($index) {
            ini_set('error_log', dirname($index) . '/' . static::ERROR_LOG_NAME);
        }
        static::setUpWebServer();
    }

    public static function tearDownAfterClass()
    {
        static::tearDownWebServer();
    }

    public static function putEnv($putenv)
    {
        // cleanup: properly replace this function by ini_set() in test code ...
        if (strpos($putenv, "DD_") === 0) {
            $val = explode("=", $putenv, 2);
            $name = strtolower(strtr($val[0], [
                "DD_TRACE_" => "datadog.trace.",
                "DD_" => "datadog.",
            ]));
            if (count($val) > 1) {
                \ini_set($name, $val[1]);
            } else {
                \ini_restore($name);
            }
        }
        \putenv($putenv);
    }

    public static function putEnvAndReloadConfig($putenvs = [])
    {
        foreach ($putenvs as $putenv) {
            self::putEnv($putenv);
        }
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    public static function call(RequestSpec $spec, $options = [])
    {
        $response = self::sendRequest(
            $spec->getMethod(),
            self::HOST . ':' . self::PORT . $spec->getPath(),
            $spec->getHeaders(),
            $spec->getBody(),
            $options
        );
        return $response;
    }

    public static function sendRequest($method, $url, $headers = [], $body = [], $changedOptions = [])
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
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, $options[CURLOPT_RETURNTRANSFER]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $options[CURLOPT_FOLLOWLOCATION]);
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
            return null;
        }

        curl_close($ch);

        return $response;
    }

    /**
     * Get additional envs to be set in the web server.
     * @return array
     */
    public static function getEnvs()
    {
        $envs = [
            'DD_TEST_INTEGRATION' => 'true',
            'DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS' => 1,
            // Short flush interval by default or our tests will take all day
            'DD_TRACE_AGENT_FLUSH_INTERVAL' => static::FLUSH_INTERVAL_MS,
            'DD_AUTOLOAD_NO_COMPILE' => getenv('DD_AUTOLOAD_NO_COMPILE'),
            'DD_TRACE_DEBUG' => ini_get("datadog.trace.debug"),
        ];

        return $envs;
    }

    /**
     * Get additional inis to be set in the web server.
     * @return array
     */
    public static function getInis()
    {
        return [
            'ddtrace.request_init_hook' => realpath(__DIR__ . '/../../bridge/dd_wrap_autoloader.php'),
            // The following values should be made configurable from the outside. I could not get env XDEBUG_CONFIG
            // to work setting it both in docker-compose.yml and in `getEnvs()` above, but that should be the best
            // option.
            'xdebug.remote_enable' => 1,
            'xdebug.remote_host' => 'host.docker.internal',
            'xdebug.remote_autostart' => 1,
        ];
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

    public static function setUpWebServer(array $additionalEnvs = [])
    {
        $rootPath = static::getAppIndexScript();
        if ($rootPath) {
            self::$appServer = new WebServer($rootPath, '0.0.0.0', self::PORT);
            $envs = static::getEnvs();
            if (!empty($additionalEnvs)) {
                $envs = array_merge($envs, $additionalEnvs);
            }
            self::$appServer->mergeEnvs($envs);
            self::$appServer->mergeInis(static::getInis());
            self::$appServer->start();
        }
    }

    public static function tearDownWebServer()
    {
        print("[tearDownWebServer]" . self::$appServer->checkErrors());
        if (self::$appServer) {
            self::$appServer->stop();
        }
    }
}
