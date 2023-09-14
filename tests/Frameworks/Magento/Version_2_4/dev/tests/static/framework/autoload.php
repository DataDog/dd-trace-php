<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use \Magento\Framework\App\Filesystem\DirectoryList;

//phpcs:ignore Magento2.Functions.DiscouragedFunction
$baseDir = realpath(__DIR__ . '/../../../../');
// phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile
require $baseDir . '/app/autoload.php';
// phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile
require $baseDir . '/vendor/squizlabs/php_codesniffer/autoload.php';
$testsBaseDir = $baseDir . '/dev/tests/static';
$autoloadWrapper = \Magento\Framework\Autoload\AutoloaderRegistry::getAutoloader();
$autoloadWrapper->addPsr4(
    'Magento\\',
    [
        $testsBaseDir . '/testsuite/Magento/',
        $testsBaseDir . '/framework/Magento/',
        $testsBaseDir . '/framework/tests/unit/testsuite/Magento',
    ]
);
$autoloadWrapper->addPsr4(
    'Magento\\TestFramework\\',
    [
        $testsBaseDir . '/framework/Magento/TestFramework/',
        $testsBaseDir . '/../integration/framework/Magento/TestFramework/',
        $testsBaseDir . '/../api-functional/framework/Magento/TestFramework/',
    ]
);
$autoloadWrapper->addPsr4('Magento\\CodeMessDetector\\', $testsBaseDir . '/framework/Magento/CodeMessDetector');

$generatedCode = DirectoryList::getDefaultConfig()[DirectoryList::GENERATED_CODE][DirectoryList::PATH];
$autoloadWrapper->addPsr4('Magento\\', $baseDir . '/' . $generatedCode . '/Magento/');

$setup = DirectoryList::getDefaultConfig()[DirectoryList::SETUP][DirectoryList::PATH];
$autoloadWrapper->addPsr4('Magento\\Setup\\', $baseDir . '/' . $setup . '/Magento/Setup/');
