<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Product\Attribute\Backend;

class WeightTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Product\Attribute\Backend\Weight
     */
    protected $model;

    protected function setUp(): void
    {
        $objectHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        // we want to use an actual implementation of \Magento\Framework\Locale\FormatInterface
        $scopeResolver = $this->getMockForAbstractClass(
            \Magento\Framework\App\ScopeResolverInterface::class,
            [],
            '',
            false
        );
        $localeResolver = $this->getMockForAbstractClass(
            \Magento\Framework\Locale\ResolverInterface::class,
            [],
            '',
            false
        );
        $currencyFactory = $this->createMock(\Magento\Directory\Model\CurrencyFactory::class);
        $localeFormat = $objectHelper->getObject(
            \Magento\Framework\Locale\Format::class,
            [
                'scopeResolver'   => $scopeResolver,
                'localeResolver'  => $localeResolver,
                'currencyFactory' => $currencyFactory,
            ]
        );

        // the model we are testing
        $this->model = $objectHelper->getObject(
            \Magento\Catalog\Model\Product\Attribute\Backend\Weight::class,
            ['localeFormat' => $localeFormat]
        );

        $attribute = $this->getMockForAbstractClass(
            \Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class,
            [],
            '',
            false
        );
        $this->model->setAttribute($attribute);
    }

    /**
     * Tests for the cases that expect to pass validation
     *
     * @dataProvider dataProviderValidate
     */
    public function testValidate($value)
    {
        $object = $this->createMock(\Magento\Catalog\Model\Product::class);
        $object->expects($this->once())->method('getData')->willReturn($value);

        $this->assertTrue($this->model->validate($object));
    }

    /**
     * @return array
     */
    public function dataProviderValidate()
    {
        return [
            'US simple' => ['1234.56'],
            'US full'   => ['123,456.78'],
            'Brazil'    => ['123.456,78'],
            'India'     => ['1,23,456.78'],
            'Lebanon'   => ['1 234'],
            'zero'      => ['0.00'],
            'NaN becomes zero' => ['kiwi'],
        ];
    }

    /**
     * Tests for the cases that expect to fail validation
     *
     * @dataProvider dataProviderValidateForFailure
     */
    public function testValidateForFailure($value)
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $object = $this->createMock(\Magento\Catalog\Model\Product::class);
        $object->expects($this->once())->method('getData')->willReturn($value);

        $this->model->validate($object);
        $this->fail('Expected the following value to NOT validate: ' . $value);
    }

    /**
     * @return array
     */
    public function dataProviderValidateForFailure()
    {
        return [
            'negative US simple' => ['-1234.56'],
            'negative US full'   => ['-123,456.78'],
            'negative Brazil'    => ['-123.456,78'],
            'negative India'     => ['-1,23,456.78'],
            'negative Lebanon'   => ['-1 234'],
        ];
    }
}
