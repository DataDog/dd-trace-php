<?php

namespace DDTrace\Tests;

use DDTrace\Log\AbstractLogger;
use DDTrace\Log\InterpolateTrait;
use DDTrace\Log\LogLevel;

class DebugLogger extends AbstractLogger
{
    use InterpolateTrait;

    private $records = [];

    public function __construct()
    {
        parent::__construct(LogLevel::DEBUG);
    }

    /**
     * @param string $message
     * @param array $context
     */
    public function debug($message, array $context = [])
    {
        $this->records[] = [
            LogLevel::DEBUG,
            $this->interpolate($message, $context),
        ];
    }

    /**
     * @param string $message
     * @param array $context
     */
    public function warning($message, array $context = array())
    {
        $this->records[] = [
            LogLevel::WARNING,
            $this->interpolate($message, $context),
        ];
    }

    /**
     * @param string $message
     * @param array $context
     */
    public function error($message, array $context = array())
    {
        $this->records[] = [
            LogLevel::ERROR,
            $this->interpolate($message, $context),
        ];
    }

    /**
     * @param string $level
     * @param string $message
     * @return bool
     */
    public function has($level, $message)
    {
        return count(array_filter($this->records, function ($record) use ($level, $message) {
            return $record[0] === $level && $record[1] === $message;
        })) > 0;
    }

    /**
     * @param string $level
     * @param string $message
     * @return bool
     */
    public function hasOnly($level, $message)
    {
        return count($this->all()) === 1 && $this->has($level, $message);
    }

    /**
     * @return array
     */
    public function all()
    {
        return $this->records;
    }
}
