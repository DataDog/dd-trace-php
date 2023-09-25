<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Data\Test\Unit\Form\Element;

/**
 * Tests for \Magento\Framework\Data\Form\Element\Obscure
 */
class ObscureTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager */
    private $objectManager;

    /**
     * @var \Magento\Framework\Data\Form\Element\Obscure
     */
    protected $_model;

    protected function setUp(): void
    {
        $factoryMock = $this->createMock(\Magento\Framework\Data\Form\Element\Factory::class);
        $collectionFactoryMock = $this->createMock(\Magento\Framework\Data\Form\Element\CollectionFactory::class);
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $escaper = $this->objectManager->getObject(
            \Magento\Framework\Escaper::class
        );
        $this->_model = new \Magento\Framework\Data\Form\Element\Obscure(
            $factoryMock,
            $collectionFactoryMock,
            $escaper
        );
        $formMock = new \Magento\Framework\DataObject();
        $formMock->getHtmlIdPrefix('id_prefix');
        $formMock->getHtmlIdPrefix('id_suffix');
        $this->_model->setForm($formMock);
    }

    /**
     * @covers \Magento\Framework\Data\Form\Element\Obscure::__construct
     */
    public function testConstruct()
    {
        $this->assertEquals('password', $this->_model->getType());
        $this->assertEquals('textfield', $this->_model->getExtType());
    }

    /**
     * @covers \Magento\Framework\Data\Form\Element\Obscure::getEscapedValue
     */
    public function testGetEscapedValue()
    {
        $this->_model->setValue('Obscure Text');
        $this->assertStringContainsString('value="******"', $this->_model->getElementHtml());
        $this->_model->setValue('');
        $this->assertStringContainsString('value=""', $this->_model->getElementHtml());
    }

    /**
     * @covers \Magento\Framework\Data\Form\Element\Obscure::getHtmlAttributes
     */
    public function testGetHtmlAttributes()
    {
        $this->assertEmpty(
            array_diff(
                [
                    'type',
                    'title',
                    'class',
                    'style',
                    'onclick',
                    'onchange',
                    'onkeyup',
                    'disabled',
                    'readonly',
                    'maxlength',
                    'tabindex',
                ],
                $this->_model->getHtmlAttributes()
            )
        );
    }
}
