<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\TestFramework\Annotation;

use Magento\Framework\DB\Adapter\SqlVersionProvider;
use Magento\TestFramework\Deploy\CliCommand;
use Magento\TestFramework\Deploy\TestModuleManager;
use Magento\TestFramework\TestCase\MutableDataInterface;

/**
 * Handler for applying reinstallMagento annotation.
 */
class DataProviderFromFile
{
    /**
     * @var string
     */
    const FALLBACK_VALUE = 'default';

    /**
     * @var array
     */
    const POSSIBLE_SUFFIXES = [
        SqlVersionProvider::MARIA_DB_10_VERSION => 'mariadb10',
    ];

    /**
     * @var TestModuleManager
     */
    private $moduleManager;

    /**
     * @var CliCommand
     */
    private $cliCommand;

    /**
     * CopyModules constructor.
     */
    public function __construct()
    {
        $this->moduleManager = new TestModuleManager();
        $this->cliCommand = new CliCommand($this->moduleManager);
    }

    /**
     * Start test.
     *
     * @param \PHPUnit\Framework\TestCase $test
     * @throws \Exception
     */
    public function startTest(\PHPUnit\Framework\TestCase $test)
    {
        $annotations = $test->getAnnotations();
        //This annotation can be declared only on method level
        if (isset($annotations['method']['dataProviderFromFile']) && $test instanceof MutableDataInterface) {
            $test->setData(
                $this->loadAllFiles(TESTS_MODULES_PATH . "/" . $annotations['method']['dataProviderFromFile'][0])
            );
        } elseif (!$test instanceof MutableDataInterface) {
            throw new \Exception("Test type do not supports @dataProviderFromFile annotation");
        }
    }

    /**
     * Finish test.
     *
     * @param \PHPUnit\Framework\TestCase $test
     * @throws \Exception
     */
    public function endTest(\PHPUnit\Framework\TestCase $test)
    {
        if ($test instanceof MutableDataInterface) {
            $test->flushData();
        }
    }

    /**
     * Load different db version files for different databases.
     *
     * @param string $path The path of the initial file.
     *
     * @return array
     */
    private function loadAllFiles(string $path): array
    {
        $result = [];
        $pathWithoutExtension = $this->removeFileExtension($path);

        foreach (glob($pathWithoutExtension . '.*') as $file) {
            preg_match('/\.([\D]*[\d]*(?:\.[\d]+){0,2})/', $file, $splitParts);
            $dbKey = self::FALLBACK_VALUE;

            if (count($splitParts) > 1) {
                $database = array_pop($splitParts);

                if ($this->isValidDatabaseSuffix($database)) {
                    $dbKey = $database;
                }
            }

            $result[$dbKey] = include $file;
        }

        return $result;
    }

    /**
     * Remove the file extension from path.
     *
     * @param string $path The file path.
     *
     * @return string
     */
    private function removeFileExtension(string $path)
    {
        $result = explode('.', $path);
        array_pop($result);

        return implode('.', $result);
    }

    /**
     * Check if database suffix is valid.
     *
     * @param string $databaseSuffix The suffix of the database from the file
     *
     * @return bool
     */
    private function isValidDatabaseSuffix(string $databaseSuffix): bool
    {
        return in_array($databaseSuffix, self::POSSIBLE_SUFFIXES);
    }
}
