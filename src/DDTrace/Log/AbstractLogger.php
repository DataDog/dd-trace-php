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
        $level = trim(strtolower($level));

        # all() have all levels ordered from highest to lowest
        # all preceding levels to given $level will be marked as enabled
        $enabled = false;
        foreach (array_reverse(LogLevel::all()) as $knownLevel) {
            $enabled = $enabled || ($level === $knownLevel);
            $this->enabledLevels[$knownLevel] = $enabled;
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
