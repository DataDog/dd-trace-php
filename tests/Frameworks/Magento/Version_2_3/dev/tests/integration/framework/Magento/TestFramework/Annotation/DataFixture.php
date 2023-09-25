<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\TestFramework\Annotation;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\Exception;

/**
 * Implementation of the @magentoDataFixture DocBlock annotation.
 */
class DataFixture
{
    /**
     * @var string
     */
    protected $_fixtureBaseDir;

    /**
     * Fixtures that have been applied
     *
     * @var array
     */
    private $_appliedFixtures = [];

    /**
     * Constructor
     *
     * @param string $fixtureBaseDir
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct($fixtureBaseDir)
    {
        if (!is_dir($fixtureBaseDir)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                new \Magento\Framework\Phrase("Fixture base directory '%1' does not exist.", [$fixtureBaseDir])
            );
        }
        $this->_fixtureBaseDir = realpath($fixtureBaseDir);
    }

    /**
     * Handler for 'startTestTransactionRequest' event
     *
     * @param \PHPUnit\Framework\TestCase $test
     * @param \Magento\TestFramework\Event\Param\Transaction $param
     */
    public function startTestTransactionRequest(
        \PHPUnit\Framework\TestCase $test,
        \Magento\TestFramework\Event\Param\Transaction $param
    ) {
        /* Start transaction before applying first fixture to be able to revert them all further */
        if ($this->_getFixtures($test)) {
            if ($this->getDbIsolationState($test) !== ['disabled']) {
                $param->requestTransactionStart();
            } else {
                $this->_applyFixtures($this->_getFixtures($test));
            }
        }
    }

    /**
     * Handler for 'endTestNeedTransactionRollback' event
     *
     * @param \PHPUnit\Framework\TestCase $test
     * @param \Magento\TestFramework\Event\Param\Transaction $param
     */
    public function endTestTransactionRequest(
        \PHPUnit\Framework\TestCase $test,
        \Magento\TestFramework\Event\Param\Transaction $param
    ) {
        /* Isolate other tests from test-specific fixtures */
        if ($this->_appliedFixtures && $this->_getFixtures($test)) {
            if ($this->getDbIsolationState($test) !== ['disabled']) {
                $param->requestTransactionRollback();
            } else {
                $this->_revertFixtures();
            }
        }
    }

    /**
     * Handler for 'startTransaction' event
     *
     * @param \PHPUnit\Framework\TestCase $test
     */
    public function startTransaction(\PHPUnit\Framework\TestCase $test)
    {
        $this->_applyFixtures($this->_getFixtures($test));
    }

    /**
     * Handler for 'rollbackTransaction' event
     */
    public function rollbackTransaction()
    {
        $this->_revertFixtures();
    }

    /**
     * Retrieve fixtures from annotation
     *
     * @param \PHPUnit\Framework\TestCase $test
     * @param string $scope
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _getFixtures(\PHPUnit\Framework\TestCase $test, $scope = null)
    {
        if ($scope === null) {
            $annotations = $this->getAnnotations($test);
        } else {
            $annotations = $test->getAnnotations()[$scope];
        }
        $result = [];
        if (!empty($annotations['magentoDataFixture'])) {
            foreach ($annotations['magentoDataFixture'] as $fixture) {
                if (strpos($fixture, '\\') !== false) {
                    // usage of a single directory separator symbol streamlines search across the source code
                    throw new \Magento\Framework\Exception\LocalizedException(
                        new \Magento\Framework\Phrase('Directory separator "\\" is prohibited in fixture declaration.')
                    );
                }
                $fixtureMethod = [get_class($test), $fixture];
                if (is_callable($fixtureMethod)) {
                    $result[] = $fixtureMethod;
                } elseif ($this->isModuleAnnotation($fixture)) {
                    $result[] = $this->getModulePath($fixture);
                } else {
                    $result[] = $this->_fixtureBaseDir . '/' . $fixture;
                }
            }
        }
        return $result;
    }

    /**
     * Check is the Annotation like Magento_InventoryApi::Test/_files/products.php
     *
     * @param string $fixture
     * @return bool
     */
    private function isModuleAnnotation(string $fixture)
    {
        return (strpos($fixture, '::') !== false);
    }

    /**
     * Resolve the Fixture
     *
     * @param string $fixture
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function getModulePath(string $fixture)
    {
        [$moduleName, $fixtureFile] = explode('::', $fixture, 2);

        $modulePath = (new ComponentRegistrar())->getPath(ComponentRegistrar::MODULE, $moduleName);

        if ($modulePath === null) {
            throw new \Magento\Framework\Exception\LocalizedException(
                new \Magento\Framework\Phrase('Can\'t find registered Module with name %1 .', [$moduleName])
            );
        }

        return $modulePath . '/' . ltrim($fixtureFile, '/');
    }

    /**
     * Get method annotations.
     *
     * Overwrites class-defined annotations.
     *
     * @param \PHPUnit\Framework\TestCase $test
     * @return array
     */
    private function getAnnotations(\PHPUnit\Framework\TestCase $test)
    {
        $annotations = $test->getAnnotations();
        return array_replace($annotations['class'], $annotations['method']);
    }

    /**
     * Return is explicit set isolation state
     *
     * @param \PHPUnit\Framework\TestCase $test
     * @return bool|null
     */
    protected function getDbIsolationState(\PHPUnit\Framework\TestCase $test)
    {
        $annotations = $this->getAnnotations($test);
        return isset($annotations[DbIsolation::MAGENTO_DB_ISOLATION])
            ? $annotations[DbIsolation::MAGENTO_DB_ISOLATION]
            : null;
    }

    /**
     * Execute single fixture script
     *
     * @param string|array $fixture
     * @throws \Exception
     */
    protected function _applyOneFixture($fixture)
    {
        try {
            if (is_callable($fixture)) {
                call_user_func($fixture);
            } else {
                require $fixture;
            }
        } catch (\Exception $e) {
            throw new Exception(
                sprintf(
                    "Error in fixture: %s.\n %s\n %s",
                    json_encode($fixture),
                    $e->getMessage(),
                    $e->getTraceAsString()
                ),
                500,
                $e
            );
        }
    }

    /**
     * Execute fixture scripts if any
     *
     * @param array $fixtures
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _applyFixtures(array $fixtures)
    {
        /* Execute fixture scripts */
        foreach ($fixtures as $oneFixture) {
            /* Skip already applied fixtures */
            if (in_array($oneFixture, $this->_appliedFixtures, true)) {
                continue;
            }
            $this->_applyOneFixture($oneFixture);
            $this->_appliedFixtures[] = $oneFixture;
        }
    }

    /**
     * Revert changes done by fixtures
     */
    protected function _revertFixtures()
    {
        $appliedFixtures = array_reverse($this->_appliedFixtures);
        foreach ($appliedFixtures as $fixture) {
            if (is_callable($fixture)) {
                $fixture[1] .= 'Rollback';
                if (is_callable($fixture)) {
                    $this->_applyOneFixture($fixture);
                }
            } else {
                $fileInfo = pathinfo($fixture);
                $extension = '';
                if (isset($fileInfo['extension'])) {
                    $extension = '.' . $fileInfo['extension'];
                }
                $rollbackScript = $fileInfo['dirname'] . '/' . $fileInfo['filename'] . '_rollback' . $extension;
                if (file_exists($rollbackScript)) {
                    $this->_applyOneFixture($rollbackScript);
                }
            }
        }
        $this->_appliedFixtures = [];
    }
}
