<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Test\Integrity\Dependency;

use Magento\Framework\App\Utility\Files;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Setup\Declaration\Schema\Config\Converter;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Inspection\Exception as InspectionException;

/**
 * Provide information on the dependency between the modules according to the declarative schema.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class DeclarativeSchemaDependencyProvider
{
    /**
     * Declarative name for table entity of the declarative schema.
     */
    public const SCHEMA_ENTITY_TABLE = 'table';

    /**
     * Declarative name for column entity of the declarative schema.
     */
    public const SCHEMA_ENTITY_COLUMN = 'column';

    /**
     * Declarative name for constraint entity of the declarative schema.
     */
    public const SCHEMA_ENTITY_CONSTRAINT = 'constraint';

    /**
     * Declarative name for index entity of the declarative schema.
     */
    public const SCHEMA_ENTITY_INDEX = 'index';

    /**
     * @var array
     */
    private $dbSchemaDeclaration = [];

    /**
     * @var array
     */
    private $moduleSchemaFileMapping = [];

    /**
     * @var DependencyProvider
     */
    private $dependencyProvider;

    /**
     * @param DependencyProvider $dependencyProvider
     */
    public function __construct(DependencyProvider $dependencyProvider)
    {
        $this->dependencyProvider = $dependencyProvider;
    }

    /**
     * Provide declared dependencies between modules based on the declarative schema configuration.
     *
     * @param string $moduleName
     * @return array
     * @throws \Exception
     */
    public function getDeclaredExistingModuleDependencies(string $moduleName): array
    {
        $dependencies = $this->getDependenciesFromFiles($this->getSchemaFileNameByModuleName($moduleName));
        $dependencies = $this->filterSelfDependency($moduleName, $dependencies);
        $declared = $this->dependencyProvider->getDeclaredDependencies(
            $moduleName,
            DependencyProvider::TYPE_HARD,
            DependencyProvider::MAP_TYPE_DECLARED
        );

        $existingDeclared = [];
        foreach ($dependencies as $dependency) {
            $checkResult = array_intersect($declared, $dependency);
            if ($checkResult) {
                $existingDeclared[] = array_values($checkResult);
            }
        }

        return array_unique(array_merge([], ...$existingDeclared));
    }

    /**
     * Provide undeclared dependencies between modules based on the declarative schema configuration.
     *
     * [
     *     $dependencyId => [$module1, $module2, $module3 ...],
     *     ...
     * ]
     *
     * @param string $moduleName
     * @return array
     * @throws \Exception
     */
    public function getUndeclaredModuleDependencies(string $moduleName): array
    {
        $dependencies = $this->getDependenciesFromFiles($this->getSchemaFileNameByModuleName($moduleName));
        $dependencies = $this->filterSelfDependency($moduleName, $dependencies);
        return $this->collectDependencies($moduleName, $dependencies);
    }

    /**
     * Provide schema file name by module name.
     *
     * @param string $module
     * @return string
     * @throws LocalizedException
     */
    private function getSchemaFileNameByModuleName(string $module): string
    {
        if (empty($this->moduleSchemaFileMapping)) {
            $componentRegistrar = new ComponentRegistrar();
            foreach (array_values(Files::init()->getDbSchemaFiles()) as $filePath) {
                $filePath = reset($filePath);
                foreach ($componentRegistrar->getPaths(ComponentRegistrar::MODULE) as $moduleName => $moduleDir) {
                    if (strpos($filePath, $moduleDir . '/') !== false) {
                        $foundModuleName = str_replace('_', '\\', $moduleName);
                        $this->moduleSchemaFileMapping[$foundModuleName] = $filePath;
                        break;
                    }
                }
            }
        }

        return $this->moduleSchemaFileMapping[$module] ?? '';
    }

    /**
     * Remove self dependencies.
     *
     * @param string $moduleName
     * @param array $dependencies
     * @return array
     */
    private function filterSelfDependency(string $moduleName, array $dependencies): array
    {
        foreach ($dependencies as $id => $modules) {
            $decodedId = self::decodeDependencyId($id);
            $entityType = $decodedId['entityType'];
            if ($entityType === self::SCHEMA_ENTITY_TABLE || $entityType === "column") {
                if (array_search($moduleName, $modules) !== false) {
                    unset($dependencies[$id]);
                }
            } else {
                $dependencies[$id] = $this->filterComplexDependency($moduleName, $modules);
            }
        }

        return array_filter($dependencies);
    }

    /**
     * Remove already declared dependencies.
     *
     * @param string $moduleName
     * @param array $modules
     * @return array
     */
    private function filterComplexDependency(string $moduleName, array $modules): array
    {
        $resultDependencies = [];
        if (!is_array(reset($modules))) {
            if (array_search($moduleName, $modules) === false) {
                $resultDependencies = $modules;
            }
        } else {
            foreach ($modules as $dependencySet) {
                if (array_search($moduleName, $dependencySet) === false) {
                    $resultDependencies[] = $dependencySet;
                }
            }
            $resultDependencies = array_merge([], ...$resultDependencies);
        }

        return array_values(array_unique($resultDependencies));
    }

    /**
     * Retrieve declarative schema declaration.
     *
     * @return array
     * @throws LocalizedException
     */
    private function getDeclarativeSchema(): array
    {
        if ($this->dbSchemaDeclaration) {
            return $this->dbSchemaDeclaration;
        }

        $entityTypes = [self::SCHEMA_ENTITY_COLUMN, self::SCHEMA_ENTITY_CONSTRAINT, self::SCHEMA_ENTITY_INDEX];
        $declaration = [];
        foreach (Files::init()->getDbSchemaFiles() as $filePath) {
            $filePath = reset($filePath);
            preg_match('#app/code/(\w+/\w+)#', $filePath, $result);
            $moduleName = str_replace('/', '\\', $result[1]);
            $moduleDeclaration = $this->getDbSchemaDeclaration($filePath);

            foreach ($moduleDeclaration[self::SCHEMA_ENTITY_TABLE] as $tableName => $tableDeclaration) {
                if (!isset($tableDeclaration['modules'])) {
                    $tableDeclaration['modules'] = [];
                }
                array_push($tableDeclaration['modules'], $moduleName);
                $moduleDeclaration = array_replace_recursive(
                    $moduleDeclaration,
                    [self::SCHEMA_ENTITY_TABLE => [
                        $tableName => $tableDeclaration,
                    ]
                    ]
                );
                foreach ($entityTypes as $entityType) {
                    if (!isset($tableDeclaration[$entityType])) {
                        continue;
                    }
                    $moduleDeclaration = array_replace_recursive(
                        $moduleDeclaration,
                        [self::SCHEMA_ENTITY_TABLE => [
                            $tableName => $this->addModuleAssigment($tableDeclaration, $entityType, $moduleName)
                        ]
                        ]
                    );
                }
            }
            $declaration = array_merge_recursive($declaration, $moduleDeclaration);
        }
        $this->dbSchemaDeclaration = $declaration;

        return $this->dbSchemaDeclaration;
    }

    /**
     * Get declared dependencies.
     *
     * @param string $tableName
     * @param string $entityType
     * @param null|string $entityName
     * @return array
     * @throws LocalizedException
     */
    private function resolveEntityDependencies(string $tableName, string $entityType, ?string $entityName = null): array
    {
        switch ($entityType) {
            case self::SCHEMA_ENTITY_COLUMN:
            case self::SCHEMA_ENTITY_CONSTRAINT:
            case self::SCHEMA_ENTITY_INDEX:
                return $this->getDeclarativeSchema()
                [self::SCHEMA_ENTITY_TABLE][$tableName][$entityType][$entityName]['modules'];
            case self::SCHEMA_ENTITY_TABLE:
                return $this->getDeclarativeSchema()[self::SCHEMA_ENTITY_TABLE][$tableName]['modules'];
            default:
                return [];
        }
    }

    /**
     * @param string $filePath
     * @return array
     */
    private function getDbSchemaDeclaration(string $filePath): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML(file_get_contents($filePath));
        return (new Converter())->convert($dom);
    }

    /**
     * Add dependency on the current module.
     *
     * @param array $tableDeclaration
     * @param string $entityType
     * @param string $moduleName
     * @return array
     */
    private function addModuleAssigment(
        array $tableDeclaration,
        string $entityType,
        string $moduleName
    ): array {
        $declarationWithAssigment = [];
        foreach ($tableDeclaration[$entityType] as $entityName => $entityDeclaration) {
            if (!isset($entityDeclaration['modules'])) {
                $entityDeclaration['modules'] = [];
            }
            if (!$this->isEntityDisabled($entityDeclaration)) {
                array_push($entityDeclaration['modules'], $moduleName);
            }

            $declarationWithAssigment[$entityType][$entityName] = $entityDeclaration;
        }

        return $declarationWithAssigment;
    }

    /**
     * Retrieve dependencies from files.
     *
     * @param string $file
     * @return string[]
     * @throws \Exception
     */
    private function getDependenciesFromFiles($file)
    {
        if (!$file) {
            return [];
        }

        $moduleDbSchema = $this->getDbSchemaDeclaration($file);
        $dependencies = array_merge_recursive(
            $this->getDisabledDependencies($moduleDbSchema),
            $this->getConstraintDependencies($moduleDbSchema),
            $this->getIndexDependencies($moduleDbSchema)
        );
        return $dependencies;
    }

    /**
     * Retrieve dependencies for disabled entities.
     *
     * @param array $moduleDeclaration
     * @return array
     * @throws LocalizedException
     */
    private function getDisabledDependencies(array $moduleDeclaration): array
    {
        $disabledDependencies = [];
        $entityTypes = [self::SCHEMA_ENTITY_COLUMN, self::SCHEMA_ENTITY_CONSTRAINT, self::SCHEMA_ENTITY_INDEX];
        foreach ($moduleDeclaration[self::SCHEMA_ENTITY_TABLE] as $tableName => $tableDeclaration) {
            foreach ($entityTypes as $entityType) {
                if (!isset($tableDeclaration[$entityType])) {
                    continue;
                }
                foreach ($tableDeclaration[$entityType] as $entityName => $entityDeclaration) {
                    if ($this->isEntityDisabled($entityDeclaration)) {
                        $dependencyIdentifier = $this->getDependencyId($tableName, $entityType, $entityName);
                        $disabledDependencies[$dependencyIdentifier] =
                            $this->resolveEntityDependencies($tableName, $entityType, $entityName);
                    }
                }
            }
            if ($this->isEntityDisabled($tableDeclaration)) {
                $disabledDependencies[$this->getDependencyId($tableName)] =
                    $this->resolveEntityDependencies($tableName, self::SCHEMA_ENTITY_TABLE);
            }
        }

        return $disabledDependencies;
    }

    /**
     * Retrieve dependencies for foreign entities.
     *
     * @param array $constraintDeclaration
     * @return array
     * @throws \Exception
     */
    private function getFKDependencies(array $constraintDeclaration): array
    {
        $referenceDependencyIdentifier =
            $this->getDependencyId(
                $constraintDeclaration['referenceTable'],
                self::SCHEMA_ENTITY_CONSTRAINT,
                $constraintDeclaration['referenceId']
            );
        $dependencyIdentifier =
            $this->getDependencyId(
                $constraintDeclaration[self::SCHEMA_ENTITY_TABLE],
                self::SCHEMA_ENTITY_CONSTRAINT,
                $constraintDeclaration['referenceId']
            );

        $constraintDependencies = [];
        $constraintDependencies[$referenceDependencyIdentifier] =
            $this->resolveEntityDependencies(
                $constraintDeclaration['referenceTable'],
                self::SCHEMA_ENTITY_COLUMN,
                $constraintDeclaration['referenceColumn']
            );
        $constraintDependencies[$dependencyIdentifier] =
            $this->resolveEntityDependencies(
                $constraintDeclaration[self::SCHEMA_ENTITY_TABLE],
                self::SCHEMA_ENTITY_COLUMN,
                $constraintDeclaration[self::SCHEMA_ENTITY_COLUMN]
            );

        return $constraintDependencies;
    }

    /**
     * Retrieve dependencies for constraint entities.
     *
     * @param array $moduleDeclaration
     * @return array
     * @throws \Exception
     */
    private function getConstraintDependencies(array $moduleDeclaration): array
    {
        $constraintDependencies = [];
        foreach ($moduleDeclaration[self::SCHEMA_ENTITY_TABLE] as $tableName => $tableDeclaration) {
            if (empty($tableDeclaration[self::SCHEMA_ENTITY_CONSTRAINT])) {
                continue;
            }
            foreach ($tableDeclaration[self::SCHEMA_ENTITY_CONSTRAINT] as $constraintName => $constraintDeclaration) {
                if ($this->isEntityDisabled($constraintDeclaration)) {
                    continue;
                }
                $dependencyIdentifier =
                    $this->getDependencyId($tableName, self::SCHEMA_ENTITY_CONSTRAINT, $constraintName);
                switch ($constraintDeclaration['type']) {
                    case 'foreign':
                        //phpcs:ignore Magento2.Performance.ForeachArrayMerge
                        $constraintDependencies = array_merge(
                            $constraintDependencies,
                            $this->getFKDependencies($constraintDeclaration)
                        );
                        break;
                    case 'primary':
                    case 'unique':
                        $constraintDependencies[$dependencyIdentifier] = $this->getComplexDependency(
                            $tableName,
                            $constraintDeclaration
                        );
                }
            }
        }
        return $constraintDependencies;
    }

    /**
     * Calculate complex dependency.
     *
     * @param string $tableName
     * @param array $entityDeclaration
     * @return array
     * @throws LocalizedException
     */
    private function getComplexDependency(string $tableName, array $entityDeclaration): array
    {
        $complexDependency = [];
        if (empty($entityDeclaration[self::SCHEMA_ENTITY_COLUMN])) {
            return $complexDependency;
        }

        if (!is_array($entityDeclaration[self::SCHEMA_ENTITY_COLUMN])) {
            $entityDeclaration[self::SCHEMA_ENTITY_COLUMN] = [$entityDeclaration[self::SCHEMA_ENTITY_COLUMN]];
        }

        foreach (array_keys($entityDeclaration[self::SCHEMA_ENTITY_COLUMN]) as $columnName) {
            $complexDependency[] =
                $this->resolveEntityDependencies($tableName, self::SCHEMA_ENTITY_COLUMN, $columnName);
        }

        return array_values($complexDependency);
    }

    /**
     * Retrieve dependencies for index entities.
     *
     * @param array $moduleDeclaration
     * @return array
     * @throws LocalizedException
     */
    private function getIndexDependencies(array $moduleDeclaration): array
    {
        $indexDependencies = [];
        foreach ($moduleDeclaration[self::SCHEMA_ENTITY_TABLE] as $tableName => $tableDeclaration) {
            if (empty($tableDeclaration[self::SCHEMA_ENTITY_INDEX])) {
                continue;
            }
            foreach ($tableDeclaration[self::SCHEMA_ENTITY_INDEX] as $indexName => $indexDeclaration) {
                if ($this->isEntityDisabled($indexDeclaration)) {
                    continue;
                }
                $dependencyIdentifier =
                    $this->getDependencyId($tableName, self::SCHEMA_ENTITY_INDEX, $indexName);
                $indexDependencies[$dependencyIdentifier] =
                    $this->getComplexDependency($tableName, $indexDeclaration);
            }
        }

        return $indexDependencies;
    }

    /**
     * Check status of the entity declaration.
     *
     * @param array $entityDeclaration
     * @return bool
     */
    private function isEntityDisabled(array $entityDeclaration): bool
    {
        return isset($entityDeclaration['disabled']) && $entityDeclaration['disabled'] == true;
    }

    /**
     * Retrieve dependency id.
     *
     * @param string $tableName
     * @param string $entityType
     * @param null|string $entityName
     * @return string
     */
    private function getDependencyId(
        string $tableName,
        string $entityType = self::SCHEMA_ENTITY_TABLE,
        ?string $entityName = null
    ) {
        return implode('___', [$tableName, $entityType, $entityName ?: $tableName]);
    }

    /**
     * Retrieve dependency parameters from dependency id.
     *
     * @param string $id
     * @return array
     */
    public static function decodeDependencyId(string $id): array
    {
        $decodedValues = explode('___', $id);
        $result = [
            'tableName' => $decodedValues[0],
            'entityType' => $decodedValues[1],
            'entityName' => $decodedValues[2],
        ];
        return $result;
    }

    /**
     * Collect module dependencies.
     *
     * @param $currentModuleName
     * @param array $dependencies
     * @return array
     * @throws InspectionException
     * @throws LocalizedException
     */
    private function collectDependencies($currentModuleName, $dependencies = []): array
    {
        if (empty($dependencies)) {
            return [];
        }
        foreach ($dependencies as $dependencyName => $dependency) {
            $this->collectDependency($dependencyName, $dependency, $currentModuleName);
        }

        return $this->dependencyProvider->getDeclaredDependencies(
            $currentModuleName,
            DependencyProvider::TYPE_HARD,
            DependencyProvider::MAP_TYPE_FOUND
        );
    }

    /**
     *  Collect a module dependency.
     *
     * @param string $dependencyName
     * @param array $dependency
     * @param string $currentModule
     * @throws LocalizedException
     * @throws InspectionException
     */
    private function collectDependency(
        string $dependencyName,
        array $dependency,
        string $currentModule
    ) {
        $declared = $this->dependencyProvider->getDeclaredDependencies(
            $currentModule,
            DependencyProvider::TYPE_HARD,
            DependencyProvider::MAP_TYPE_DECLARED
        );
        $checkResult = array_intersect($declared, $dependency);

        if (empty($checkResult)) {
            $this->dependencyProvider->addDependencies(
                $currentModule,
                DependencyProvider::TYPE_HARD,
                DependencyProvider::MAP_TYPE_FOUND,
                [
                    $dependencyName => $dependency,
                ]
            );
        }
    }
}
