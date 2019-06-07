<?php

namespace DDTrace\Benchmark;

final class BenchmarkResult
{
    private $tracerVersion;
    private $scriptFile;
    private $command = '';
    private $errorLog;
    private $lastLine = '';
    private $startTime = 0;
    private $endTime = 0;

    public function __construct(string $tracerVersion, string $scriptFile, string $command)
    {
        $this->tracerVersion = $tracerVersion;
        $this->scriptFile = $scriptFile;
        $this->command = $command;
        $this->errorLog = sprintf(
            '%s/%s-%s.log',
            dirname($scriptFile),
            basename($scriptFile, '.php'),
            $this->tracerVersion
        );
        // Clean up from last run
        if (file_exists($this->errorLog)) {
            unlink($this->errorLog);
        }
    }

    public function finish(
        int $exitCode,
        array $log,
        int $startTime,
        int $endTime
    ): void
    {
        if (0 !== $exitCode) {
            file_put_contents($this->errorLog, implode("\n", $log));
        }
        $this->lastLine = !empty($log) ? end($log) : '';
        $this->startTime = $startTime;
        $this->endTime = $endTime;
    }

    public function wasError(): bool
    {
        return file_exists($this->errorLog);
    }

    public function errorLog(): string
    {
        return $this->errorLog;
    }

    public function lastLine(): string
    {
        return $this->lastLine;
    }

    public function duration(): int
    {
        return $this->endTime - $this->startTime;
    }

    public function command(): string
    {
        return $this->command;
    }
}
