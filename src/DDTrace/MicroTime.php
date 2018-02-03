<?php
/**
 * Although DataDog uses nanotime to report spans PHP does not support nanotime
 * plus, nanotime is a uint64 which is not supported either. Microtime will be used
 * and there will be transformations in reporting in order to send nanotime.
 */
namespace DDTrace\MicroTime;

/**
 * @return int
 */
function now()
{
    return (int) (microtime(true) * 1000 * 1000);
}

/**
 * @param mixed $microtime
 * @return bool
 */
function isValid($microtime)
{
    return
        ($microtime === (int) $microtime)
        && ctype_digit((string) $microtime)
        && strlen((string) $microtime) === 16;
}
