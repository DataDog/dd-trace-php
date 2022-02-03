<?php

namespace DDTrace\Integrations\Mysqli;

use DDTrace\Util\ObjectKVStore;

class MysqliCommon
{
    /**
     * Given a mysqli instance, it extract an array containing host info.
     *
     * @param $mysqli
     * @return array
     */
    public static function extractHostInfo($mysqli)
    {
        if (!isset($mysqli->host_info) || !is_string($mysqli->host_info)) {
            return [];
        }
        $hostInfo = $mysqli->host_info;
        return self::parseHostInfo(substr($hostInfo, 0, strpos($hostInfo, ' ')));
    }

    /**
     * Given a host definition string, it extract an array containing host info.
     *
     * @param string $hostString
     * @return array
     */
    public static function parseHostInfo($hostString)
    {
        if (empty($hostString) || !is_string($hostString)) {
            return [];
        }

        $parts = explode(':', $hostString);
        $host = $parts[0];
        $port = isset($parts[1]) ? $parts[1] : '3306';
        return [
            'db.type' => 'mysql',
            'out.host' => $host,
            'out.port' => $port,
        ];
    }

    /**
     * Store a query into a mysqli or statement instance.
     *
     * @param mixed $instance
     * @param string $query
     */
    public static function storeQuery($instance, $query)
    {
        ObjectKVStore::put($instance, 'query', $query);
    }

    /**
     * Retrieves a query from a mysqli or statement instance.
     *
     * @param mixed $instance
     * @param string $fallbackValue
     * @return string|null
     */
    public static function retrieveQuery($instance, $fallbackValue)
    {
        return ObjectKVStore::get($instance, 'query', $fallbackValue);
    }
}
