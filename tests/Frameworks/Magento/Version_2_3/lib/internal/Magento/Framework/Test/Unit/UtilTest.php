<?php
/**
 * Collection of various useful functions
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Test\Unit;

class UtilTest extends \PHPUnit\Framework\TestCase
{
    public function testGetTrimmedPhpVersion()
    {
        $util = new \Magento\Framework\Util();
        $version = implode('.', [PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION]);
        $this->assertEquals($version, $util->getTrimmedPhpVersion());
    }
}
