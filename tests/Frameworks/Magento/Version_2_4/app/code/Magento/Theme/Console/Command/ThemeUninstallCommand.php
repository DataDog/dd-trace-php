<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Theme\Console\Command;

use Magento\Framework\App\Cache;
use Magento\Framework\App\Console\MaintenanceModeEnabler;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\State\CleanupFiles;
use Magento\Framework\Composer\ComposerInformation;
use Magento\Framework\Composer\DependencyChecker;
use Magento\Theme\Model\Theme\Data\Collection;
use Magento\Theme\Model\Theme\ThemePackageInfo;
use Magento\Theme\Model\Theme\ThemeUninstaller;
use Magento\Theme\Model\Theme\ThemeDependencyChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Magento\Framework\Setup\BackupRollbackFactory;
use Magento\Theme\Model\ThemeValidator;

/**
 * Command for uninstalling theme and backup-code feature
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class ThemeUninstallCommand extends Command
{
    /**
     * Names of input arguments or options
     */
    const INPUT_KEY_BACKUP_CODE = 'backup-code';
    const INPUT_KEY_THEMES = 'theme';
    const INPUT_KEY_CLEAR_STATIC_CONTENT = 'clear-static-content';

    /**
     * Composer general dependency checker
     *
     * @var DependencyChecker
     */
    private $dependencyChecker;

    /**
     * Root composer.json information
     *
     * @var ComposerInformation
     */
    private $composer;

    /**
     * Theme collection in filesystem
     *
     * @var Collection
     */
    private $themeCollection;

    /**
     * System cache model
     *
     * @var Cache
     */
    private $cache;

    /**
     * Cleaning up application state service
     *
     * @var CleanupFiles
     */
    private $cleanupFiles;

    /**
     * BackupRollback factory
     *
     * @var BackupRollbackFactory
     */
    private $backupRollbackFactory;

    /**
     * Theme Validator
     *
     * @var ThemeValidator
     */
    private $themeValidator;

    /**
     * Package name finder
     *
     * @var ThemePackageInfo
     */
    private $themePackageInfo;

    /**
     * Theme Uninstaller
     *
     * @var ThemeUninstaller
     */
    private $themeUninstaller;

    /**
     * Theme Dependency Checker
     *
     * @var ThemeDependencyChecker
     */
    private $themeDependencyChecker;

    /**
     * @var MaintenanceModeEnabler
     */
    private $maintenanceModeEnabler;

    /**
     * Constructor
     *
     * @param Cache $cache
     * @param CleanupFiles $cleanupFiles
     * @param ComposerInformation $composer
     * @param MaintenanceMode $maintenanceMode deprecated, use $maintenanceModeEnabler instead
     * @param DependencyChecker $dependencyChecker
     * @param Collection $themeCollection
     * @param BackupRollbackFactory $backupRollbackFactory
     * @param ThemeValidator $themeValidator
     * @param ThemePackageInfo $themePackageInfo
     * @param ThemeUninstaller $themeUninstaller
     * @param ThemeDependencyChecker $themeDependencyChecker
     * @param MaintenanceModeEnabler $maintenanceModeEnabler
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        Cache $cache,
        CleanupFiles $cleanupFiles,
        ComposerInformation $composer,
        MaintenanceMode $maintenanceMode,
        DependencyChecker $dependencyChecker,
        Collection $themeCollection,
        BackupRollbackFactory $backupRollbackFactory,
        ThemeValidator $themeValidator,
        ThemePackageInfo $themePackageInfo,
        ThemeUninstaller $themeUninstaller,
        ThemeDependencyChecker $themeDependencyChecker,
        MaintenanceModeEnabler $maintenanceModeEnabler = null
    ) {
        $this->cache = $cache;
        $this->cleanupFiles = $cleanupFiles;
        $this->composer = $composer;
        $this->dependencyChecker = $dependencyChecker;
        $this->themeCollection = $themeCollection;
        $this->backupRollbackFactory = $backupRollbackFactory;
        $this->themeValidator = $themeValidator;
        $this->themePackageInfo = $themePackageInfo;
        $this->themeUninstaller = $themeUninstaller;
        $this->themeDependencyChecker = $themeDependencyChecker;
        $this->maintenanceModeEnabler =
            $maintenanceModeEnabler ?: ObjectManager::getInstance()->get(MaintenanceModeEnabler::class);
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('theme:uninstall');
        $this->setDescription('Uninstalls theme');
        $this->addOption(
            self::INPUT_KEY_BACKUP_CODE,
            null,
            InputOption::VALUE_NONE,
            'Take code backup (excluding temporary files)'
        );
        $this->addArgument(
            self::INPUT_KEY_THEMES,
            InputArgument::IS_ARRAY | InputArgument::REQUIRED,
            'Path of the theme. Theme path should be specified as full path which is area/vendor/name.'
            . ' For example, frontend/Magento/blank'
        );
        $this->addOption(
            self::INPUT_KEY_CLEAR_STATIC_CONTENT,
            'c',
            InputOption::VALUE_NONE,
            'Clear generated static view files.'
        );
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $messages = [];
        $themePaths = $input->getArgument(self::INPUT_KEY_THEMES);
        $messages = array_merge($messages, $this->validate($themePaths));
        if (!empty($messages)) {
            $output->writeln($messages);
            // we must have an exit code higher than zero to indicate something was wrong
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
        $messages = array_merge(
            $messages,
            $this->themeValidator->validateIsThemeInUse($themePaths),
            $this->themeDependencyChecker->checkChildTheme($themePaths),
            $this->checkDependencies($themePaths)
        );
        if (!empty($messages)) {
            $output->writeln(
                '<error>Unable to uninstall. Please resolve the following issues:</error>'
                . PHP_EOL . implode(PHP_EOL, $messages)
            );
            // we must have an exit code higher than zero to indicate something was wrong
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }

        $result = $this->maintenanceModeEnabler->executeInMaintenanceMode(
            function () use ($input, $output, $themePaths) {
                try {
                    if ($input->getOption(self::INPUT_KEY_BACKUP_CODE)) {
                        $time = time();
                        $codeBackup = $this->backupRollbackFactory->create($output);
                        $codeBackup->codeBackup($time);
                    }

                    $this->themeUninstaller->uninstallRegistry($output, $themePaths);
                    $this->themeUninstaller->uninstallCode($output, $themePaths);

                    $this->cleanup($input, $output);
                    return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
                } catch (\Exception $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    $output->writeln('<error>Please disable maintenance mode after you resolved above issues</error>');
                    // we must have an exit code higher than zero to indicate something was wrong
                    return \Magento\Framework\Console\Cli::RETURN_FAILURE;
                }
            },
            $output,
            true
        );

        return $result;
    }

    /**
     * Validate given full theme paths
     *
     * @param string[] $themePaths
     * @return string[]
     */
    private function validate($themePaths)
    {
        $messages = [];

        $incorrectThemes = $this->getIncorrectThemes($themePaths);
        if (!empty($incorrectThemes)) {
            $text = 'Theme path should be specified as full path which is area/vendor/name.';
            $messages[] = '<error>Incorrect theme(s) format: ' . implode(', ', $incorrectThemes)
                . '. ' . $text . '</error>';
            return $messages;
        }

        $unknownPackages = $this->getUnknownPackages($themePaths);
        $unknownThemes = $this->getUnknownThemes($themePaths);

        $unknownPackages = array_diff($unknownPackages, $unknownThemes);
        if (!empty($unknownPackages)) {
            $text = count($unknownPackages) > 1 ?
                ' are not installed Composer packages' : ' is not an installed Composer package';
            $messages[] = '<error>' . implode(', ', $unknownPackages) . $text . '</error>';
        }

        if (!empty($unknownThemes)) {
            $messages[] = '<error>Unknown theme(s): ' . implode(', ', $unknownThemes) . '</error>';
        }

        return $messages;
    }

    /**
     * Retrieve list of themes with wrong name format
     *
     * @param string[] $themePaths
     * @return string[]
     */
    protected function getIncorrectThemes($themePaths)
    {
        $result = [];
        foreach ($themePaths as $themePath) {
            if (!preg_match('/^[^\/]+\/[^\/]+\/[^\/]+$/', $themePath)) {
                $result[] = $themePath;
                continue;
            }
        }
        return $result;
    }

    /**
     * Retrieve list of unknown packages
     *
     * @param string[] $themePaths
     * @return string[]
     */
    protected function getUnknownPackages($themePaths)
    {
        $installedPackages = $this->composer->getRootRequiredPackages();

        $result = [];
        foreach ($themePaths as $themePath) {
            if (array_search($this->themePackageInfo->getPackageName($themePath), $installedPackages) === false) {
                $result[] = $themePath;
            }
        }
        return $result;
    }

    /**
     * Retrieve list of unknown themes
     *
     * @param string[] $themePaths
     * @return string[]
     */
    protected function getUnknownThemes($themePaths)
    {
        $result = [];
        foreach ($themePaths as $themePath) {
            if (!$this->themeCollection->hasTheme($this->themeCollection->getThemeByFullPath($themePath))) {
                $result[] = $themePath;
            }
        }
        return $result;
    }

    /**
     * Check dependencies to given full theme paths
     *
     * @param string[] $themePaths
     * @return string[]
     */
    private function checkDependencies($themePaths)
    {
        $messages = [];
        $packageToPath = [];
        foreach ($themePaths as $themePath) {
            $packageToPath[$this->themePackageInfo->getPackageName($themePath)] = $themePath;
        }
        $dependencies = $this->dependencyChecker->checkDependencies(array_keys($packageToPath), true);
        foreach ($dependencies as $package => $dependingPackages) {
            if (!empty($dependingPackages)) {
                $messages[] =
                    '<error>' . $packageToPath[$package] .
                    " has the following dependent package(s):</error>" .
                    PHP_EOL . "\t<error>" . implode('</error>' . PHP_EOL . "\t<error>", $dependingPackages)
                    . "</error>";
            }
        }
        return $messages;
    }

    /**
     * Cleanup after updated modules status
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    private function cleanup(InputInterface $input, OutputInterface $output)
    {
        $this->cache->clean();
        $output->writeln('<info>Cache cleared successfully.</info>');

        if ($input->getOption(self::INPUT_KEY_CLEAR_STATIC_CONTENT)) {
            $this->cleanupFiles->clearMaterializedViewFiles();
            $output->writeln('<info>Generated static view files cleared successfully.</info>');
        } else {
            $output->writeln(
                '<error>Alert: Generated static view files were not cleared.'
                . ' You can clear them using the --' . self::INPUT_KEY_CLEAR_STATIC_CONTENT . ' option.'
                . ' Failure to clear static view files might cause display issues in the Admin and storefront.</error>'
            );
        }
    }
}
