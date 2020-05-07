<?php

namespace DDTrace\Log;

/**
 * Provides methods to interpolate log messages.
 */
trait InterpolateTrait
{
    /**
     * Interpolates context values into the message placeholders. Example code from:
     * https://www.php-fig.org/psr/psr-3/
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    public function interpolate($message, array $context = [])
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            // check that the value can be casted to string
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }
}
