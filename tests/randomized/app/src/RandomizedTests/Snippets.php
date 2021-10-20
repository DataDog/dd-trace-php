<?php

namespace RandomizedTests;

use Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as GuzzleClient;

class Snippets
{
    /** @var SnippetsConfiguration */
    private $config;

    public function __construct(SnippetsConfiguration $configuration)
    {
        $this->config = $configuration ?: new SnippetsConfiguration();
    }

    public function availableIntegrations()
    {
        $all = [
            'elasticsearch' => 1,
            'guzzle' => 1,
            'memcached' => 1,
            'mysqli' => 1,
            'curl' => 8,
            'pdo' => 1,
            'phpredis' => 1,
        ];

        if (Utils::isPhpVersion(5, 4) || Utils::isPhpVersion(5, 5) || Utils::isPhpVersion(8, 0)) {
            unset($all['elasticsearch']);
        }

        return $all;
    }

    public function mysqliVariant1()
    {
        $mysqli = \mysqli_connect(
            $this->config->mysqlHost,
            $this->config->mysqlUser,
            $this->config->mysqlPassword,
            $this->config->mysqlDb,
            $this->config->mysqlPort
        );
        $mysqli->query('SELECT 1');
        $mysqli->close();
    }

    public function pdoVariant1()
    {
        $pdo = new \PDO(
            \sprintf(
                'mysql:host=%s;dbname=%s;port=%s',
                $this->config->mysqlHost,
                $this->config->mysqlDb,
                $this->config->mysqlPort
            ),
            $this->config->mysqlUser,
            $this->config->mysqlPassword
        );
        $stm = $pdo->query("SELECT VERSION()");
        $version = $stm->fetch();
        $pdo = null;
    }

    public function memcachedVariant1()
    {
        $client = new \Memcached();
        $client->addServer($this->config->memcachedHost, $this->config->memcachedPort);
        $client->add('key', 'value');
        $client->get('key');
    }

    public function curlVariant1()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
    }

    public function curlVariant2()
    {
        $ch1 = curl_init();
        curl_setopt($ch1, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);

        $ch3 = curl_init();
        curl_setopt($ch3, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, 1);

        $mh = curl_multi_init();

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                // Wait a short time for more activity
                curl_multi_select($mh);
            }
        } while ($active && $status == CURLM_OK);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_remove_handle($mh, $ch3);
        curl_multi_close($mh);
    }

    public function curlVariant3()
    {
        # Call curl_multi_init() before curl_init()
        $mh = curl_multi_init();

        $ch1 = curl_init();
        curl_setopt($ch1, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);

        curl_multi_close($mh);
    }

    public function curlVariant4()
    {
        $mh = curl_multi_init();

        $ch1 = curl_init();
        curl_setopt($ch1, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        # Do not call curl_multi_remove_handle()

        curl_multi_close($mh);
    }

    public function curlVariant5()
    {
        $mh = curl_multi_init();

        $ch1 = curl_init();
        curl_setopt($ch1, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);

        # Do not call curl_multi_close()
    }

    public function curlVariant6()
    {
        if (PHP_VERSION_ID <= 50500) {
            # curl_multi_setopt() was added in PHP 5.5
            return;
        }

        $version = curl_version();
        if (!isset($version['version_number']) || $version['version_number'] < 0x072e00) {
            /* CURLMOPT_PUSHFUNCTION is only available in curl v7.46.0
             * @see https://github.com/php/php-src/blob/ce0bc58/ext/curl/interface.c#L1018
             */
            return;
        }

        $mh = curl_multi_init();

        /* Set a CURLMOPT_PUSHFUNCTION callback.
         * I believe this will only be called for HTTP/2 requests which httpbin
         * does not support. But we still want to test it since this closure is
         * stored on the multi-handle and we want to make sure there are no
         * dtor issues.
         */
        $callback = static function () {
            return CURL_PUSH_OK;
        };
        curl_multi_setopt($mh, CURLMOPT_PUSHFUNCTION, $callback);

        $ch1 = curl_init();
        curl_setopt($ch1, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);

        curl_multi_close($mh);
    }

    public function curlVariant7()
    {
        $mh = curl_multi_init();

        $ch1 = curl_init();
        curl_setopt($ch1, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);

        # Set a CURLOPT_WRITEFUNCTION callback
        curl_setopt($ch1, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            return \strlen($data);
        });

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);

        # Set a CURLOPT_HEADERFUNCTION callback
        curl_setopt($ch2, CURLOPT_HEADERFUNCTION, function ($ch, $data) {
            return \strlen($data);
        });

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);

        curl_multi_close($mh);
    }

    public function curlVariant8()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->config->httpBinHost . '/get?client=curl');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);

        # Do not call curl_close()
    }

    public function elasticsearchVariant1()
    {
        $clientBuilder = ClientBuilder::create();
        $clientBuilder->setHosts([$this->config->elasticSearchHost]);
        $client = $clientBuilder->build();

        $params = [
            'index' => 'my_index',
            'type' => 'my_type',
            'id' => 'my_id',
            'body' => ['testField' => 'abc']
        ];

        $client->index($params);
        // See: https://github.com/elastic/elasticsearch-php/issues/842
        $client = null;
    }

    public function guzzleVariant1()
    {
        $client = new GuzzleClient();
        $client->get($this->config->httpBinHost . '/get?client=guzzle');
    }

    public function phpredisVariant1()
    {
        $redis = new \Redis();
        $redis->connect($this->config->redisHost, $this->config->redisPort);
        $redis->flushAll();
        $redis->set('k1', 'v1');
        $redis->get('k1');
    }
}
