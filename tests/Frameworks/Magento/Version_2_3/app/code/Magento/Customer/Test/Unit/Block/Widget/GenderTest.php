<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Test\Unit\Block\Widget;

use Magento\Customer\Block\Widget\Gender;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class GenderTest extends \PHPUnit\Framework\TestCase
{
    /** Constants used in the unit tests */
    const CUSTOMER_ENTITY_TYPE = 'customer';

    const GENDER_ATTRIBUTE_CODE = 'gender';

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Customer\Api\CustomerMetadataInterface
     */
    private $customerMetadata;

    /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Customer\Api\Data\AttributeMetadataInterface */
    private $attribute;

    /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Customer\Model\Session */
    private $customerSession;

    /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Customer\Api\CustomerRepositoryInterface */
    private $customerRepository;

    /** @var Gender */
    private $block;

    protected function setUp(): void
    {
        $this->attribute = $this->getMockBuilder(\Magento\Customer\Api\Data\AttributeMetadataInterface::class)
            ->getMockForAbstractClass();

        $this->customerMetadata = $this->getMockBuilder(\Magento\Customer\Api\CustomerMetadataInterface::class)
            ->getMockForAbstractClass();
        $this->customerMetadata->expects($this->any())
            ->method('getAttributeMetadata')
            ->with(self::GENDER_ATTRIBUTE_CODE)
            ->willReturn($this->attribute);

        $this->customerRepository = $this
            ->getMockBuilder(\Magento\Customer\Api\CustomerRepositoryInterface::class)
            ->getMockForAbstractClass();
        $this->customerSession = $this->createMock(\Magento\Customer\Model\Session::class);

        $this->block = new \Magento\Customer\Block\Widget\Gender(
            $this->createMock(\Magento\Framework\View\Element\Template\Context::class),
            $this->createMock(\Magento\Customer\Helper\Address::class),
            $this->customerMetadata,
            $this->customerRepository,
            $this->customerSession
        );
    }

    /**
     * Test the Gender::isEnabled() method.
     *
     * @param bool $isVisible Determines whether the 'gender' attribute is visible or enabled
     * @param bool $expectedValue The value we expect from Gender::isEnabled()
     * @return void
     *
     * @dataProvider isEnabledDataProvider
     */
    public function testIsEnabled($isVisible, $expectedValue)
    {
        $this->attribute->expects($this->once())->method('isVisible')->willReturn($isVisible);
        $this->assertSame($expectedValue, $this->block->isEnabled());
    }

    /**
     * The testIsEnabled data provider.
     * @return array
     */
    public function isEnabledDataProvider()
    {
        return [[true, true], [false, false]];
    }

    public function testIsEnabledWithException()
    {
        $this->customerMetadata->expects(
            $this->any()
        )->method(
            'getAttributeMetadata'
        )->will(
            $this->throwException(new NoSuchEntityException(
                __(
                    'No such entity with %fieldName = %fieldValue',
                    ['fieldName' => 'field', 'fieldValue' => 'value']
                )
            ))
        );
        $this->assertFalse($this->block->isEnabled());
    }

    /**
     * Test the Gender::isRequired() method.
     *
     * @param bool $isRequired Determines whether the 'gender' attribute is required
     * @param bool $expectedValue The value we expect from Gender::isRequired()
     * @return void
     *
     * @dataProvider isRequiredDataProvider
     */
    public function testIsRequired($isRequired, $expectedValue)
    {
        $this->attribute->expects($this->once())->method('isRequired')->willReturn($isRequired);
        $this->assertSame($expectedValue, $this->block->isRequired());
    }

    /**
     * The testIsRequired data provider.
     * @return array
     */
    public function isRequiredDataProvider()
    {
        return [[true, true], [false, false]];
    }

    public function testIsRequiredWithException()
    {
        $this->customerMetadata->expects(
            $this->any()
        )->method(
            'getAttributeMetadata'
        )->will(
            $this->throwException(new NoSuchEntityException(
                __(
                    'No such entity with %fieldName = %fieldValue',
                    ['fieldName' => 'field', 'fieldValue' => 'value']
                )
            ))
        );
        $this->assertFalse($this->block->isRequired());
    }

    /**
     * Test the Gender::getCustomer() method.
     * @return void
     */
    public function testGetCustomer()
    {
        $customerData = $this->getMockBuilder(\Magento\Customer\Api\Data\CustomerInterface::class)
            ->getMockForAbstractClass();
        $this->customerSession->expects($this->once())->method('getCustomerId')->willReturn(1);
        $this->customerRepository
            ->expects($this->once())
            ->method('getById')
            ->with(1)
            ->willReturn($customerData);

        $customer = $this->block->getCustomer();
        $this->assertSame($customerData, $customer);
    }

    /**
     * Test the Gender::getGenderOptions() method.
     * @return void
     */
    public function testGetGenderOptions()
    {
        $options = [
            ['label' => __('Male'), 'value' => 'M'],
            ['label' => __('Female'), 'value' => 'F'],
            ['label' => __('Not Specified'), 'value' => 'NA']
        ];

        $this->attribute->expects($this->once())->method('getOptions')->willReturn($options);
        $this->assertSame($options, $this->block->getGenderOptions());
    }
}
