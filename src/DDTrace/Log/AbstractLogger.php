<?php

namespace DDTrace\Log;

/**
 * An abstract logger.
 */
abstract class AbstractLogger implements LoggerInterface
{
    /**
     * @var array A map - 'name' => true|false - of enabled levels.
     */
    private $enabledLevels = [];

    /**
     * @param string $level
     */
    public function __construct($level)
    {
        // At the moment we only support debug or off.
        foreach (LogLevel::all() as $knownLevel) {
            $this->enabledLevels[$knownLevel] = $level === LogLevel::DEBUG;
        }
    }

    /**
     * @param string $level
     * @return bool
     */
    public function isLevelActive($level)
    {
        return (bool)$this->enabledLevels[$level];
    }
}
