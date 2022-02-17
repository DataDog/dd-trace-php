<?php

namespace RandomizedTests;

class SnippetsConfiguration
{
    /** @var string */
    public $httpBinHost = 'localhost';

    /** @var string */
    public $mysqlHost = 'localhost';

    /** @var int */
    public $mysqlPort = 3306;

    /** @var string */
    public $mysqlUser = 'user';

    /** @var string */
    public $mysqlPassword = 'pass';

    /** @var string */
    public $mysqlDb = 'db';

    /** @var string */
    public $memcachedHost = 'localhost';

    /** @var string */
    public $memcachedPort = '11211';

    /** @var string */
    public $redisHost = 'localhost';

    /** @var int */
    public $redisPort = 6379;

    /** @var string */
    public $elasticSearchHost = 'localhost';

    /**
     * @param string $value
     * @return $this
     */
    public function withHttpBinHost($value)
    {
        $this->httpBinHost = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function withMysqlHost($value)
    {
        $this->mysqlHost = $value;
        return $this;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function withMysqlPort($value)
    {
        $this->mysqlPort = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function withMysqlUser($value)
    {
        $this->mysqlUser = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function withMysqlPassword($value)
    {
        $this->mysqlPassword = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function withMysqlDb($value)
    {
        $this->mysqlDb = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function withMemcachedHost($value)
    {
        $this->memcachedHost = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function withMemcachedPort($value)
    {
        $this->memcachedPort = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function withRedisHost($value)
    {
        $this->redisHost = $value;
        return $this;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function withRedisPort($value)
    {
        $this->redisPort = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function withElasticSearchHost($value)
    {
        $this->elasticSearchHost = $value;
        return $this;
    }
}
