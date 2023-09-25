<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Usps\Test\Unit\Model\Source;

class GenericTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Usps\Model\Source\Generic
     */
    protected $_generic;

    /**
     * @var \Magento\Usps\Model\Carrier
     */
    protected $_uspsModel;

    protected function setUp(): void
    {
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_uspsModel = $this->getMockBuilder(
            \Magento\Usps\Model\Carrier::class
        )->setMethods(
            ['getCode']
        )->disableOriginalConstructor()->getMock();

        $this->_generic = $helper->getObject(
            \Magento\Usps\Model\Source\Generic::class,
            ['shippingUsps' => $this->_uspsModel]
        );
    }

    /**
     * @dataProvider getCodeDataProvider
     * @param array$expected array
     * @param array $options
     */
    public function testToOptionArray($expected, $options)
    {
        $this->_uspsModel->expects($this->any())->method('getCode')->willReturn($options);

        $this->assertEquals($expected, $this->_generic->toOptionArray());
    }

    /**
     * @return array expected result and return of \Magento\Usps\Model\Carrier::getCode
     */
    public function getCodeDataProvider()
    {
        return [
            [[['value' => 'Val', 'label' => 'Label']], ['Val' => 'Label']],
            [[], false]
        ];
    }
}
