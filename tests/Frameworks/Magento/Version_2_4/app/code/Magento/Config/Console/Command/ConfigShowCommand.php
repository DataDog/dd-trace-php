<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Config\Console\Command;

use Magento\Config\Console\Command\ConfigShow\ValueProcessor;
use Magento\Framework\App\Config\ConfigPathResolver;
use Magento\Framework\App\Config\ConfigSourceInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Scope\ValidatorInterface;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Config\Model\Config\PathValidatorFactory;

/**
 * Command provides possibility to show saved system configuration.
 *
 * @api
 * @since 101.0.0
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConfigShowCommand extends Command
{
    /**#@+
     * Names of input arguments or options.
     */
    public const INPUT_OPTION_SCOPE = 'scope';
    public const INPUT_OPTION_SCOPE_CODE = 'scope-code';
    public const INPUT_ARGUMENT_PATH = 'path';
    /**#@-*/

    /**
     * @var ValidatorInterface
     */
    private $scopeValidator;

    /**
     * Source of configurations.
     *
     * @var ConfigSourceInterface
     */
    private $configSource;

    /**
     * Config path resolver.
     *
     * @var ConfigPathResolver
     */
    private $pathResolver;

    /**
     * Class for processing value using backend model.
     *
     * @var ValueProcessor
     */
    private $valueProcessor;

    /**
     * The scope of configuration.
     *
     * @var string
     */
    private $scope;

    /**
     * The scope code of configuration.
     *
     * @var string
     */
    private $scopeCode;

    /**
     * The configuration path.
     *
     * @var string
     */
    private $inputPath;

    /**
     * @var PathValidatorFactory
     */
    private $pathValidatorFactory;

    /**
     * @var EmulatedAdminhtmlAreaProcessor
     */
    private $emulatedAreaProcessor;

    /**
     * @param ValidatorInterface $scopeValidator
     * @param ConfigSourceInterface $configSource
     * @param ConfigPathResolver $pathResolver
     * @param ValueProcessor $valueProcessor
     * @param PathValidatorFactory|null $pathValidatorFactory
     * @param EmulatedAdminhtmlAreaProcessor|null $emulatedAreaProcessor
     * @internal param ScopeConfigInterface $appConfig
     */
    public function __construct(
        ValidatorInterface $scopeValidator,
        ConfigSourceInterface $configSource,
        ConfigPathResolver $pathResolver,
        ValueProcessor $valueProcessor,
        ?PathValidatorFactory $pathValidatorFactory = null,
        ?EmulatedAdminhtmlAreaProcessor $emulatedAreaProcessor = null
    ) {
        parent::__construct();
        $this->scopeValidator = $scopeValidator;
        $this->configSource = $configSource;
        $this->pathResolver = $pathResolver;
        $this->valueProcessor = $valueProcessor;
        $this->pathValidatorFactory = $pathValidatorFactory
            ?: ObjectManager::getInstance()->get(PathValidatorFactory::class);
        $this->emulatedAreaProcessor = $emulatedAreaProcessor
            ?: ObjectManager::getInstance()->get(EmulatedAdminhtmlAreaProcessor::class);
    }

    /**
     * @inheritdoc
     * @since 101.0.0
     */
    protected function configure()
    {
        $this->addArgument(
            self::INPUT_ARGUMENT_PATH,
            InputArgument::OPTIONAL,
            'Configuration path, for example section_id/group_id/field_id'
        );
        $this->addOption(
            self::INPUT_OPTION_SCOPE,
            null,
            InputOption::VALUE_OPTIONAL,
            'Scope for configuration, if not specified, then \'default\' scope will be used',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $this->addOption(
            self::INPUT_OPTION_SCOPE_CODE,
            null,
            InputOption::VALUE_OPTIONAL,
            'Scope code (required only if scope is not `default`)',
            ''
        );
        $this->setName('config:show')
            ->setDescription(
                'Shows configuration value for given path. If path is not specified, all saved values will be shown'
            );
        parent::configure();
    }

    /**
     * Displays configuration value for given configuration path.
     *
     * Shows error message if configuration for given path doesn't exist
     * or scope/scope-code doesn't pass validation.
     *
     * @inheritdoc
     * @since 101.0.0
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->scope = $input->getOption(self::INPUT_OPTION_SCOPE);
            $this->scopeCode = $input->getOption(self::INPUT_OPTION_SCOPE_CODE);
            $inputPath = $input->getArgument(self::INPUT_ARGUMENT_PATH);
            $this->inputPath = $inputPath !== null ? trim($inputPath, '/') : '';

            $configValue = $this->emulatedAreaProcessor->process(function () {
                $this->scopeValidator->isValid($this->scope, $this->scopeCode);
                if ($this->inputPath) {
                    $pathValidator = $this->pathValidatorFactory->create();
                    $pathValidator->validate($this->inputPath);
                }

                $configPath = $this->pathResolver->resolve($this->inputPath, $this->scope, $this->scopeCode);

                return $this->configSource->get($configPath);
            });

            $this->outputResult($output, $configValue, $this->inputPath);
            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Outputs single configuration value or list of values if array given.
     *
     * @param OutputInterface $output an OutputInterface instance
     * @param string|array $configValue can be string when $configPath is a path for concreate field.
     * In other cases $configValue should be an array
     * ```php
     * [
     *      'section' =>
     *      [
     *          'group1' =>
     *          [
     *              'field1' => 'value1',
     *              'field2' => 'value2'
     *          ],
     *          'group2' =>
     *          [
     *              'field1' => 'value3'
     *          ]
     *      ]
     * ]
     * ```
     * @param string $configPath base configuration path
     * @return void
     */
    private function outputResult(OutputInterface $output, $configValue, $configPath)
    {
        if (!is_array($configValue)) {
            $value = $this->valueProcessor->process($this->scope, $this->scopeCode, $configValue, $configPath);
            $output->writeln($this->inputPath === $configPath ? $value : sprintf("%s - %s", $configPath, $value));
        } elseif (is_array($configValue)) {
            foreach ($configValue as $name => $value) {
                $childPath = empty($configPath) ? $name : ($configPath . '/' . $name);
                $this->outputResult($output, $value, $childPath);
            }
        }
    }
}
