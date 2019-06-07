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

            $this->output->section(sprintf(
                '%s (%d)',
                $config['name'] ?? basename($dir),
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
            foreach ($this->tracerVersions as $tracerVersion) {
                $this->output->write(sprintf(
                    '<info>%s</info> %02d: %s/%s... ',
                    str_pad($tracerVersion, 8), // Space for 00.00.00
                    $i,
                    basename($dir),
                    $fileName
                ));
                $this->output->writeln(
                    $this->benchmarkScript($tracerVersion, $script, $config) ? '✅' : '❌'
                );
            }
            $i++;
        }
    }

    private function benchmarkScript(string $tracerVersion, string $scriptFile, array $config): bool
    {
        $cli = new CLIRunner($this->phpVersion, $tracerVersion);
        $result = $cli->benchmarkScript($scriptFile, $config);
        $this->totalRuns++;
        if ($this->output->isVerbose()) {
            $this->output->write("\n<comment>" . $result->command() . "</comment>");
        }

        if ($this->output->isVerbose()) {
            if ($result->wasError()) {
                $this->output->writeln("\n<error>" . $result->lastLine() . "</error>");
            } else {
                $this->output->writeln("\n" . $result->lastLine());
            }
        }
        if ($result->wasError()) {
            $this->errorResults[] = $result;
            return false;
        }
        $this->successResults[] = $result;
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
        $this->output->listing(array_map(function (BenchmarkResult $result) {
            return $result->errorLog();
        }, $this->errorResults));
    }
}
