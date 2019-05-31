<?php

namespace DDTrace\Benchmark\Commands;

use DDTrace\Benchmark\Crawler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RunBenchmarks extends Command
{
    protected static $defaultName = 'run';

    protected function configure()
    {
        $this->setDescription('Run the benchmark scripts');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $crawler = new Crawler($output);
        $crawler->crawl();
    }
}
