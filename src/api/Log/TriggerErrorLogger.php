<?php

namespace DDTrace\Log;

final class TriggerErrorLogger extends AbstractLogger
{
    use InterpolateTrait;

    public function __construct($level = LogLevel::WARNING)
    {
        parent::__construct($level);
    }

    public function debug($message, array $context = array())
    {
        $this->emit(LogLevel::DEBUG, $message, $context, E_USER_NOTICE);
    }

    public function warning($message, array $context = array())
    {
        $this->emit(LogLevel::WARNING, $message, $context, E_USER_WARNING);
    }

    public function error($message, array $context = array())
    {
        $this->emit(LogLevel::ERROR, $message, $context, E_USER_WARNING);
    }

    private function emit($level, $message, array $context, $severity)
    {
        if (!$this->isLevelActive($level)) {
            return;
        }

        trigger_error($this->interpolate($message, $context), $severity);
    }
}
