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

    /** @var bool Whether to use JSON Formatting */
    private $json;

    /**
     * @param string $level
     */
    public function __construct($level, $json = false)
    {
        $level = trim(strtolower($level));

        # all() have all levels ordered from highest to lowest
        # all preceding levels to given $level will be marked as enabled
        $enabled = false;
        foreach (array_reverse(LogLevel::all()) as $knownLevel) {
            $enabled = $enabled || ($level === $knownLevel);
            $this->enabledLevels[$knownLevel] = $enabled;
        }

        $this->json = $json;
    }

    /**
     * @param string $level
     * @return bool
     */
    public function isLevelActive($level)
    {
        return (bool)$this->enabledLevels[$level];
    }

    public function isJSON(): bool
    {
        return $this->json;
    }
}
