<?php

namespace DDTrace\Http;


/**
 * A utility class that provides methods to work on urls
 */
class Urls
{
    /**
     * Removes query string and fragment from a url.
     *
     * @param string $url
     * @return string
     */
    public static function sanitize($url)
    {
        return strstr($url, '?', true) ?: $url;
    }
}
