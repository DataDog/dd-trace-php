<?php

namespace DDTrace\Benchmark;

use Symfony\Component\Console\Style\SymfonyStyle;

final class BenchmarkRunner
{
    private const BENCHMARK_SCRIPTS_DIR = 'benchmark-scripts';

    private $dir;
    private $phpVersion;
    private $tracerVersions;
    private $output;
    private $successResults = [];
    /**
     * @var string[]
     */
    private $errorResults = [];
    private $totalRuns = 0;

    public function __construct(
        string $phpVersion,
        array $tracerVersions,
        SymfonyStyle $output
    )
    {
        $this->dir = dirname(__DIR__) . '/' . self::BENCHMARK_SCRIPTS_DIR;
        $this->phpVersion = $phpVersion;
        $this->tracerVersions = $tracerVersions;
        $this->output = $output;
    }

    public function run(): void
    {
        $this->output->writeln("Running benchmarks in <info>$this->dir</info>...");

        $files = glob($this->dir . '/*', GLOB_ONLYDIR);
        foreach ($files as $dir) {
            $config = $this->loadConfig($dir);
            if (!$config) {
                $this->output->writeln('<comment>Missing or invalid ' . $dir . '/config.php</comment>');
                continue;
            }

            $this->output->title(sprintf(
                '%s (%d)',
                $config['name'] ?? 'Untitled',
                count($files)
            ));
            $this->runBenchmarks($dir, $config);
        }
        $this->showSummary();
    }

    private function loadConfig(string $dir): array
    {
        $file = $dir . '/config.php';
        if (!file_exists($file)) {
            return [];
        }
        $config = require $file;
        return is_array($config) ? $config : [];
    }

    private function runBenchmarks(string $dir, array $config): void
    {
        $i = 0;
        foreach (glob($dir . '/*.php') as $script) {
            $fileName = basename($script);
            if ('config.php' === $fileName) {
                continue;
            }
            $this->output->write(sprintf(
                '%03d: %s/%s... ',
                $i,
                basename($dir),
                $fileName
            ));
            $this->output->writeln(
                $this->benchmarkScript($script) ? '✅' : '❌'
            );
            $i++;
        }
    }

    private function benchmarkScript(string $scriptFile): bool
    {
        $errorLog = sprintf(
            '%s/%s.log',
            dirname($scriptFile),
            basename($scriptFile, '.php')
        );
        // Clean up from last run
        if (file_exists($errorLog)) {
            unlink($errorLog);
        }

        $exitCode = 0;
        $log = [];

        $startTime = hrtime(true);
        $lastLine = exec(
            sprintf(
                'php %s 2>&1',
                escapeshellarg($scriptFile)
            ),
            $log,
            $exitCode
        );
        $endTime = hrtime(true);
        $this->totalRuns++;

        if ($this->output->isVerbose()) {
            $this->output->write($lastLine);
        }
        if (0 !== $exitCode) {
            file_put_contents($errorLog, implode("\n", $log));
            $this->errorResults[$scriptFile] = $errorLog;
            return false;
        }
        // Make an entity to store this
        $this->successResults[$scriptFile] = [
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
        return true;
    }

    private function showSummary(): void
    {
        $this->output->title('SUMMARY');
        $this->showErrorSummary();
        $this->output->table(
            ['Success', 'Error', 'Total'],
            [
                [
                    count($this->successResults),
                    count($this->errorResults),
                    $this->totalRuns,
                ],
            ]
        );
    }

    private function showErrorSummary(): void
    {
        if (empty($this->errorResults)) {
            return;
        }
        $this->output->writeln('<error>Failed benchmarks</error>');
        $this->output->listing($this->errorResults);
    }
}
