<?php

namespace DDTrace\Benchmark;

use Symfony\Component\Console\Output\OutputInterface;

final class Crawler
{
    private const BENCHMARK_SCRIPTS_DIR = 'benchmark-scripts';

    private $dir;
    private $output;

    public function __construct(OutputInterface $output)
    {
        $this->dir = dirname(__DIR__) . '/' . self::BENCHMARK_SCRIPTS_DIR;
        $this->output = $output;
    }

    public function crawl(): void
    {
        $this->output->writeln('Running scrips from <info>' . $this->dir . '</info>');
        $this->output->writeln('Crawling...');
    }
}
