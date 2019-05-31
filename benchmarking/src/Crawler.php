<?php

namespace DDTrace\Benchmark;

use Symfony\Component\Console\Style\SymfonyStyle;

final class Crawler
{
    private const BENCHMARK_SCRIPTS_DIR = 'benchmark-scripts';

    private $dir;
    private $output;

    public function __construct(SymfonyStyle $output)
    {
        $this->dir = dirname(__DIR__) . '/' . self::BENCHMARK_SCRIPTS_DIR;
        $this->output = $output;
    }

    public function crawl(): void
    {
        $this->output->writeln('Running scripts from <info>' . $this->dir . '</info>');
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
            if ('config.php' === basename($script)) {
                continue;
            }
            $this->output->section($script);
            // Run benchmark script here
        }
    }
}
