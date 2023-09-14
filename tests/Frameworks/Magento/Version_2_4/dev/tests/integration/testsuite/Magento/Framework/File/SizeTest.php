<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Magento file size test
 */
namespace Magento\Framework\File;

class SizeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\File\Size
     */
    protected $_fileSize;

    protected function setUp(): void
    {
        $this->_fileSize = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Framework\File\Size::class);
    }

    /**
     * @backupStaticAttributes
     */
    public function testGetMaxFileSize()
    {
        $this->assertGreaterThanOrEqual(0, $this->_fileSize->getMaxFileSize());
        $this->assertGreaterThanOrEqual(0, $this->_fileSize->getMaxFileSizeInMb());
    }
}
