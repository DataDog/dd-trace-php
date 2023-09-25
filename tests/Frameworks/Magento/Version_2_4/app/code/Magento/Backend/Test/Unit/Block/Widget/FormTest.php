<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Backend\Test\Unit\Block\Widget;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form;
use Magento\Framework\Data\Form as DataForm;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FormTest extends TestCase
{
    /** @var  Form */
    protected $model;

    /** @var  Context|MockObject */
    protected $context;

    /** @var  DataForm|MockObject */
    protected $dataForm;

    /** @var  UrlInterface|MockObject */
    protected $urlBuilder;

    protected function setUp(): void
    {
        $this->prepareContext();

        $this->dataForm = $this->getMockBuilder(\Magento\Framework\Data\Form::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'setParent',
                'setBaseUrl',
                'addCustomAttribute',
            ])
            ->getMock();

        $this->model = new Form(
            $this->context
        );
    }

    protected function prepareContext()
    {
        $this->urlBuilder = $this->getMockBuilder(UrlInterface::class)
            ->getMock();

        $this->context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->context->expects($this->any())
            ->method('getUrlBuilder')
            ->willReturn($this->urlBuilder);
    }

    public function testSetForm()
    {
        $baseUrl = 'base_url';
        $attributeKey = 'attribute_key';
        $attributeValue = 'attribute_value';

        $this->dataForm->expects($this->once())
            ->method('setParent')
            ->with($this->model)
            ->willReturnSelf();
        $this->dataForm->expects($this->once())
            ->method('setBaseUrl')
            ->with($baseUrl)
            ->willReturnSelf();
        $this->dataForm->expects($this->once())
            ->method('addCustomAttribute')
            ->with($attributeKey, $attributeValue)
            ->willReturnSelf();

        $this->urlBuilder->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn($baseUrl);

        $this->model->setData('custom_attributes', [$attributeKey => $attributeValue]);
        $this->assertEquals($this->model, $this->model->setForm($this->dataForm));
    }

    public function testSetFormNoCustomAttributes()
    {
        $baseUrl = 'base_url';

        $this->dataForm->expects($this->once())
            ->method('setParent')
            ->with($this->model)
            ->willReturnSelf();
        $this->dataForm->expects($this->once())
            ->method('setBaseUrl')
            ->with($baseUrl)
            ->willReturnSelf();

        $this->urlBuilder->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn($baseUrl);

        $this->assertEquals($this->model, $this->model->setForm($this->dataForm));
    }
}
