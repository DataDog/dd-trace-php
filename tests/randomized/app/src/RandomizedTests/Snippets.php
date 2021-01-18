<?php

namespace RandomizedTests;

use Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as GuzzleClient;

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
        $pdo = new \PDO('mysql:host=mysql;dbname=test', 'test', 'test');
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
