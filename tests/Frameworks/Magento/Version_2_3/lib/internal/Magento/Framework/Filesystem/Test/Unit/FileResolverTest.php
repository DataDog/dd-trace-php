<?php
/**
 * Unit test for \Magento\Framework\Filesystem\FileResolver
 *
 * Only one method is unit testable, other methods require integration testing.
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filesystem\Test\Unit;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class FileResolverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Filesystem\FileResolver
     */
    protected $model;

    protected function setUp(): void
    {
        $this->model = (new ObjectManager($this))->getObject(\Magento\Framework\Filesystem\FileResolver::class);
    }

    public function testGetFilePath()
    {
        $this->assertSame('Path/To/My/Class.php', $this->model->getFilePath('Path\To\My_Class'));
    }
}
