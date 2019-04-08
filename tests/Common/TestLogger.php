<?php

namespace DDTrace\Tests\Common;

use DDTrace\Log\LoggerInterface;

final class TestLogger implements LoggerInterface
{
    public $lastLog = '';

    public function debug($message, array $context = [])
    {
        $this->log($message, $context);
    }

    public function warning($message, array $context = [])
    {
        $this->log($message, $context);
    }

    public function error($message, array $context = [])
    {
        $this->log($message, $context);
    }

    public function isLevelActive($level)
    {
        return true;
    }

    private function log($message, array $context = [])
    {
        $this->lastLog = $message . ' ' . json_encode($context);
    }
}
