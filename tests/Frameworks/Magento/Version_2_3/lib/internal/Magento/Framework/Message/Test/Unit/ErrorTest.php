<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Message\Test\Unit;

use Magento\Framework\Message\MessageInterface;

/**
 * \Magento\Framework\Message\Error test case
 */
class ErrorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Message\Error
     */
    protected $model;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(\Magento\Framework\Message\Error::class);
    }

    public function testGetType()
    {
        $this->assertEquals(MessageInterface::TYPE_ERROR, $this->model->getType());
    }
}
