<?php

namespace DDTrace\Http;

class Request
{
    /**
     * @return string
     */
    public static function getUrl()
    {
        $host = self::server('HTTP_HOST');
        if (!$host) {
            $host = self::server('SERVER_NAME');
        }
        if (!$host) {
            $host = self::server('SERVER_ADDR');
        }
        return self::getProtocol() . '://' . $host . self::server('REQUEST_URI');
    }

    /**
     * @return string
     */
    public static function getProtocol()
    {
        $protocol = strtolower(self::server('HTTPS'));
        return !empty($protocol) && 'off' !== $protocol
            ? 'https'
            : 'http';
    }

    /**
     * @return string
     */
    public static function getMethod()
    {
        return strtoupper(self::server('REQUEST_METHOD'));
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    private static function server($key, $default = '')
    {
        return isset($_SERVER[$key]) ? $_SERVER[$key] : $default;
    }
}
