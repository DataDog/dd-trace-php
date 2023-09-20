<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/**
 * Tests for \Magento\Framework\Data\Form\Element\Image
 */
namespace Magento\Framework\Data\Form\Element;

class ImageTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Data\Form\Element\Image
     */
    protected $imageElement;

    protected function setUp(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var $elementFactory \Magento\Framework\Data\Form\ElementFactory */
        $elementFactory = $objectManager->create(\Magento\Framework\Data\Form\ElementFactory::class);
        $this->imageElement = $elementFactory->create(\Magento\Framework\Data\Form\Element\Image::class, []);
        $form = $objectManager->create(\Magento\Framework\Data\Form::class);
        $this->imageElement->setForm($form);
    }

    public function testGetElementHtml()
    {
        $filePath = 'some/path/to/file.jpg';
        $this->imageElement->setValue($filePath);
        $html = $this->imageElement->getElementHtml();

        $this->assertStringContainsString('media/' . $filePath, $html);
    }
}
