<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Deploy\Process;

use Magento\Deploy\Package\Package;
use Magento\Deploy\Service\DeployPackage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Psr\Log\LoggerInterface;

/**
 * Deployment Queue
 *
 * Deploy packages in parallel forks (if available)
 */
class Queue
{
    /**
     * Default max amount of processes
     */
    const DEFAULT_MAX_PROCESSES_AMOUNT = 4;

    /**
     * Default max execution time
     */
    const DEFAULT_MAX_EXEC_TIME = 900;

    /**
     * @var array
     */
    private $packages = [];

    /**
     * @var int[]
     */
    private $processIds = [];

    /**
     * @var Package[]
     */
    private $inProgress = [];

    /**
     * @var int
     */
    private $maxProcesses;

    /**
     * @var int
     */
    private $maxExecTime;

    /**
     * @var AppState
     */
    private $appState;

    /**
     * @var LocaleResolver
     */
    private $localeResolver;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DeployPackage
     */
    private $deployPackageService;

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var int
     */
    private $start = 0;

    /**
     * @var int
     */
    private $lastJobStarted = 0;

    /**
     * @var int
     */
    private $logDelay;

    /**
     * @param AppState $appState
     * @param LocaleResolver $localeResolver
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     * @param DeployPackage $deployPackageService
     * @param array $options
     * @param int $maxProcesses
     * @param int $maxExecTime
     */
    public function __construct(
        AppState $appState,
        LocaleResolver $localeResolver,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger,
        DeployPackage $deployPackageService,
        array $options = [],
        $maxProcesses = self::DEFAULT_MAX_PROCESSES_AMOUNT,
        $maxExecTime = self::DEFAULT_MAX_EXEC_TIME
    ) {
        $this->appState = $appState;
        $this->localeResolver = $localeResolver;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->deployPackageService = $deployPackageService;
        $this->options = $options;
        $this->maxProcesses = $maxProcesses;
        $this->maxExecTime = $maxExecTime;
    }

    /**
     * Adds deployment package.
     *
     * @param Package $package
     * @param Package[] $dependencies
     * @return bool true on success
     */
    public function add(Package $package, array $dependencies = [])
    {
        $this->packages[$package->getPath()] = [
            'package' => $package,
            'dependencies' => $dependencies
        ];

        return true;
    }

    /**
     * Returns packages array.
     *
     * @return Package[]
     */
    public function getPackages()
    {
        return $this->packages;
    }

    /**
     * Process jobs
     *
     * @return int
     * @throws TimeoutException
     */
    public function process()
    {
        $returnStatus = 0;
        $this->logDelay = 10;
        $this->start = $this->lastJobStarted = time();
        $packages = $this->packages;
        while (count($packages) && $this->checkTimeout()) {
            foreach ($packages as $name => $packageJob) {
                // Unsets each member of $packages array (passed by reference) as each is executed
                $this->assertAndExecute($name, $packages, $packageJob);
            }

            $this->refreshStatus();

            if ($this->isCanBeParalleled()) {
                // in parallel mode sleep before trying to check status and run new jobs
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                usleep(500000); // 0.5 sec (less sleep == less time waste)

                foreach ($this->inProgress as $name => $package) {
                    if ($this->isDeployed($package)) {
                        unset($this->inProgress[$name]);
                    }
                }
            }
        }

        $this->awaitForAllProcesses();

        if (!empty($packages)) {
            throw new TimeoutException('Not all packages are deployed.');
        }

        return $returnStatus;
    }

    /**
     * Refresh current status in console once in 10 iterations (once in 5 sec)
     *
     * @return void
     */
    private function refreshStatus(): void
    {
        if ($this->logDelay >= 10) {
            $this->logger->info('.');
            $this->logDelay = 0;
        } else {
            $this->logDelay++;
        }
    }

    /**
     * Check that all depended packages deployed and execute
     *
     * @param string $name
     * @param array $packages
     * @param array $packageJob
     * @return void
     */
    private function assertAndExecute($name, array &$packages, array $packageJob)
    {
        /** @var Package $package */
        $package = $packageJob['package'];
        $dependenciesNotFinished = false;
        if ($package->getParent() && $package->getParent() !== $package) {
            foreach ($packageJob['dependencies'] as $dependencyName => $dependency) {
                if (!$this->isDeployed($dependency)) {
                    //If it's not present in $packages then it's already
                    //in progress so just waiting...
                    if (!array_key_exists($dependencyName, $packages)) {
                        $dependenciesNotFinished = true;
                    } else {
                        $this->assertAndExecute(
                            $dependencyName,
                            $packages,
                            $packages[$dependencyName]
                        );
                    }
                }
            }
        }
        $this->executePackage($package, $name, $packages, $dependenciesNotFinished);
    }

    /**
     * Executes deployment package.
     *
     * @param Package $package
     * @param string $name
     * @param array $packages
     * @param bool $dependenciesNotFinished
     * @return void
     */
    private function executePackage(Package $package, string $name, array &$packages, bool $dependenciesNotFinished)
    {
        if (!$dependenciesNotFinished
            && !$this->isDeployed($package)
            && ($this->maxProcesses < 2 || (count($this->inProgress) < $this->maxProcesses))
        ) {
            unset($packages[$name]);
            $this->execute($package);
        }
    }

    /**
     * Need to wait till all processes finished
     *
     * @return void
     */
    private function awaitForAllProcesses()
    {
        while ($this->inProgress && $this->checkTimeout()) {
            foreach ($this->inProgress as $name => $package) {
                if ($this->isDeployed($package)) {
                    unset($this->inProgress[$name]);
                }
            }

            $this->refreshStatus();

            // sleep before checking parallel jobs status
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            usleep(500000); // 0.5 sec (less sleep == less time waste)
        }
        if ($this->isCanBeParalleled()) {
            // close connections only if ran with forks
            $this->resourceConnection->closeConnection();
        }
    }

    /**
     * Checks if can be parallel.
     *
     * @return bool
     */
    private function isCanBeParalleled()
    {
        return function_exists('pcntl_fork') && $this->maxProcesses > 1;
    }

    /**
     * Executes the process.
     *
     * @param Package $package
     * @return bool true on success for main process and exit for child process
     * @throws \RuntimeException
     */
    private function execute(Package $package)
    {
        $this->lastJobStarted = time();
        $this->logger->info(
            "Execute: " . $package->getPath(),
            [
                'process' => $package->getPath(),
                'count' => count($package->getFiles()),
            ]
        );

        $this->appState->emulateAreaCode(
            $package->getArea() == Package::BASE_AREA ? 'global' : $package->getArea(),
            function () use ($package) {
                // emulate application locale needed for correct file path resolving
                $this->localeResolver->setLocale($package->getLocale());

                // execute package pre-processors
                // (may add more files to deploy, so it needs to be executed in main thread)
                foreach ($package->getPreProcessors() as $processor) {
                    $processor->process($package, $this->options);
                }
            }
        );

        if ($this->isCanBeParalleled()) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Unable to fork a new process');
            }

            if ($pid) {
                $this->inProgress[$package->getPath()] = $package;
                $this->processIds[$package->getPath()] = $pid;
                return true;
            }

            // process child process
            $this->inProgress = [];
            $this->deployPackageService->deploy($package, $this->options, true);
            // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
            exit(0);
        } else {
            $this->deployPackageService->deploy($package, $this->options);
            return true;
        }
    }

    /**
     * Checks if package is deployed.
     *
     * @param Package $package
     * @return bool
     */
    private function isDeployed(Package $package)
    {
        if ($this->isCanBeParalleled()) {
            if ($package->getState() === null) {
                $pid = $this->getPid($package);

                // When $pid comes back as null the child process for this package has not yet started; prevents both
                // hanging until timeout expires (which was behaviour in 2.2.x) and the type error from strict_types
                if ($pid === null) {
                    return false;
                }

                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                if ($result === $pid) {
                    $package->setState(Package::STATE_COMPLETED);
                    // phpcs:ignore Magento2.Functions.DiscouragedFunction
                    $exitStatus = pcntl_wexitstatus($status);

                    $this->logger->info(
                        "Exited: " . $package->getPath() . "(status: $exitStatus)",
                        [
                            'process' => $package->getPath(),
                            'status' => $exitStatus,
                        ]
                    );

                    unset($this->inProgress[$package->getPath()]);
                    // phpcs:ignore Magento2.Functions.DiscouragedFunction
                    return pcntl_wexitstatus($status) === 0;
                } elseif ($result === -1) {
                    // phpcs:ignore Magento2.Functions.DiscouragedFunction
                    $errno = pcntl_errno();
                    // phpcs:ignore Magento2.Functions.DiscouragedFunction
                    $strerror = pcntl_strerror($errno);

                    throw new \RuntimeException(
                        "Error encountered checking child process status (PID: $pid): $strerror (errno: $errno)"
                    );
                }
                return false;
            }
        }
        return $package->getState();
    }

    /**
     * Returns process ID or null if not found.
     *
     * @param Package $package
     * @return int|null
     */
    private function getPid(Package $package)
    {
        return $this->processIds[$package->getPath()] ?? null;
    }

    /**
     * Checks timeout.
     *
     * @return bool
     */
    private function checkTimeout()
    {
        return time() - $this->lastJobStarted < $this->maxExecTime;
    }

    /**
     * Free resources
     *
     * Protect against zombie process
     *
     * @throws \RuntimeException
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     * @return void
     */
    public function __destruct()
    {
        foreach ($this->inProgress as $package) {
            $pid = $this->getPid($package);
            $this->logger->info(
                "Reaping child process: {$package->getPath()} (PID: $pid)",
                [
                    'process' => $package->getPath(),
                    'pid' => $pid,
                ]
            );

            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            if (pcntl_waitpid($pid, $status) === -1) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $errno = pcntl_errno();
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $strerror = pcntl_strerror($errno);

                throw new \RuntimeException(
                    "Error encountered waiting for child process (PID: $pid): $strerror (errno: $errno)"
                );
            }
        }
    }
}
