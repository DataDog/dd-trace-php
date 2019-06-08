<?php

namespace DDTrace\Benchmark;

final class DDTraceCompiler
{
    private const SO_DIRECTORY = '/php/%s/lib/php/extensions/ddtrace-%s';
    private const SCRIPT = '/home/app/bin/ext-ddtrace';

    public const DEFAULT_VERSION = 'local';

    private $phpVersion;
    private $tracerVersion;
    private $forceRecompile = [];
    private $soFile;

    public function __construct(
        string $phpVersion,
        string $tracerVersion = self::DEFAULT_VERSION,
        array $forceRecompile = []
    )
    {
        $this->phpVersion = $phpVersion;
        $this->tracerVersion = $tracerVersion;
        $this->soFile = sprintf(self::SO_DIRECTORY, $phpVersion, $tracerVersion) . '/ddtrace.so';
        $this->forceRecompile = $forceRecompile;
    }

    public function compile($verbose = false): ?string
    {
        if (!$this->shouldCompile()) {
            return null;
        }
        return $this->doCompile($verbose);
    }

    public function shouldCompile(): bool
    {
        if (in_array('all', $this->forceRecompile, true)) {
            return true;
        }
        if (in_array($this->tracerVersion, $this->forceRecompile, true)) {
            return true;
        }
        return !file_exists($this->soFile);
    }

    private function doCompile($verbose = false): ?string
    {
        $exitCode = 0;
        $log = [];
        $lastLine = exec(
            sprintf(
                '%s %s %s',
                self::SCRIPT,
                escapeshellarg($this->phpVersion),
                escapeshellarg($this->tracerVersion)
            ),
            $log,
            $exitCode
        );
        if (0 !== $exitCode) {
            $errorLog = dirname(__DIR__) . '/ddtrace-error.log';
            file_put_contents($errorLog, implode("\n", $log));
            throw new \Exception(
                'Error compiling ddtrace: ' . $lastLine .
                "\nSee $errorLog for details"
            );
        }
        return $verbose ? implode("\n", $log) : $lastLine;
    }
}
