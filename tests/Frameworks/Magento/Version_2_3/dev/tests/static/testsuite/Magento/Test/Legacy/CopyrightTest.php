<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Tests to ensure that all files has up to date copyright info
 */
namespace Magento\Test\Legacy;

class CopyrightTest extends \PHPUnit\Framework\TestCase
{
    public function testCopyright()
    {
        $invoker = new \Magento\Framework\App\Utility\AggregateInvoker($this);
        $invoker(
            function ($filename) {
                $fileText = file_get_contents($filename);
                if (strpos($fileText, 'Copyright © Magento, Inc. All rights reserved.') === false) {
                    $this->fail('Copyright is missing or has wrong format ' . $filename);
                }
            },
            $this->copyrightDataProvider()
        );
    }

    public function copyrightDataProvider()
    {
        $blackList = $this->getFilesData('blacklist*.php');

        $changedFiles = [];
        foreach (glob(__DIR__ . '/../_files/changed_files*') as $listFile) {
            $changedFiles = array_merge($changedFiles, file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }
        array_walk(
            $changedFiles,
            function (&$file) {
                $file = [BP . '/' . $file];
            }
        );
        $changedFiles = array_filter(
            $changedFiles,
            function ($path) use ($blackList) {
                if (!file_exists($path[0]) || !is_readable($path[0])) {
                    return false;
                }
                $path[0] = realpath($path[0]);
                foreach ($blackList as $item) {
                    if (preg_match($item, $path[0])) {
                        return false;
                    }
                }
                return true;
            }
        );
        return $changedFiles;
    }

    /**
     * @param string $filePattern
     * @return array
     */
    protected function getFilesData($filePattern)
    {
        $result = [];
        foreach (glob(__DIR__ . '/_files/copyright/' . $filePattern) as $file) {
            $fileData = include $file;
            $result = array_merge($result, $fileData);
        }
        return $result;
    }
}
