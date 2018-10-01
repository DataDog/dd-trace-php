<?php

/**
 * Although DataDog uses nanotime to report spans PHP does not support nanotime
 * plus, nanotime is a uint64 which is not supported either. Microtime will be used
 * and there will be transformations in reporting in order to send nanotime.
 */
namespace DDTrace\Time;

/**
 * @return int
 */
function now()
{
    return (int) (microtime(true) * 1000 * 1000);
}

/**
 * @return int
 */
function fromMicrotime($microtime)
{
    return (int) $microtime * 1000 * 1000;
}

/**
 * @param mixed $time
 * @return bool
 */
function isValid($time)
{
    return
        ($time === (int) $time)
        && ctype_digit((string) $time)
        && strlen((string) $time) === 16;
}
