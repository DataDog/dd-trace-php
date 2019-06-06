<?php

namespace DDTrace\Benchmark\Commands;

use DDTrace\Benchmark\Crawler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RunBenchmarks extends Command
{
    // Move this somewhere else
    private const SUPPORTED_PHP_VERSIONS = [
        '7.3',
        '7.2',
        '7.1',
        '7.0',
        '5.6',
        //'5.5',
        '5.4',
    ];

    protected static $defaultName = 'run';

    protected function configure()
    {
        $this
            ->setDescription('Run the benchmark scripts')
            ->addArgument(
                'PHP version',
                InputArgument::REQUIRED,
                'PHP version to run the benchmarks against (e.g. 7.3)'
            )
            ->addArgument(
                'tracer versions',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Tracer versions to benchmark; "local" means the local checked-out version (e.g. 0.27.2 local)',
                ['local']
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $phpVersion = $input->getArgument('PHP version');
        $this->validatePhpVersion($phpVersion);

        $tracerVersions = $input->getArgument('tracer versions');
        $this->validateTracerVersion($tracerVersions);

        // Compile ddtrace versions here

        $crawler = new Crawler(new SymfonyStyle($input, $output));
        $crawler->crawl($phpVersion, $tracerVersions);
    }

    private function validatePhpVersion(string $phpVersion): void
    {
        if (!in_array($phpVersion, self::SUPPORTED_PHP_VERSIONS, true)) {
            throw new \InvalidArgumentException(
                'PHP version must be a supported PHP version: ' .
                implode(', ', self::SUPPORTED_PHP_VERSIONS)
            );
        }
    }

    private function validateTracerVersion(array $tracerVersions): void
    {
    }
}
