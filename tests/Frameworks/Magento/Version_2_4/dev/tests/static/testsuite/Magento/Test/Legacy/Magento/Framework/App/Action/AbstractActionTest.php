<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Test\Legacy\Magento\Framework\App\Action;

use Magento\Framework\App\Action\AbstractAction;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Utility\Files;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Utility\AddedFiles;
use Magento\TestFramework\Utility\ChildrenClassesSearch;
use PHPUnit\Framework\TestCase;

/**
 * Test newly created controllers must do not extend AbstractAction.
 */
class AbstractActionTest extends TestCase
{
    /**
     * @var ChildrenClassesSearch
     */
    private $childrenClassesSearch;

    /**
     * @var Files
     */
    private $fileUtilities;

    /**
     * @throws LocalizedException
     */
    protected function setUp(): void
    {
        $this->childrenClassesSearch = new ChildrenClassesSearch();
        $this->fileUtilities = Files::init();
    }

    /**
     * Test newly created controllers do not extend deprecated AbstractAction.
     *
     * @throws \ReflectionException
     */
    public function testNewControllersDoNotExtendAbstractAction(): void
    {
        $files = $this->getTestFiles();

        $found = $this->childrenClassesSearch->getClassesWhichAreChildrenOf($files, AbstractAction::class);

        $this->assertEmpty(
            $found,
            "The following new controller(s) extend " . AbstractAction::class . "\r\n"
            . "All new controller classes must implement " . ActionInterface::class . " instead.\r\n"
            . print_r($found, true)
        );
    }

    /**
     * Provide files for test.
     *
     * @return array
     */
    private function getTestFiles(): array
    {
        $phpFiles = AddedFiles::getAddedFilesList($this->getChangedFilesBaseDir());
        $phpFiles = array_diff($phpFiles, $this->getIgnoredFiles());

        $phpFiles = Files::composeDataSets($phpFiles);
        $fileTypes = Files::INCLUDE_APP_CODE | Files::INCLUDE_LIBS | Files::AS_DATA_SET;
        return array_intersect_key($phpFiles, $this->fileUtilities->getPhpFiles($fileTypes));
    }

    /**
     * Returns base directory for generated lists.
     *
     * @return string
     */
    private function getChangedFilesBaseDir(): string
    {
        return BP . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'static' .
            DIRECTORY_SEPARATOR . 'testsuite' . DIRECTORY_SEPARATOR . 'Magento' . DIRECTORY_SEPARATOR . 'Test';
    }

    /**
     * Reads list of controller files to ignore
     * These are false positive cases so the static tests will ignore these controllers
     *
     * @return array
     */
    private function getIgnoredFiles(): array
    {
        // phpcs:disable Magento2.Files.LineLength.MaxExceeded
        $list = include __DIR__ . '/../../../../_files/blacklist/controller/controllers_extending_abstract_action.php';
        $ignored = [];
        $appPath = BP;
        foreach ($list as $file) {
            // phpcs:ignore
            $ignored = array_merge($ignored, glob($appPath . DIRECTORY_SEPARATOR . $file, GLOB_NOSORT));
        }
        return $ignored;
    }
}
