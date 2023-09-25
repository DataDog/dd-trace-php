<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Setup\Test\Unit\Model {

    use Magento\Backend\Setup\ConfigOptionsList;
    use Magento\Framework\App\Area;
    use Magento\Framework\App\Cache\Manager;
    use Magento\Framework\App\DeploymentConfig;
    use Magento\Framework\App\DeploymentConfig\Reader;
    use Magento\Framework\App\DeploymentConfig\Writer;
    use Magento\Framework\App\Filesystem\DirectoryList;
    use Magento\Framework\App\MaintenanceMode;
    use Magento\Framework\App\ResourceConnection;
    use Magento\Framework\App\State\CleanupFiles;
    use Magento\Framework\Component\ComponentRegistrar;
    use Magento\Framework\Config\ConfigOptionsListConstants;
    use Magento\Framework\Config\File\ConfigFilePool;
    use Magento\Framework\DB\Adapter\AdapterInterface;
    use Magento\Framework\DB\Ddl\Table;
    use Magento\Framework\DB\Select;
    use Magento\Framework\Exception\FileSystemException;
    use Magento\Framework\Exception\LocalizedException;
    use Magento\Framework\Exception\RuntimeException;
    use Magento\Framework\Filesystem;
    use Magento\Framework\Filesystem\Directory\WriteInterface;
    use Magento\Framework\Filesystem\DriverPool;
    use Magento\Framework\Model\ResourceModel\Db\Context;
    use Magento\Framework\Module\ModuleList\Loader;
    use Magento\Framework\Module\ModuleListInterface;
    use Magento\Framework\Module\ModuleResource;
    use Magento\Framework\ObjectManagerInterface;
    use Magento\Framework\Registry;
    use Magento\Framework\Setup\FilePermissions;
    use Magento\Framework\Setup\LoggerInterface;
    use Magento\Framework\Setup\Patch\PatchApplier;
    use Magento\Framework\Setup\Patch\PatchApplierFactory;
    use Magento\Framework\Setup\SampleData\State;
    use Magento\Framework\Setup\SchemaListener;
    use Magento\Framework\Validation\ValidationException;
    use Magento\RemoteStorage\Driver\DriverException;
    use Magento\RemoteStorage\Setup\ConfigOptionsList as RemoteStorageValidator;
    use Magento\Setup\Console\Command\InstallCommand;
    use Magento\Setup\Controller\ResponseTypeInterface;
    use Magento\Setup\Model\AdminAccount;
    use Magento\Setup\Model\AdminAccountFactory;
    use Magento\Setup\Model\ConfigModel;
    use Magento\Setup\Model\DeclarationInstaller;
    use Magento\Setup\Model\Installer;
    use Magento\Setup\Model\ObjectManagerProvider;
    use Magento\Setup\Model\PhpReadinessCheck;
    use Magento\Setup\Model\SearchConfig;
    use Magento\Setup\Module\ConnectionFactory;
    use Magento\Setup\Module\DataSetup;
    use Magento\Setup\Module\DataSetupFactory;
    use Magento\Setup\Module\Setup;
    use Magento\Setup\Module\SetupFactory;
    use Magento\Setup\Validator\DbValidator;
    use PHPUnit\Framework\MockObject\MockObject;
    use PHPUnit\Framework\TestCase;
    use ReflectionException;

    /**
     * @SuppressWarnings(PHPMD.TooManyFields)
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    class InstallerTest extends TestCase
    {
        /**
         * @var array
         */
        private $request = [
            ConfigOptionsListConstants::INPUT_KEY_DB_HOST => '127.0.0.1',
            ConfigOptionsListConstants::INPUT_KEY_DB_NAME => 'magento',
            ConfigOptionsListConstants::INPUT_KEY_DB_USER => 'magento',
            ConfigOptionsListConstants::INPUT_KEY_ENCRYPTION_KEY => 'encryption_key',
            ConfigOptionsList::INPUT_KEY_BACKEND_FRONTNAME => 'backend'
        ];

        /**
         * @var Installer|MockObject
         */
        private $object;

        /**
         * @var FilePermissions|MockObject
         */
        private $filePermissions;

        /**
         * @var Writer|MockObject
         */
        private $configWriter;

        /**
         * @var Reader|MockObject
         */
        private $configReader;

        /**
         * @var DeploymentConfig|MockObject
         */
        private $config;

        /**
         * @var ModuleListInterface|MockObject
         */
        private $moduleList;

        /**
         * @var Loader|MockObject
         */
        private $moduleLoader;

        /**
         * @var AdminAccountFactory|MockObject
         */
        private $adminFactory;

        /**
         * @var LoggerInterface|MockObject
         */
        private $logger;

        /**
         * @var AdapterInterface|MockObject
         */
        private $connection;

        /**
         * @var MaintenanceMode|MockObject
         */
        private $maintenanceMode;

        /**
         * @var Filesystem|MockObject
         */
        private $filesystem;

        /**
         * @var MockObject
         */
        private $objectManager;

        /**
         * @var ConfigModel|MockObject
         */
        private $configModel;

        /**
         * @var CleanupFiles|MockObject
         */
        private $cleanupFiles;

        /**
         * @var DbValidator|MockObject
         */
        private $dbValidator;

        /**
         * @var SetupFactory|MockObject
         */
        private $setupFactory;

        /**
         * @var DataSetupFactory|MockObject
         */
        private $dataSetupFactory;

        /**
         * @var State|MockObject
         */
        private $sampleDataState;

        /**
         * @var ComponentRegistrar|MockObject
         */
        private $componentRegistrar;

        /**
         * @var MockObject|PhpReadinessCheck
         */
        private $phpReadinessCheck;

        /**
         * @var DeclarationInstaller|MockObject
         */
        private $declarationInstallerMock;

        /**
         * @var SchemaListener|MockObject
         */
        private $schemaListenerMock;

        /**
         * Sample DB configuration segment
         * @var array
         */
        private static $dbConfig = [
            'default' => [
                ConfigOptionsListConstants::KEY_HOST => '127.0.0.1',
                ConfigOptionsListConstants::KEY_NAME => 'magento',
                ConfigOptionsListConstants::KEY_USER => 'magento',
                ConfigOptionsListConstants::KEY_PASSWORD => ''
            ]
        ];

        /**
         * @var Context|MockObject
         */
        private $contextMock;

        /**
         * @var PatchApplier|MockObject
         */
        private $patchApplierMock;

        /**
         * @var PatchApplierFactory|MockObject
         */
        private $patchApplierFactoryMock;

        /**
         * @inheritdoc
         */
        protected function setUp(): void
        {
            $this->filePermissions = $this->createMock(FilePermissions::class);
            $this->configWriter = $this->createMock(Writer::class);
            $this->configReader = $this->createMock(Reader::class);
            $this->config = $this->createMock(DeploymentConfig::class);

            $this->moduleList = $this->getMockForAbstractClass(ModuleListInterface::class);
            $this->moduleList->expects($this->any())->method('getNames')->willReturn(
                ['Foo_One', 'Bar_Two']
            );
            $this->moduleLoader = $this->createMock(Loader::class);
            $this->adminFactory = $this->createMock(AdminAccountFactory::class);
            $this->logger = $this->getMockForAbstractClass(LoggerInterface::class);
            $this->connection = $this->getMockForAbstractClass(AdapterInterface::class);
            $this->maintenanceMode = $this->createMock(MaintenanceMode::class);
            $this->filesystem = $this->createMock(Filesystem::class);
            $this->objectManager = $this->getMockForAbstractClass(ObjectManagerInterface::class);
            $this->contextMock =
                $this->createMock(Context::class);
            $this->configModel = $this->createMock(ConfigModel::class);
            $this->cleanupFiles = $this->createMock(CleanupFiles::class);
            $this->dbValidator = $this->createMock(DbValidator::class);
            $this->setupFactory = $this->createMock(SetupFactory::class);
            $this->dataSetupFactory = $this->createMock(DataSetupFactory::class);
            $this->sampleDataState = $this->createMock(State::class);
            $this->componentRegistrar =
                $this->createMock(ComponentRegistrar::class);
            $this->phpReadinessCheck = $this->createMock(PhpReadinessCheck::class);
            $this->declarationInstallerMock = $this->createMock(DeclarationInstaller::class);
            $this->schemaListenerMock = $this->createMock(SchemaListener::class);
            $this->patchApplierFactoryMock = $this->createMock(PatchApplierFactory::class);
            $this->patchApplierMock = $this->createMock(PatchApplier::class);
            $this->patchApplierFactoryMock->expects($this->any())->method('create')->willReturn(
                $this->patchApplierMock
            );
            $this->object = $this->createObject();
        }

        /**
         * Instantiates the object with mocks
         * @param MockObject|bool $connectionFactory
         * @param MockObject|bool $objectManagerProvider
         * @return Installer
         */
        private function createObject($connectionFactory = false, $objectManagerProvider = false)
        {
            if (!$connectionFactory) {
                $connectionFactory = $this->createMock(ConnectionFactory::class);
                $connectionFactory->expects($this->any())->method('create')->willReturn($this->connection);
            }
            if (!$objectManagerProvider) {
                $objectManagerProvider =
                    $this->createMock(ObjectManagerProvider::class);
                $objectManagerProvider->expects($this->any())->method('get')->willReturn($this->objectManager);
            }

            $installer = $this->getMockBuilder(Installer::class)
                ->enableOriginalConstructor()
                ->onlyMethods(['getModuleResource'])
                ->setConstructorArgs([
                    'filePermissions' => $this->filePermissions,
                    'deploymentConfigWriter' => $this->configWriter,
                    'deploymentConfigReader' => $this->configReader,
                    'deploymentConfig' => $this->config,
                    'moduleList' => $this->moduleList,
                    'moduleLoader' => $this->moduleLoader,
                    'adminAccountFactory' => $this->adminFactory,
                    'log' => $this->logger,
                    'connectionFactory' => $connectionFactory,
                    'maintenanceMode' => $this->maintenanceMode,
                    'filesystem' => $this->filesystem,
                    'objectManagerProvider' => $objectManagerProvider,
                    'context' => $this->contextMock,
                    'setupConfigModel' => $this->configModel,
                    'cleanupFiles' => $this->cleanupFiles,
                    'dbValidator' => $this->dbValidator,
                    'setupFactory' => $this->setupFactory,
                    'dataSetupFactory' => $this->dataSetupFactory,
                    'sampleDataState' => $this->sampleDataState,
                    'componentRegistrar' => $this->componentRegistrar,
                    'phpReadinessCheck' => $this->phpReadinessCheck,
                ])
                ->getMock();

            return $installer;
        }

        /**
         * @param array $request
         * @param array $logMessages
         * @dataProvider installDataProvider
         * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
         */
        public function testInstall(array $request, array $logMessages)
        {
            $this->moduleList->method('getOne')
                ->willReturnMap(
                    [
                        ['Foo_One', ['setup_version' => '2.0.0']],
                        ['Bar_Two', ['setup_version' => null]]
                    ]
                );

            $this->config->expects($this->atLeastOnce())
                ->method('get')
                ->willReturnMap(
                    [
                        [ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTION_DEFAULT, null, true],
                        [ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY, null, true],
                        ['modules/Magento_User', null, '1']
                    ]
                );
            $allModules = ['Foo_One' => [], 'Bar_Two' => []];

            $this->declarationInstallerMock->expects($this->once())->method('installSchema');
            $this->moduleLoader->expects($this->any())->method('load')->willReturn($allModules);
            $setup = $this->createMock(Setup::class);
            $table = $this->createMock(Table::class);
            $connection = $this->getMockBuilder(AdapterInterface::class)
                ->onlyMethods(['getTables', 'newTable'])
                ->addMethods(['getSchemaListener'])
                ->getMockForAbstractClass();
            $connection->expects($this->any())->method('getSchemaListener')->willReturn($this->schemaListenerMock);
            $connection->expects($this->once())->method('getTables')->willReturn([]);
            $setup->expects($this->any())->method('getConnection')->willReturn($connection);
            $table->expects($this->any())->method('addColumn')->willReturn($table);
            $table->expects($this->any())->method('setComment')->willReturn($table);
            $table->expects($this->any())->method('addIndex')->willReturn($table);
            $connection->expects($this->any())->method('newTable')->willReturn($table);
            $resource = $this->createMock(ResourceConnection::class);
            $this->contextMock->expects($this->any())->method('getResources')->willReturn($resource);
            $resource->expects($this->any())->method('getConnection')->willReturn($connection);

            $moduleResource = $this->getMockBuilder(ModuleResource::class)
                ->enableOriginalConstructor()
                ->onlyMethods(['getDbVersion', 'getDataVersion'])
                ->setConstructorArgs(['context' => $this->contextMock])
                ->getMock();
            $moduleResource->method('getDbVersion')->willReturnOnConsecutiveCalls(false, '2.1.0');
            $moduleResource->method('getDataVersion')->willReturn(false);
            $this->object->method('getModuleResource')->willReturn($moduleResource);

            $dataSetup = $this->createMock(DataSetup::class);
            $dataSetup->expects($this->any())->method('getConnection')->willReturn($connection);
            $cacheManager = $this->createMock(Manager::class);
            $cacheManager->expects($this->any())->method('getAvailableTypes')->willReturn(['foo', 'bar']);
            $cacheManager->expects($this->exactly(3))->method('setEnabled')->willReturn(['foo', 'bar']);
            $cacheManager->expects($this->exactly(3))->method('clean');
            $cacheManager->expects($this->exactly(3))->method('getStatus')->willReturn(['foo' => 1, 'bar' => 1]);
            $appState = $this->getMockBuilder(\Magento\Framework\App\State::class)
                ->disableOriginalConstructor()
                ->disableArgumentCloning()
                ->getMock();
            $appState->expects($this->once())
                ->method('setAreaCode')
                ->with(Area::AREA_GLOBAL);
            $registry = $this->createMock(Registry::class);
            $searchConfigMock = $this->getMockBuilder(SearchConfig::class)->disableOriginalConstructor()->getMock();

            $remoteStorageValidatorMock = $this->getMockBuilder(RemoteStorageValidator::class)
                ->disableOriginalConstructor()
                ->getMock();

            $this->configWriter->expects(static::never())->method('checkIfWritable');

            $this->setupFactory->expects($this->atLeastOnce())->method('create')->with($resource)->willReturn($setup);
            $this->dataSetupFactory->expects($this->atLeastOnce())->method('create')->willReturn($dataSetup);
            $this->objectManager->expects($this->any())
                ->method('create')
                ->willReturnMap(
                    [
                        [Manager::class, [], $cacheManager],
                        [\Magento\Framework\App\State::class, [], $appState],
                        [
                            PatchApplierFactory::class,
                            ['objectManager' => $this->objectManager],
                            $this->patchApplierFactoryMock
                        ],
                    ]
                );
            $this->patchApplierMock->expects($this->exactly(2))->method('applySchemaPatch')->willReturnMap(
                [
                    ['Bar_Two'],
                    ['Foo_One']
                ]
            );
            $this->patchApplierMock->expects($this->exactly(2))->method('applyDataPatch')->willReturnMap(
                [
                    ['Bar_Two'],
                    ['Foo_One']
                ]
            );
            $this->objectManager->expects($this->any())
                ->method('get')
                ->willReturnMap(
                    [
                        [\Magento\Framework\App\State::class, $appState],
                        [Manager::class, $cacheManager],
                        [DeclarationInstaller::class, $this->declarationInstallerMock],
                        [Registry::class, $registry],
                        [SearchConfig::class, $searchConfigMock],
                        [RemoteStorageValidator::class, $remoteStorageValidatorMock]
                    ]
                );
            $this->adminFactory->expects($this->any())->method('create')->willReturn(
                $this->createMock(AdminAccount::class)
            );
            $this->sampleDataState->expects($this->once())->method('hasError')->willReturn(true);
            $this->phpReadinessCheck->expects($this->once())->method('checkPhpExtensions')->willReturn(
                ['responseType' => ResponseTypeInterface::RESPONSE_TYPE_SUCCESS]
            );
            $this->filePermissions->expects($this->any())
                ->method('getMissingWritablePathsForInstallation')
                ->willReturn([]);
            $this->filePermissions->expects($this->once())
                ->method('getMissingWritableDirectoriesForDbUpgrade')
                ->willReturn([]);
            call_user_func_array(
                [
                    $this->logger->expects($this->exactly(count($logMessages)))->method('log'),
                    'withConsecutive'
                ],
                $logMessages
            );
            $this->logger->expects($this->exactly(2))
                ->method('logSuccess')
                ->withConsecutive(
                    ['Magento installation complete.'],
                    ['Magento Admin URI: /']
                );

            $this->object->install($request);
        }

        /**
         * @return array
         * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
         */
        public function installDataProvider()
        {
            return [
                [
                    'request' => $this->request,
                    'logMessages' => [
                        ['Starting Magento installation:'],
                        ['File permissions check...'],
                        ['Required extensions check...'],
                        ['Enabling Maintenance Mode...'],
                        ['Installing deployment configuration...'],
                        ['Installing database schema:'],
                        ['Schema creation/updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Schema post-updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Installing search configuration...'],
                        ['Validating remote storage configuration...'],
                        ['Installing user configuration...'],
                        ['Enabling caches:'],
                        ['Current status:'],
                        ['foo: 1'],
                        ['bar: 1'],
                        ['Installing data...'],
                        ['Data install/update:'],
                        ['Disabling caches:'],
                        ['Current status:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Data post-updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Enabling caches:'],
                        ['Current status:'],
                        ['Caches clearing:'],
                        ['Cache cleared successfully'],
                        ['Disabling Maintenance Mode:'],
                        ['Post installation file permissions check...'],
                        ['Write installation date...'],
                        ['Sample Data is installed with errors. See log file for details']
                    ],
                ],
                [
                    'request' => [
                        ConfigOptionsListConstants::INPUT_KEY_DB_HOST => '127.0.0.1',
                        ConfigOptionsListConstants::INPUT_KEY_DB_NAME => 'magento',
                        ConfigOptionsListConstants::INPUT_KEY_DB_USER => 'magento',
                        ConfigOptionsListConstants::INPUT_KEY_ENCRYPTION_KEY => 'encryption_key',
                        ConfigOptionsList::INPUT_KEY_BACKEND_FRONTNAME => 'backend',
                        AdminAccount::KEY_USER => 'admin',
                        AdminAccount::KEY_PASSWORD => '123',
                        AdminAccount::KEY_EMAIL => 'admin@example.com',
                        AdminAccount::KEY_FIRST_NAME => 'John',
                        AdminAccount::KEY_LAST_NAME => 'Doe'
                    ],
                    'logMessages' => [
                        ['Starting Magento installation:'],
                        ['File permissions check...'],
                        ['Required extensions check...'],
                        ['Enabling Maintenance Mode...'],
                        ['Installing deployment configuration...'],
                        ['Installing database schema:'],
                        ['Schema creation/updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Schema post-updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Installing search configuration...'],
                        ['Validating remote storage configuration...'],
                        ['Installing user configuration...'],
                        ['Enabling caches:'],
                        ['Current status:'],
                        ['foo: 1'],
                        ['bar: 1'],
                        ['Installing data...'],
                        ['Data install/update:'],
                        ['Disabling caches:'],
                        ['Current status:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Data post-updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Enabling caches:'],
                        ['Current status:'],
                        ['Installing admin user...'],
                        ['Caches clearing:'],
                        ['Cache cleared successfully'],
                        ['Disabling Maintenance Mode:'],
                        ['Post installation file permissions check...'],
                        ['Write installation date...'],
                        ['Sample Data is installed with errors. See log file for details']
                    ],
                ],
            ];
        }

        /**
         * Test the installation with order increment prefix set and enabled
         *
         * @param array $request
         * @param array $logMessages
         * @throws RuntimeException
         * @throws FileSystemException
         * @throws LocalizedException
         * @dataProvider installWithOrderIncrementPrefixDataProvider
         * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
         */
        public function testInstallWithOrderIncrementPrefix(array $request, array $logMessages)
        {
            $this->moduleList->method('getOne')
                ->willReturnMap(
                    [
                        ['Foo_One', ['setup_version' => '2.0.0']],
                        ['Bar_Two', ['setup_version' => null]]
                    ]
                );

            $this->config->expects($this->atLeastOnce())
                ->method('get')
                ->willReturnMap(
                    [
                        [ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTION_DEFAULT, null, true],
                        [ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY, null, true],
                        ['modules/Magento_User', null, '1']
                    ]
                );
            $allModules = ['Foo_One' => [], 'Bar_Two' => []];

            $this->declarationInstallerMock->expects($this->once())->method('installSchema');
            $this->moduleLoader->expects($this->any())->method('load')->willReturn($allModules);
            $setup = $this->createMock(Setup::class);
            $table = $this->createMock(Table::class);

            $select = $this->createMock(Select::class);
            $select->expects($this->any())->method('from')->willReturn($select);
            $select->expects($this->any())->method('where')->willReturn($select);

            $connection = $this->getMockBuilder(AdapterInterface::class)
                ->onlyMethods(['getTables', 'newTable','select'])
                ->addMethods(['getSchemaListener'])
                ->getMockForAbstractClass();
            $connection->expects($this->any())->method('getSchemaListener')->willReturn($this->schemaListenerMock);
            $connection->expects($this->once())->method('getTables')->willReturn([]);
            $connection->expects($this->any())->method('select')->willReturn($select);

            $connection->expects($this->atLeastOnce())->method('fetchRow')->willReturn([
                'entity_store_id' => 1,
                'profile_id' => 1
            ]);
            $connection->expects($this->exactly(2))->method('update');

            $setup->expects($this->any())->method('getConnection')->willReturn($connection);
            $table->expects($this->any())->method('addColumn')->willReturn($table);
            $table->expects($this->any())->method('setComment')->willReturn($table);
            $table->expects($this->any())->method('addIndex')->willReturn($table);
            $connection->expects($this->any())->method('newTable')->willReturn($table);
            $resource = $this->createMock(ResourceConnection::class);
            $this->contextMock->expects($this->any())->method('getResources')->willReturn($resource);
            $resource->expects($this->any())->method('getConnection')->willReturn($connection);

            $moduleResource = $this->getMockBuilder(ModuleResource::class)
                ->enableOriginalConstructor()
                ->onlyMethods(['getDbVersion', 'getDataVersion'])
                ->setConstructorArgs(['context' => $this->contextMock])
                ->getMock();
            $moduleResource->method('getDbVersion')->willReturnOnConsecutiveCalls(false, '2.1.0');
            $moduleResource->method('getDataVersion')->willReturn(false);
            $this->object->method('getModuleResource')->willReturn($moduleResource);

            $dataSetup = $this->createMock(DataSetup::class);
            $dataSetup->expects($this->any())->method('getConnection')->willReturn($connection);
            $cacheManager = $this->createMock(Manager::class);
            $cacheManager->expects($this->any())->method('getAvailableTypes')->willReturn(['foo', 'bar']);
            $cacheManager->expects($this->exactly(3))->method('setEnabled')->willReturn(['foo', 'bar']);
            $cacheManager->expects($this->exactly(3))->method('clean');
            $cacheManager->expects($this->exactly(3))->method('getStatus')->willReturn(['foo' => 1, 'bar' => 1]);
            $appState = $this->getMockBuilder(\Magento\Framework\App\State::class)
                ->disableOriginalConstructor()
                ->disableArgumentCloning()
                ->getMock();
            $appState->expects($this->once())
                ->method('setAreaCode')
                ->with(Area::AREA_GLOBAL);
            $registry = $this->createMock(Registry::class);
            $searchConfigMock = $this->getMockBuilder(SearchConfig::class)->disableOriginalConstructor()->getMock();

            $remoteStorageValidatorMock = $this->getMockBuilder(RemoteStorageValidator::class)
                ->disableOriginalConstructor()
                ->getMock();

            $this->configWriter->expects(static::never())->method('checkIfWritable');

            $this->setupFactory->expects($this->atLeastOnce())->method('create')->with($resource)->willReturn($setup);
            $this->dataSetupFactory->expects($this->atLeastOnce())->method('create')->willReturn($dataSetup);
            $this->objectManager->expects($this->any())
                ->method('create')
                ->willReturnMap(
                    [
                        [Manager::class, [], $cacheManager],
                        [\Magento\Framework\App\State::class, [], $appState],
                        [
                            PatchApplierFactory::class,
                            ['objectManager' => $this->objectManager],
                            $this->patchApplierFactoryMock
                        ],
                    ]
                );
            $this->patchApplierMock->expects($this->exactly(2))->method('applySchemaPatch')->willReturnMap(
                [
                    ['Bar_Two'],
                    ['Foo_One']
                ]
            );
            $this->patchApplierMock->expects($this->exactly(2))->method('applyDataPatch')->willReturnMap(
                [
                    ['Bar_Two'],
                    ['Foo_One']
                ]
            );
            $this->objectManager->expects($this->any())
                ->method('get')
                ->willReturnMap(
                    [
                        [\Magento\Framework\App\State::class, $appState],
                        [Manager::class, $cacheManager],
                        [DeclarationInstaller::class, $this->declarationInstallerMock],
                        [Registry::class, $registry],
                        [SearchConfig::class, $searchConfigMock],
                        [RemoteStorageValidator::class, $remoteStorageValidatorMock]
                    ]
                );
            $this->adminFactory->expects($this->any())->method('create')->willReturn(
                $this->createMock(AdminAccount::class)
            );
            $this->sampleDataState->expects($this->once())->method('hasError')->willReturn(true);
            $this->phpReadinessCheck->expects($this->once())->method('checkPhpExtensions')->willReturn(
                ['responseType' => ResponseTypeInterface::RESPONSE_TYPE_SUCCESS]
            );
            $this->filePermissions->expects($this->any())
                ->method('getMissingWritablePathsForInstallation')
                ->willReturn([]);
            $this->filePermissions->expects($this->once())
                ->method('getMissingWritableDirectoriesForDbUpgrade')
                ->willReturn([]);
            call_user_func_array(
                [
                    $this->logger->expects($this->exactly(count($logMessages)))->method('log'),
                    'withConsecutive'
                ],
                $logMessages
            );
            $this->logger->expects($this->exactly(2))
                ->method('logSuccess')
                ->withConsecutive(
                    ['Magento installation complete.'],
                    ['Magento Admin URI: /']
                );

            $this->object->install($request);
        }

        /**
         * @return array
         * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
         */
        public function installWithOrderIncrementPrefixDataProvider(): array
        {
            return [
                [
                    'request' => [
                        ConfigOptionsListConstants::INPUT_KEY_DB_HOST => '127.0.0.1',
                        ConfigOptionsListConstants::INPUT_KEY_DB_NAME => 'magento',
                        ConfigOptionsListConstants::INPUT_KEY_DB_USER => 'magento',
                        ConfigOptionsListConstants::INPUT_KEY_ENCRYPTION_KEY => 'encryption_key',
                        ConfigOptionsList::INPUT_KEY_BACKEND_FRONTNAME => 'backend',
                        InstallCommand::INPUT_KEY_SALES_ORDER_INCREMENT_PREFIX => 'ORD'
                    ],
                    'logMessages' => [
                        ['Starting Magento installation:'],
                        ['File permissions check...'],
                        ['Required extensions check...'],
                        ['Enabling Maintenance Mode...'],
                        ['Installing deployment configuration...'],
                        ['Installing database schema:'],
                        ['Schema creation/updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Schema post-updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Installing search configuration...'],
                        ['Validating remote storage configuration...'],
                        ['Installing user configuration...'],
                        ['Enabling caches:'],
                        ['Current status:'],
                        ['foo: 1'],
                        ['bar: 1'],
                        ['Installing data...'],
                        ['Data install/update:'],
                        ['Disabling caches:'],
                        ['Current status:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Data post-updates:'],
                        ['Module \'Foo_One\':'],
                        ['Module \'Bar_Two\':'],
                        ['Enabling caches:'],
                        ['Current status:'],
                        ['Creating sales order increment prefix...'], // << added
                        ['Caches clearing:'],
                        ['Cache cleared successfully'],
                        ['Disabling Maintenance Mode:'],
                        ['Post installation file permissions check...'],
                        ['Write installation date...'],
                        ['Sample Data is installed with errors. See log file for details']
                    ],
                ],
            ];
        }

        /**
         * Test installation with invalid remote storage configuration raises ValidationException via validation
         * and reverts configuration back to local file driver
         *
         * @param bool $isDeploymentConfigWritable
         * @dataProvider installWithInvalidRemoteStorageConfigurationDataProvider
         * @throws \Magento\Framework\Exception\FileSystemException
         * @throws \Magento\Framework\Exception\LocalizedException
         * @throws \Magento\Framework\Exception\RuntimeException
         * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
         */
        public function testInstallWithInvalidRemoteStorageConfiguration(bool $isDeploymentConfigWritable)
        {
            $request = $this->request;

            $logMessages = [
                ['Starting Magento installation:'],
                ['File permissions check...'],
                ['Required extensions check...'],
                ['Enabling Maintenance Mode...'],
                ['Installing deployment configuration...'],
                ['Installing database schema:'],
                ['Schema creation/updates:'],
                ['Module \'Foo_One\':'],
                ['Module \'Bar_Two\':'],
                ['Schema post-updates:'],
                ['Module \'Foo_One\':'],
                ['Module \'Bar_Two\':'],
                ['Installing search configuration...'],
                ['Validating remote storage configuration...'],
            ];

            $this->config->expects(static::atLeastOnce())
                ->method('get')
                ->willReturnMap(
                    [
                        [ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTION_DEFAULT, null, true],
                        [ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY, null, true],
                        ['modules/Magento_User', null, '1']
                    ]
                );
            $this->moduleList->method('getOne')->willReturn(['setup_version' => '2.0.0']);
            $allModules = ['Foo_One' => [], 'Bar_Two' => []];

            $this->declarationInstallerMock->expects(static::once())->method('installSchema');
            $this->moduleLoader->expects(static::exactly(2))->method('load')->willReturn($allModules);
            $setup = $this->createMock(Setup::class);
            $table = $this->createMock(Table::class);
            $connection = $this->getMockBuilder(AdapterInterface::class)
                ->onlyMethods(['newTable', 'getTables'])
                ->addMethods(['getSchemaListener'])
                ->getMockForAbstractClass();
            $connection->expects(static::any())->method('getSchemaListener')->willReturn($this->schemaListenerMock);
            $connection->expects(static::once())->method('getTables')->willReturn([]);
            $setup->expects(static::any())->method('getConnection')->willReturn($connection);
            $table->expects(static::any())->method('addColumn')->willReturn($table);
            $table->expects(static::any())->method('setComment')->willReturn($table);
            $table->expects(static::any())->method('addIndex')->willReturn($table);
            $connection->expects(static::any())->method('newTable')->willReturn($table);

            $resource = $this->createMock(ResourceConnection::class);
            $resource->expects(static::any())->method('getConnection')->willReturn($connection);

            $this->contextMock->expects(static::exactly(2))->method('getResources')->willReturn($resource);
            $this->setModuleResource();

            $dataSetup = $this->createMock(DataSetup::class);
            $dataSetup->expects(static::never())->method('getConnection');

            $cacheManager = $this->createMock(Manager::class);
            $cacheManager->expects(static::never())->method('getAvailableTypes');

            $appState = $this->getMockBuilder(\Magento\Framework\App\State::class)
                ->disableOriginalConstructor()
                ->disableArgumentCloning()
                ->getMock();
            $registry = $this->createMock(Registry::class);
            $searchConfigMock = $this->getMockBuilder(SearchConfig::class)->disableOriginalConstructor()->getMock();

            $remoteStorageValidatorMock = $this->getMockBuilder(RemoteStorageValidator::class)
                ->disableOriginalConstructor()
                ->getMock();

            $remoteStorageValidatorMock
                ->expects(static::once())
                ->method('validate')
                ->with($request, $this->config)
                ->willReturn(['Invalid Remote Storage!']);

            $this->expectException(ValidationException::class);

            $this->configWriter
                ->expects(static::once())
                ->method('checkIfWritable')
                ->willReturn($isDeploymentConfigWritable);

            $remoteStorageReversionArguments = [
                [
                    ConfigFilePool::APP_ENV => [
                        'remote_storage' => [
                            'driver' => 'file'
                        ]
                    ]
                ],
                true
            ];

            if ($isDeploymentConfigWritable) { // assert remote storage reversion is attempted
                $this->configWriter
                    ->method('saveConfig')
                    ->withConsecutive([], [], $remoteStorageReversionArguments);
            } else { // assert remote storage reversion is never attempted
                $this->configWriter
                    ->expects(static::any())
                    ->method('saveConfig')
                    ->willReturnCallback(function (array $data, $override) use ($remoteStorageReversionArguments) {
                        $this->assertNotEquals($remoteStorageReversionArguments, [$data, $override]);
                    });
            }

            $this->setupFactory->expects(static::once())->method('create')->with($resource)->willReturn($setup);

            $this->objectManager->expects(static::any())
                ->method('create')
                ->willReturnMap(
                    [
                        [Manager::class, [], $cacheManager],
                        [\Magento\Framework\App\State::class, [], $appState],
                        [
                            PatchApplierFactory::class,
                            ['objectManager' => $this->objectManager],
                            $this->patchApplierFactoryMock
                        ]
                    ]
                );
            $this->patchApplierMock->expects(static::exactly(2))->method('applySchemaPatch')->willReturnMap(
                [
                    ['Bar_Two'],
                    ['Foo_One']
                ]
            );
            $this->objectManager->expects(static::any())
                ->method('get')
                ->willReturnMap(
                    [
                        [\Magento\Framework\App\State::class, $appState],
                        [Manager::class, $cacheManager],
                        [DeclarationInstaller::class, $this->declarationInstallerMock],
                        [Registry::class, $registry],
                        [SearchConfig::class, $searchConfigMock],
                        [RemoteStorageValidator::class, $remoteStorageValidatorMock]
                    ]
                );

            $this->sampleDataState->expects(static::never())->method('hasError');

            $this->phpReadinessCheck->expects(static::once())->method('checkPhpExtensions')->willReturn(
                ['responseType' => ResponseTypeInterface::RESPONSE_TYPE_SUCCESS]
            );

            $this->filePermissions->expects(static::exactly(2))
                ->method('getMissingWritablePathsForInstallation')
                ->willReturn([]);

            call_user_func_array(
                [
                    $this->logger->expects(static::exactly(count($logMessages)))->method('log'),
                    'withConsecutive'
                ],
                $logMessages
            );

            $this->logger->expects(static::never())->method('logSuccess');

            $this->object->install($request);
        }

        /**
         * @return array
         */
        public function installWithInvalidRemoteStorageConfigurationDataProvider()
        {
            return [
                [true],
                [false]
            ];
        }

        /**
         * Test that installation with unresolvable remote storage validator object still produces successful install
         * in case RemoteStorage module is not available.
         *
         * @throws \Magento\Framework\Exception\FileSystemException
         * @throws \Magento\Framework\Exception\LocalizedException
         * @throws \Magento\Framework\Exception\RuntimeException
         * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
         */
        public function testInstallWithUnresolvableRemoteStorageValidator()
        {
            $request = $this->request;

            // every log message call is expected
            $logMessages = $this->installDataProvider()[0]['logMessages'];

            $this->config->expects(static::atLeastOnce())
                ->method('get')
                ->willReturnMap(
                    [
                        [ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTION_DEFAULT, null, true],
                        [ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY, null, true],
                        ['modules/Magento_User', null, '1']
                    ]
                );
            $allModules = ['Foo_One' => [], 'Bar_Two' => []];
            $this->moduleList->method('getOne')->willReturn(['setup_version' => '2.0.0']);

            $this->declarationInstallerMock->expects(static::once())->method('installSchema');
            $this->moduleLoader->expects(static::any())->method('load')->willReturn($allModules);
            $setup = $this->createMock(Setup::class);
            $table = $this->createMock(Table::class);
            $connection = $this->getMockBuilder(AdapterInterface::class)
                ->addMethods(['getSchemaListener'])
                ->onlyMethods(['getTables', 'newTable'])
                ->getMockForAbstractClass();
            $connection->expects(static::any())->method('getSchemaListener')->willReturn($this->schemaListenerMock);
            $connection->expects(static::once())->method('getTables')->willReturn([]);
            $setup->expects(static::any())->method('getConnection')->willReturn($connection);
            $table->expects(static::any())->method('addColumn')->willReturn($table);
            $table->expects(static::any())->method('setComment')->willReturn($table);
            $table->expects(static::any())->method('addIndex')->willReturn($table);
            $connection->expects(static::any())->method('newTable')->willReturn($table);
            $resource = $this->createMock(ResourceConnection::class);
            $this->contextMock->expects(static::any())->method('getResources')->willReturn($resource);
            $resource->expects(static::any())->method('getConnection')->willReturn($connection);
            $this->setModuleResource();

            $dataSetup = $this->createMock(DataSetup::class);
            $dataSetup->expects(static::any())->method('getConnection')->willReturn($connection);
            $cacheManager = $this->createMock(Manager::class);
            $cacheManager->expects(static::any())->method('getAvailableTypes')->willReturn(['foo', 'bar']);
            $cacheManager->expects(static::exactly(3))->method('setEnabled')->willReturn(['foo', 'bar']);
            $cacheManager->expects(static::exactly(3))->method('clean');
            $cacheManager->expects(static::exactly(3))->method('getStatus')->willReturn(['foo' => 1, 'bar' => 1]);
            $appState = $this->getMockBuilder(\Magento\Framework\App\State::class)
                ->disableOriginalConstructor()
                ->disableArgumentCloning()
                ->getMock();
            $appState->expects(static::once())
                ->method('setAreaCode')
                ->with(Area::AREA_GLOBAL);
            $registry = $this->createMock(Registry::class);
            $searchConfigMock = $this->getMockBuilder(SearchConfig::class)->disableOriginalConstructor()->getMock();

            $remoteStorageValidatorMock = $this->getMockBuilder(RemoteStorageValidator::class)
                ->disableOriginalConstructor()
                ->getMock();

            $this->configWriter->expects(static::never())->method('checkIfWritable');

            $remoteStorageValidatorMock
                ->expects(static::never())
                ->method('validate');

            $remoteStorageReversionArguments = [
                [
                    ConfigFilePool::APP_ENV => [
                        'remote_storage' => [
                            'driver' => 'file'
                        ]
                    ]
                ],
                true
            ];

            // assert remote storage reversion is never attempted
            $this->configWriter
                ->expects(static::any())
                ->method('saveConfig')
                ->willReturnCallback(function (array $data, $override) use ($remoteStorageReversionArguments) {
                    $this->assertNotEquals($remoteStorageReversionArguments, [$data, $override]);
                });

            $this->setupFactory->expects(static::atLeastOnce())->method('create')->with($resource)->willReturn($setup);
            $this->dataSetupFactory->expects(static::atLeastOnce())->method('create')->willReturn($dataSetup);
            $this->objectManager->expects(static::any())
                ->method('create')
                ->willReturnMap(
                    [
                        [Manager::class, [], $cacheManager],
                        [\Magento\Framework\App\State::class, [], $appState],
                        [
                            PatchApplierFactory::class,
                            ['objectManager' => $this->objectManager],
                            $this->patchApplierFactoryMock
                        ]
                    ]
                );
            $this->patchApplierMock->expects(static::exactly(2))->method('applySchemaPatch')->willReturnMap(
                [
                    ['Bar_Two'],
                    ['Foo_One']
                ]
            );
            $this->patchApplierMock->expects(static::exactly(2))->method('applyDataPatch')->willReturnMap(
                [
                    ['Bar_Two'],
                    ['Foo_One']
                ]
            );

            $objectManagerReturnMapSequence = [
                0 => [Registry::class, $registry],
                1 => [DeclarationInstaller::class, $this->declarationInstallerMock],
                3 => [SearchConfig::class, $searchConfigMock],
                4 => [
                    RemoteStorageValidator::class,
                    new ReflectionException('Class ' . RemoteStorageValidator::class . ' does not exist')
                ],
                5 => [\Magento\Framework\App\State::class, $appState],
                7 => [Registry::class, $registry],
                11 => [Manager::class, $cacheManager]
            ];
            $withArgs = $willReturnArgs = [];

            foreach ($objectManagerReturnMapSequence as $map) {
                list($getArgument, $mockedObject) = $map;

                $withArgs[] = [$getArgument];

                if ($mockedObject instanceof \Exception) {
                    $willReturnArgs[] = $this->throwException($mockedObject);
                } else {
                    $willReturnArgs[] = $mockedObject;
                }
            }
            $this->objectManager
                ->method('get')
                ->withConsecutive(...$withArgs)
                ->willReturnOnConsecutiveCalls(...$willReturnArgs);

            $this->adminFactory->expects(static::any())->method('create')->willReturn(
                $this->createMock(AdminAccount::class)
            );
            $this->sampleDataState->expects(static::once())->method('hasError')->willReturn(true);
            $this->phpReadinessCheck->expects(static::once())->method('checkPhpExtensions')->willReturn(
                ['responseType' => ResponseTypeInterface::RESPONSE_TYPE_SUCCESS]
            );
            $this->filePermissions->expects(static::any())
                ->method('getMissingWritablePathsForInstallation')
                ->willReturn([]);
            $this->filePermissions->expects(static::once())
                ->method('getMissingWritableDirectoriesForDbUpgrade')
                ->willReturn([]);
            call_user_func_array(
                [
                    $this->logger->expects(static::exactly(count($logMessages)))->method('log'),
                    'withConsecutive'
                ],
                $logMessages
            );
            $this->logger->expects(static::exactly(2))
                ->method('logSuccess')
                ->withConsecutive(
                    ['Magento installation complete.'],
                    ['Magento Admin URI: /']
                );

            $this->object->install($request);
        }

        /**
         * Test installation with invalid remote storage configuration is able to be caught earlier than
         * the queued validation step if necessary, and that configuration is reverted back to local file driver.
         *
         * @dataProvider installWithInvalidRemoteStorageConfigurationWithEarlyExceptionDataProvider
         * @throws \Magento\Framework\Exception\FileSystemException
         * @throws \Magento\Framework\Exception\LocalizedException
         * @throws \Magento\Framework\Exception\RuntimeException
         * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
         */
        public function testInstallWithInvalidRemoteStorageConfigurationWithEarlyException(\Exception $exception)
        {
            $request = $this->request;

            $logMessages = [
                ['Starting Magento installation:'],
                ['File permissions check...'],
                ['Required extensions check...'],
                ['Enabling Maintenance Mode...'],
                ['Installing deployment configuration...'],
                ['Installing database schema:'],
                ['Schema creation/updates:']
            ];

            $this->config->expects(static::atLeastOnce())
                ->method('get')
                ->willReturnMap(
                    [
                        [ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTION_DEFAULT, null, true],
                        [ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY, null, true],
                        ['modules/Magento_User', null, '1']
                    ]
                );
            $allModules = ['Foo_One' => [], 'Bar_Two' => []];
            $this->moduleList->method('getOne')->willReturn(['setup_version' => '2.0.0']);

            $this->declarationInstallerMock
                ->expects(static::once())
                ->method('installSchema')
                ->willThrowException($exception);

            $this->expectException(get_class($exception));

            $this->moduleLoader->expects(static::exactly(2))->method('load')->willReturn($allModules);
            $setup = $this->createMock(Setup::class);
            $table = $this->createMock(Table::class);
            $connection = $this->getMockBuilder(AdapterInterface::class)
                ->onlyMethods(['getTables', 'newTable'])
                ->addMethods(['getSchemaListener'])
                ->getMockForAbstractClass();
            $connection->expects(static::any())->method('getSchemaListener')->willReturn($this->schemaListenerMock);
            $connection->expects(static::once())->method('getTables')->willReturn([]);
            $setup->expects(static::any())->method('getConnection')->willReturn($connection);
            $table->expects(static::any())->method('addColumn')->willReturn($table);
            $table->expects(static::any())->method('setComment')->willReturn($table);
            $table->expects(static::any())->method('addIndex')->willReturn($table);
            $connection->expects(static::any())->method('newTable')->willReturn($table);

            $resource = $this->createMock(ResourceConnection::class);
            $this->contextMock->expects(static::once())->method('getResources')->willReturn($resource);

            $dataSetup = $this->createMock(DataSetup::class);
            $dataSetup->expects(static::never())->method('getConnection');

            $cacheManager = $this->createMock(Manager::class);
            $cacheManager->expects(static::never())->method('getAvailableTypes');

            $registry = $this->createMock(Registry::class);

            $remoteStorageValidatorMock = $this->getMockBuilder(RemoteStorageValidator::class)
                ->disableOriginalConstructor()
                ->getMock();

            $remoteStorageValidatorMock
                ->expects(static::never())
                ->method('validate');

            $this->configWriter
                ->expects(static::once())
                ->method('checkIfWritable')
                ->willReturn(true);

            $remoteStorageReversionArguments = [
                [
                    ConfigFilePool::APP_ENV => [
                        'remote_storage' => [
                            'driver' => 'file'
                        ]
                    ]
                ],
                true
            ];

            $this->configWriter
                ->method('saveConfig')
                ->withConsecutive([], [], $remoteStorageReversionArguments);

            $this->setupFactory->expects(static::once())->method('create')->with($resource)->willReturn($setup);

            $this->objectManager->expects(static::any())
                ->method('get')
                ->willReturnMap(
                    [
                        [DeclarationInstaller::class, $this->declarationInstallerMock],
                        [Registry::class, $registry]
                    ]
                );

            $this->sampleDataState->expects(static::never())->method('hasError');

            $this->phpReadinessCheck->expects(static::once())->method('checkPhpExtensions')->willReturn(
                ['responseType' => ResponseTypeInterface::RESPONSE_TYPE_SUCCESS]
            );

            $this->filePermissions->expects(static::exactly(2))
                ->method('getMissingWritablePathsForInstallation')
                ->willReturn([]);

            call_user_func_array(
                [
                    $this->logger->expects(static::exactly(count($logMessages)))->method('log'),
                    'withConsecutive'
                ],
                $logMessages
            );

            $this->logger->expects(static::never())->method('logSuccess');

            $this->object->install($request);
        }

        public function installWithInvalidRemoteStorageConfigurationWithEarlyExceptionDataProvider()
        {
            return [
                [new RuntimeException(__('Remote driver is not available.'))],
                [new DriverException(__('Bucket and region are required values'))]
            ];
        }

        /**
         * Test for InstallDataFixtures
         *
         * @dataProvider testInstallDataFixturesDataProvider
         *
         * @param bool $keepCache
         * @param array $expectedToEnableCacheTypes
         * @return void
         */
        public function testInstallDataFixtures(bool $keepCache, array $expectedToEnableCacheTypes): void
        {
            $this->moduleList->method('getOne')->willReturn(['setup_version' => '2.0.0']);

            $cacheManagerMock = $this->createMock(Manager::class);
            //simulate disabled layout cache type
            $cacheManagerMock->expects($this->atLeastOnce())
                ->method('getStatus')
                ->willReturn(['layout' => 0]);
            $cacheManagerMock->expects($this->atLeastOnce())
                ->method('getAvailableTypes')
                ->willReturn(['block_html', 'full_page', 'layout' , 'config', 'collections']);
            $cacheManagerMock->expects($this->exactly(2))
                ->method('setEnabled')
                ->withConsecutive([$expectedToEnableCacheTypes, false], [$expectedToEnableCacheTypes, true])
                ->willReturn([]);

            $this->objectManager->expects($this->atLeastOnce())
                ->method('create')
                ->willReturnMap(
                    [
                        [Manager::class, [], $cacheManagerMock],
                        [
                            PatchApplierFactory::class,
                            ['objectManager' => $this->objectManager],
                            $this->patchApplierFactoryMock
                        ],
                    ]
                );

            $registryMock = $this->createMock(Registry::class);
            $this->objectManager->expects($this->atLeastOnce())
                ->method('get')
                ->with(Registry::class)
                ->willReturn($registryMock);

            $this->config->expects($this->atLeastOnce())
                ->method('get')
                ->willReturn(true);

            $this->filePermissions->expects($this->atLeastOnce())
                ->method('getMissingWritableDirectoriesForDbUpgrade')
                ->willReturn([]);

            $connection = $this->getMockBuilder(AdapterInterface::class)
                ->addMethods(['getSchemaListener'])
                ->getMockForAbstractClass();
            $connection->expects($this->once())
                ->method('getSchemaListener')
                ->willReturn($this->schemaListenerMock);

            $resource = $this->createMock(ResourceConnection::class);
            $resource->expects($this->atLeastOnce())
                ->method('getConnection')
                ->willReturn($connection);
            $this->contextMock->expects($this->once())
                ->method('getResources')
                ->willReturn($resource);
            $this->setModuleResource();

            $dataSetup = $this->createMock(DataSetup::class);
            $dataSetup->expects($this->once())
                ->method('getConnection')
                ->willReturn($connection);

            $this->dataSetupFactory->expects($this->atLeastOnce())
                ->method('create')
                ->willReturn($dataSetup);

            $this->object->installDataFixtures($this->request, $keepCache);
        }

        /**
         * DataProvider for testInstallDataFixtures
         *
         * @return array
         */
        public function testInstallDataFixturesDataProvider(): array
        {
            return [
                'keep cache' => [
                    true, ['block_html', 'full_page']
                ],
                'do not keep a cache' => [
                    false,
                    ['block_html', 'full_page', 'layout']
                ],
            ];
        }

        public function testCheckInstallationFilePermissions()
        {
            $this->filePermissions
                ->expects($this->once())
                ->method('getMissingWritablePathsForInstallation')
                ->willReturn([]);
            $this->object->checkInstallationFilePermissions();
        }

        public function testCheckInstallationFilePermissionsError()
        {
            $this->expectException('Exception');
            $this->expectExceptionMessage('Missing write permissions to the following paths:');
            $this->filePermissions
                ->expects($this->once())
                ->method('getMissingWritablePathsForInstallation')
                ->willReturn(['foo', 'bar']);
            $this->object->checkInstallationFilePermissions();
        }

        public function testCheckExtensions()
        {
            $this->phpReadinessCheck->expects($this->once())->method('checkPhpExtensions')->willReturn(
                ['responseType' => ResponseTypeInterface::RESPONSE_TYPE_SUCCESS]
            );
            $this->object->checkExtensions();
        }

        public function testCheckExtensionsError()
        {
            $this->expectException('Exception');
            $this->expectExceptionMessage('Missing following extensions: \'foo\'');
            $this->phpReadinessCheck->expects($this->once())->method('checkPhpExtensions')->willReturn(
                [
                    'responseType' => ResponseTypeInterface::RESPONSE_TYPE_ERROR,
                    'data' => ['required' => ['foo', 'bar'], 'missing' => ['foo']]
                ]
            );
            $this->object->checkExtensions();
        }

        public function testCheckApplicationFilePermissions()
        {
            $this->filePermissions
                ->expects($this->once())
                ->method('getUnnecessaryWritableDirectoriesForApplication')
                ->willReturn(['foo', 'bar']);
            $expectedMessage = "For security, remove write permissions from these directories: 'foo' 'bar'";
            $this->logger->expects($this->once())->method('log')->with($expectedMessage);
            $this->object->checkApplicationFilePermissions();
            $this->assertSame(['message' => [$expectedMessage]], $this->object->getInstallInfo());
        }

        public function testUpdateModulesSequence()
        {
            $this->cleanupFiles->expects($this->once())->method('clearCodeGeneratedFiles')->willReturn(
                [
                    "The directory '/generation' doesn't exist - skipping cleanup"
                ]
            );
            $installer = $this->prepareForUpdateModulesTests();

            $this->logger
                ->method('log')
                ->withConsecutive(
                    ['Cache types config flushed successfully'],
                    ['Cache cleared successfully'],
                    ['File system cleanup:'],
                    ['The directory \'/generation\' doesn\'t exist - skipping cleanup'],
                    ['Updating modules:']
                );

            $installer->updateModulesSequence(false);
        }

        public function testUpdateModulesSequenceKeepGenerated()
        {
            $this->cleanupFiles->expects($this->never())->method('clearCodeGeneratedClasses');

            $installer = $this->prepareForUpdateModulesTests();
            $this->logger
                ->method('log')
                ->withConsecutive(
                    ['Cache types config flushed successfully'],
                    ['Cache cleared successfully'],
                    ['Updating modules:']
                );

            $installer->updateModulesSequence(true);
        }

        /**
         * @return void
         */
        public function testUninstall(): void
        {
            $this->config->expects($this->once())
                ->method('get')
                ->with(ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS)
                ->willReturn([]);
            $this->configReader->expects($this->once())->method('getFiles')->willReturn(
                [
                    'ConfigOne.php',
                    'ConfigTwo.php'
                ]
            );
            $configDir = $this->getMockForAbstractClass(
                WriteInterface::class
            );
            $configDir
                ->expects($this->exactly(2))
                ->method('getAbsolutePath')
                ->willReturnMap(
                    [
                        ['ConfigOne.php', '/config/ConfigOne.php'],
                        ['ConfigTwo.php', '/config/ConfigTwo.php']
                    ]
                );
            $this->filesystem
                ->expects($this->any())
                ->method('getDirectoryWrite')
                ->willReturnMap(
                    [
                        [DirectoryList::CONFIG, DriverPool::FILE, $configDir],
                    ]
                );
            $cacheManager = $this->createMock(Manager::class);
            $cacheManager->expects($this->once())->method('getAvailableTypes')->willReturn(['foo', 'bar']);
            $cacheManager->expects($this->once())->method('clean');
            $this->objectManager->expects($this->any())
                ->method('get')
                ->with(Manager::class)
                ->willReturn($cacheManager);
            $this->logger->expects($this->once())->method('logSuccess')->with('Magento uninstallation complete.');
            $this->cleanupFiles->expects($this->once())->method('clearAllFiles')->willReturn(
                [
                    "The directory '/var' doesn't exist - skipping cleanup",
                    "The directory '/static' doesn't exist - skipping cleanup"
                ]
            );

            $this->logger
                ->method('log')
                ->withConsecutive(
                    ['Starting Magento uninstallation:'],
                    ['Cache cleared successfully'],
                    ['No database connection defined - skipping database cleanup'],
                    ['File system cleanup:'],
                    ["The directory '/var' doesn't exist - skipping cleanup"],
                    ["The directory '/static' doesn't exist - skipping cleanup"],
                    ["The file '/config/ConfigOne.php' doesn't exist - skipping cleanup"],
                    ["The file '/config/ConfigTwo.php' doesn't exist - skipping cleanup"]
                );

            $this->object->uninstall();
        }

        /**
         * @return void
         */
        public function testCleanupDb(): void
        {
            $this->config->expects($this->once())
                ->method('get')
                ->with(ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS)
                ->willReturn(self::$dbConfig);
            $this->connection
                ->method('quoteIdentifier')
                ->with('magento')
                ->willReturn('`magento`');

            $this->connection
                ->method('query')
                ->withConsecutive(
                    ['DROP DATABASE IF EXISTS `magento`'],
                    ['CREATE DATABASE IF NOT EXISTS `magento`']
                );

            $this->logger->expects($this->once())->method('log')->with('Cleaning up database `magento`');
            $this->object->cleanupDb();
        }

        /**
         * Prepare mocks for update modules tests and returns the installer to use
         * @return Installer
         */
        private function prepareForUpdateModulesTests()
        {
            $allModules = [
                'Foo_One' => [],
                'Bar_Two' => [],
                'New_Module' => []
            ];

            $cacheManager = $this->createMock(Manager::class);
            $cacheManager->expects($this->once())->method('getAvailableTypes')->willReturn(['foo', 'bar']);
            $cacheManager->expects($this->once())->method('clean');
            $this->objectManager->expects($this->any())
                ->method('get')
                ->willReturnMap(
                    [
                        [Manager::class, $cacheManager]
                    ]
                );
            $this->moduleLoader->expects($this->once())->method('load')->willReturn($allModules);

            $expectedModules = [
                ConfigFilePool::APP_CONFIG => [
                    'modules' => [
                        'Bar_Two' => 0,
                        'Foo_One' => 1,
                        'New_Module' => 1
                    ]
                ]
            ];

            $this->config->expects($this->atLeastOnce())
                ->method('get')
                ->with(ConfigOptionsListConstants::KEY_MODULES)
                ->willReturn(true);

            $newObject = $this->createObject(false, false);
            $this->configReader->expects($this->once())->method('load')
                ->willReturn(['modules' => ['Bar_Two' => 0, 'Foo_One' => 1, 'Old_Module' => 0]]);
            $this->configWriter->expects($this->once())->method('saveConfig')->with($expectedModules);

            return $newObject;
        }

        /**
         * Sets a new ModuleResource object to the installer
         *
         * @return void
         */
        private function setModuleResource(): void
        {
            $moduleResource = new ModuleResource($this->contextMock);
            $this->object->method('getModuleResource')->willReturn($moduleResource);
        }
    }
}

namespace Magento\Setup\Model {

    /**
     * Mocking autoload function
     *
     * @returns array
     */
    function spl_autoload_functions()
    {
        return ['mock_function_one', 'mock_function_two'];
    }
}
