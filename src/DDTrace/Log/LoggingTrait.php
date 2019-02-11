<?php

namespace DDTrace\Log;

trait LoggingTrait
{
    /**
     * Emits a log message at debug level.
     *
     * @param string $message
     * @param array $context
     */
    protected static function logDebug($message, array $context = [])
    {
        Logger::get()->debug($message, $context);
    }

    /**
     * Emits a log message at warning level.
     *
     * @param string $message
     * @param array $context
     */
    protected static function logWarning($message, array $context = [])
    {
        Logger::get()->warning($message, $context);
    }

    /**
     * Emits a log message at error level.
     *
     * @param string $message
     * @param array $context
     */
    protected static function logError($message, array $context = [])
    {
        Logger::get()->error($message, $context);
    }

    /**
     * @param \Closure $classback
     */
    protected static function whenDebugIsEnabled(\Closure $classback)
    {
        if (Logger::get()->isLevelActive(LogLevel::DEBUG)) {
            $classback();
        }
    }
}
