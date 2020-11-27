<?php

use Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as GuzzleClient;

// Do not use function_exists('DDTrace\...') because if DD_TRACE_ENABLED is not false and the function does not exist
// then we MUST generate an error
if (getenv('DD_TRACE_ENABLED') !== 'false' && extension_loaded('ddtrace')) {
    // Tracing manual functions
    $callback = function (\DDTrace\SpanData $span) {
        $span->service = \ddtrace_config_app_name();
    };
    \dd_trace_method('Chaos', 'doSomethingTraced', $callback);
    \dd_trace_method('Chaos', 'doSomethingTraced1', $callback);
    \dd_trace_method('Chaos', 'doSomethingTraced2', $callback);
    \dd_trace_method('Chaos', 'useAGenerator', $callback);
}

class Chaos
{
    private $snippets;

    private $allowFatalAndUncaught = false;

    public function __construct($allowFatalAndUncaught = false)
    {
        $this->snippets = new Snippets();
        $this->allowFatalAndUncaught = $allowFatalAndUncaught;
    }

    public function randomRequestPath()
    {
        if ($this->percentOfCases(70)) {
            $this->doSomethingTraced();
        } else {
            $this->doSomethingUntraced();
        }
        return "OK";
    }

    public function doSomethingUntraced()
    {
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced1();
            } else {
                $this->doSomethingTraced1();
            }
        } else {
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingTraced()
    {
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced1();
            } else {
                $this->doSomethingTraced1();
            }
        } else {
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingUntraced1()
    {
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced2();
            } else {
                $this->doSomethingTraced2();
            }
        } else {
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingTraced1()
    {
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        if ($this->percentOfCases(80)) {
            if ($this->percentOfCases(70)) {
                $this->doSomethingUntraced2();
            } else {
                $this->doSomethingTraced2();
            }
        } else {
            return;
        }
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingUntraced2()
    {
        $this->maybeRunSomeIntegrations();
        $this->maybeSomethingHappens();
    }

    public function doSomethingTraced2()
    {
        $this->maybeSomethingHappens();
        $this->maybeRunSomeIntegrations();
    }

    private function maybeRunSomeIntegrations()
    {
        if ($this->percentOfCases(70)) {
            $this->runSomeIntegrations();
        }
    }

    private function runSomeIntegrations()
    {
        $availableIntegrations = $this->availableIntegrations();
        $availableIntegrationsNames = \array_keys($availableIntegrations);
        $numberOfIntegrationsToRun = \rand(0, \count($availableIntegrations));
        for ($integrationIndex = 0; $integrationIndex < $numberOfIntegrationsToRun; $integrationIndex++) {
            $pickAnIntegration = \rand(0, count($availableIntegrationsNames) - 1);
            $integrationName = $availableIntegrationsNames[$pickAnIntegration];
            $pickAVariant = \rand(1, $availableIntegrations[$integrationName]);

            $functionName = $integrationName . 'Variant' . $pickAVariant;
            $this->snippets->$functionName();
        }
    }

    private function availableIntegrations()
    {
        return [
            'elasticsearch' => 1,
            'guzzle' => 1,
            'memcached' => 1,
            'mysqli' => 1,
            'curl' => 1,
            'pdo' => 1,
            'phpredis' => 1,
        ];
    }

    private function maybeSomethingHappens()
    {
        $this->maybeUseAGenerator();
        $this->maybeEmitAWarning();
        $this->maybeEmitACaughtException();
        $this->maybeEmitAnUncaughtException();
        $this->maybeGenerateAFatal();
    }

    private function maybeUseAGenerator()
    {
        if ($this->percentOfCases(5)) {
            $this->useAGenerator();
        }
    }

    protected function useAGenerator()
    {
        $generator = $this->generator();
        foreach ($generator as $value) {
            // doing nothing
        }
    }

    private function maybeEmitAWarning()
    {
        // #1021 caused by DD_TRACE_ENABLED=true + warning emitted
        if ($this->percentOfCases(5)) {
            \trigger_error("Some warning triggered", \E_USER_WARNING);
        }
    }

    private function maybeEmitACaughtException()
    {
        if ($this->percentOfCases(20)) {
            try {
                $this->alwaysThrowException('caught exception from chaos');
            } catch (\Exception $e) {
            }
        }
    }

    private function maybeEmitAnUncaughtException()
    {
        if ($this->allowFatalAndUncaught && $this->percentOfCases(2)) {
            $this->alwaysThrowException('uncaught exception from chaos');
        }
    }

    private function maybeGenerateAFatal()
    {
        if ($this->allowFatalAndUncaught && $this->percentOfCases(2)) {
            $this->alwaysGenerateAFatal();
        }
    }

    private function percentOfCases($percent)
    {
        return \rand(0, 99) < $percent;
    }

    private function alwaysThrowException($message)
    {
        throw new \Exception($message, 510);
    }

    private function alwaysGenerateAFatal()
    {
        trigger_error('triggering a user errror', \E_USER_ERROR);
    }

    public function handleException(\Throwable $ex)
    {
        error_log("Handling Exception: " . $ex->getMessage());
        http_response_code(530);
        exit(1);
    }

    public function handleError($errno, $errstr)
    {
        $errorName = $errno;
        if ($errno === \E_USER_ERROR) {
            $errorName = 'E_USER_ERROR';
        } elseif ($errno === \E_USER_WARNING) {
            $errorName = 'E_USER_WARNING';
        } elseif ($errno === \E_USER_NOTICE) {
            $errorName = 'E_USER_NOTICE';
        } elseif ($errno === \E_USER_DEPRECATED) {
            $errorName = 'E_USER_DEPRECATED';
        }
        error_log("Handling Error: $errorName - $errstr");

        if ($errno === \E_USER_ERROR) {
            http_response_code(531);
            exit(1);
        }
    }

    private function generator()
    {
        $number = rand(1, 10);
        for ($i = 1; $i < $number; $i++) {
            $this->runSomeIntegrations();
            yield $i;
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// SNIPPETS
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

class Snippets
{
    public function mysqliVariant1()
    {
        $mysqli = \mysqli_connect('mysql', 'test', 'test', 'test');
        $mysqli->query('SELECT 1');
        $mysqli->close();
    }

    public function pdoVariant1()
    {
        $pdo = new PDO('mysql:host=mysql;dbname=test', 'test', 'test');
        $stm = $pdo->query("SELECT VERSION()");
        $version = $stm->fetch();
        $pdo = null;
    }

    public function memcachedVariant1()
    {
        $client = new \Memcached();
        $client->addServer('memcached', '11211');
        $client->add('key', 'value');
        $client->get('key');
    }

    public function curlVariant1()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'httpbin/get?client=curl');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
    }

    public function elasticsearchVariant1()
    {
        $clientBuilder = ClientBuilder::create();
        $clientBuilder->setHosts(['elasticsearch']);
        $client = $clientBuilder->build();

        $params = [
            'index' => 'my_index',
            'type' => 'my_type',
            'id' => 'my_id',
            'body' => ['testField' => 'abc']
        ];

        $client->index($params);
    }

    public function guzzleVariant1()
    {
        $client = new GuzzleClient();
        $client->get('httpbin/get?client=guzzle');
    }

    public function phpredisVariant1()
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $redis->flushAll();
        $redis->set('k1', 'v1');
        $redis->get('k1');
    }
}
