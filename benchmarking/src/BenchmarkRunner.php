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

        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) as $dir) {
            $config = $this->loadConfig($dir);
            if (!$config) {
                $this->output->writeln('<comment>Missing or invalid ' . $dir . '/config.php</comment>');
                continue;
            }
            $this->runBenchmarks($dir, $config);
        }
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
        $this->output->title($config['name'] ?? 'Untitled');
        foreach (glob($dir . '/*.php') as $script) {
            $fileName = basename($script);
            if ('config.php' === $fileName) {
                continue;
            }
            $this->output->section(basename($dir) . '/' . $fileName);
            // Run benchmark script here
        }
    }
}
