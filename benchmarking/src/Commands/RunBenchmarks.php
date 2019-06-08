<?php

namespace DDTrace\Benchmark\Commands;

use DDTrace\Benchmark\BenchmarkRunner;
use DDTrace\Benchmark\DDTraceCompiler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
                [DDTraceCompiler::DEFAULT_VERSION]
            )
            ->addOption(
                'force-recompile',
                ['f'],
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Force the ddtrace extension to recompile before running benchmarks',
                []
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $phpVersion = $input->getArgument('PHP version');
        $this->validatePhpVersion($phpVersion);

        $tracerVersions = $input->getArgument('tracer versions');
        $this->validateTracerVersion($tracerVersions);

        $output->writeln("DDTrace Benchmarking for PHP <info>$phpVersion</info>");
        $output->writeln('<comment>Version(s) to benchmark: <info>' . implode(', ', $tracerVersions) . "</info></comment>\n");

        foreach ($tracerVersions as $tracerVersion) {
            $compiler = new DDTraceCompiler(
                $phpVersion,
                $tracerVersion,
                $input->getOption('force-recompile')
            );
            if ($compiler->shouldCompile()) {
                $output->writeln("Compiling ddtrace <info>$tracerVersion</info> for PHP <info>$phpVersion</info>...");
                $result = $compiler->compile($output->isVeryVerbose());
                $output->writeln($result);
                $output->writeln("---\n");
            }
        }

        $runner = new BenchmarkRunner(
            $phpVersion,
            $tracerVersions,
            new SymfonyStyle($input, $output)
        );
        $runner->run();
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
        // Add tracer version validation
    }
}
