<?php

namespace DDTrace\Benchmark;

final class CLIRunner
{
    private const DEFAULT_ENV = [
        'DD_TRACE_CLI_ENABLED' => 'true',
        'DD_TRACE_AGENT_PORT' => 8126,
        'DD_AGENT_HOST' => 'ddagent',
        //'DD_TRACE_DEBUG' => 'true',
    ];
    private const DEFAULT_INI = [
        'log_errors' => 'on',
        'error_log' => null,
    ];

    private $phpVersion;
    private $tracerVersion;

    public function __construct(
        string $phpVersion,
        string $tracerVersion
    )
    {
        $this->phpVersion = $phpVersion;
        $this->tracerVersion = $tracerVersion;
    }

    public function benchmarkScript(string $scriptFile, array $config): BenchmarkResult
    {
        $command = sprintf(
            '%s /php/%s/bin/php %s %s 2>&1',
            self::getEnvs($config['env'] ?? []),
            $this->phpVersion,
            $this->getInis($config['ini'] ?? []),
            escapeshellarg($scriptFile)
        );

        $result = new BenchmarkResult($this->tracerVersion, $scriptFile, $command);
        $exitCode = 0;
        $log = [];

        $startTime = hrtime(true);
        exec($command, $log, $exitCode);
        $endTime = hrtime(true);

        $result->finish($exitCode, $log, $startTime, $endTime);
        return $result;
    }

    private static function getEnvs(array $envs): string
    {
        return EnvSerializer::serialize(
            array_merge(self::DEFAULT_ENV, $envs)
        );
    }

    private function getInis(array $inis): string
    {
        return IniSerializer::serialize(
            array_merge(
                self::DEFAULT_INI,
                [
                    'extension' => sprintf(
                        '/php/%s/lib/php/extensions/ddtrace-%s/ddtrace.so',
                        $this->phpVersion,
                        $this->tracerVersion
                    ),
                    'ddtrace.request_init_hook' => sprintf(
                        '/src/ddtrace-downloads/dd-trace-php-%s/bridge/dd_wrap_autoloader.php',
                        $this->tracerVersion
                    ),
                ],
                $inis
            )
        );
    }
}
