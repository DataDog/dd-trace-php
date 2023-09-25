<?php
/**
 * test Magento\Customer\Model\Metadata\Form\AbstractData
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Model\Metadata\Form;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AbstractDataTest extends \PHPUnit\Framework\TestCase
{
    const MODEL = 'MODEL';

    /** @var \Magento\Customer\Test\Unit\Model\Metadata\Form\ExtendsAbstractData */
    protected $_model;

    /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Stdlib\DateTime\TimezoneInterface */
    protected $_localeMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Locale\ResolverInterface */
    protected $_localeResolverMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject | \Psr\Log\LoggerInterface */
    protected $_loggerMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Customer\Api\Data\AttributeMetadataInterface */
    protected $_attributeMock;

    /** @var string */
    protected $_value;

    /** @var string */
    protected $_entityTypeCode;

    /** @var string */
    protected $_isAjax;

    protected function setUp(): void
    {
        $this->_localeMock = $this->getMockBuilder(
            \Magento\Framework\Stdlib\DateTime\TimezoneInterface::class
        )->disableOriginalConstructor()->getMock();
        $this->_localeResolverMock = $this->getMockBuilder(
            \Magento\Framework\Locale\ResolverInterface::class
        )->disableOriginalConstructor()->getMock();
        $this->_loggerMock = $this->getMockBuilder(\Psr\Log\LoggerInterface::class)->getMock();
        $this->_attributeMock = $this->createMock(\Magento\Customer\Api\Data\AttributeMetadataInterface::class);
        $this->_value = 'VALUE';
        $this->_entityTypeCode = 'ENTITY_TYPE_CODE';
        $this->_isAjax = false;

        $this->_model = new ExtendsAbstractData(
            $this->_localeMock,
            $this->_loggerMock,
            $this->_attributeMock,
            $this->_localeResolverMock,
            $this->_value,
            $this->_entityTypeCode,
            $this->_isAjax
        );
    }

    public function testGetAttribute()
    {
        $this->assertSame($this->_attributeMock, $this->_model->getAttribute());
    }

    /**
     */
    public function testGetAttributeException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Attribute object is undefined');

        $this->_model->setAttribute(false);
        $this->_model->getAttribute();
    }

    public function testSetRequestScope()
    {
        $this->assertSame($this->_model, $this->_model->setRequestScope('REQUEST_SCOPE'));
        $this->assertSame('REQUEST_SCOPE', $this->_model->getRequestScope());
    }

    /**
     * @param bool $bool
     * @dataProvider trueFalseDataProvider
     */
    public function testSetRequestScopeOnly($bool)
    {
        $this->assertSame($this->_model, $this->_model->setRequestScopeOnly($bool));
        $this->assertSame($bool, $this->_model->isRequestScopeOnly());
    }

    /**
     * @return array
     */
    public function trueFalseDataProvider()
    {
        return [[true], [false]];
    }

    public function testGetSetExtractedData()
    {
        $data = ['KEY' => 'VALUE'];
        $this->assertSame($this->_model, $this->_model->setExtractedData($data));
        $this->assertSame($data, $this->_model->getExtractedData());
        $this->assertSame('VALUE', $this->_model->getExtractedData('KEY'));
        $this->assertNull($this->_model->getExtractedData('BAD_KEY'));
    }

    /**
     * @param bool|string $input
     * @param bool|string $output
     * @param bool|string $filter
     * @dataProvider applyInputFilterProvider
     */
    public function testApplyInputFilter($input, $output, $filter)
    {
        if ($input) {
            $this->_attributeMock->expects($this->once())->method('getInputFilter')->willReturn($filter);
        }
        $this->assertEquals($output, $this->_model->applyInputFilter($input));
    }

    /**
     * @return array
     */
    public function applyInputFilterProvider()
    {
        return [
            [false, false, false],
            [true, true, false],
            ['string', 'string', false],
            ['2014/01/23', '2014-01-23', 'date'],
            ['<tag>internal text</tag>', 'internal text', 'striptags']
        ];
    }

    /**
     * @param null|bool|string $format
     * @param string           $output
     * @dataProvider dateFilterFormatProvider
     */
    public function testDateFilterFormat($format, $output)
    {
        // Since model is instantiated in setup, if I use it directly in the dataProvider, it will be null.
        // I use this value to indicate the model is to be used for output
        if (self::MODEL == $output) {
            $output = $this->_model;
        }
        if ($format === null) {
            $this->_localeMock->expects(
                $this->once()
            )->method(
                'getDateFormat'
            )->with(
                $this->equalTo(\IntlDateFormatter::SHORT)
            )->willReturn(
                $output
            );
        }
        $actual = $this->_model->dateFilterFormat($format);
        $this->assertEquals($output, $actual);
    }

    /**
     * @return array
     */
    public function dateFilterFormatProvider()
    {
        return [[null, 'Whatever I put'], [false, self::MODEL], ['something else', self::MODEL]];
    }

    /**
     * @param bool|string $input
     * @param bool|string $output
     * @param bool|string $filter
     * @dataProvider applyOutputFilterDataProvider
     */
    public function testApplyOutputFilter($input, $output, $filter)
    {
        if ($input) {
            $this->_attributeMock->expects($this->once())->method('getInputFilter')->willReturn($filter);
        }
        $this->assertEquals($output, $this->_model->applyOutputFilter($input));
    }

    /**
     * This is similar to applyInputFilterProvider except for striptags
     *
     * @return array
     */
    public function applyOutputFilterDataProvider()
    {
        return [
            [false, false, false],
            [true, true, false],
            ['string', 'string', false],
            ['2014/01/23', '2014-01-23', 'date'],
            ['internal text', 'internal text', 'striptags']
        ];
    }

    /**
     * Tests input validation rules.
     *
     * @param null|string $value
     * @param null|string $label
     * @param null|string $inputValidation
     * @param bool|array  $expectedOutput
     * @dataProvider validateInputRuleDataProvider
     */
    public function testValidateInputRule($value, $label, $inputValidation, $expectedOutput): void
    {
        $validationRule = $this->getMockBuilder(\Magento\Customer\Api\Data\ValidationRuleInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getName', 'getValue'])
            ->getMockForAbstractClass();

        $validationRule->method('getName')
            ->willReturn('input_validation');

        $validationRule->method('getValue')
            ->willReturn($inputValidation);

        $this->_attributeMock->method('getStoreLabel')
            ->willReturn($label);

        $this->_attributeMock->method('getValidationRules')
            ->willReturn([$validationRule]);

        $this->assertEquals($expectedOutput, $this->_model->validateInputRule($value));
    }

    /**
     * @return array
     */
    public function validateInputRuleDataProvider()
    {
        return [
            [null, null, null, true],
            ['value', null, null, true],
            [
                '!@#$',
                'mylabel',
                'alphanumeric',
                [
                    \Zend_Validate_Alnum::NOT_ALNUM => '"mylabel" contains non-alphabetic or non-numeric characters.'
                ]
            ],
            [
                'abc qaz',
                'mylabel',
                'alphanumeric',
                [
                    \Zend_Validate_Alnum::NOT_ALNUM => '"mylabel" contains non-alphabetic or non-numeric characters.'
                ]
            ],
            ['abcqaz', 'mylabel', 'alphanumeric', true],
            ['abc qaz', 'mylabel', 'alphanum-with-spaces', true],
            [
                '!@#$',
                'mylabel',
                'numeric',
                [\Zend_Validate_Digits::NOT_DIGITS => '"mylabel" contains non-numeric characters.']
            ],
            [
                '1234',
                'mylabel',
                'alpha',
                [\Zend_Validate_Alpha::NOT_ALPHA => '"mylabel" contains non-alphabetic characters.']
            ],
            [
                '!@#$',
                'mylabel',
                'email',
                [
                    // @codingStandardsIgnoreStart
                    \Zend_Validate_EmailAddress::INVALID_HOSTNAME => '"mylabel" is not a valid hostname.',
                    \Zend_Validate_Hostname::INVALID_HOSTNAME => "'#\$' does not match the expected structure for a DNS hostname",
                    \Zend_Validate_Hostname::INVALID_LOCAL_NAME => "'#\$' does not look like a valid local network name."
                    // @codingStandardsIgnoreEnd
                ]
            ],
            ['1234', 'mylabel', 'url', ['"mylabel" is not a valid URL.']],
            ['http://.com', 'mylabel', 'url', ['"mylabel" is not a valid URL.']],
            [
                '1234',
                'mylabel',
                'date',
                [\Zend_Validate_Date::INVALID_DATE => '"mylabel" is not a valid date.']
            ]
        ];
    }

    /**
     * @param bool $ajaxRequest
     * @dataProvider trueFalseDataProvider
     */
    public function testGetIsAjaxRequest($ajaxRequest)
    {
        $this->_model = new ExtendsAbstractData(
            $this->_localeMock,
            $this->_loggerMock,
            $this->_attributeMock,
            $this->_localeResolverMock,
            $this->_value,
            $this->_entityTypeCode,
            $ajaxRequest
        );
        $this->assertSame($ajaxRequest, $this->_model->getIsAjaxRequest());
    }

    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param string                        $attributeCode
     * @param bool|string                   $requestScope
     * @param bool                          $requestScopeOnly
     * @param string                        $expectedValue
     * @dataProvider getRequestValueDataProvider
     */
    public function testGetRequestValue($request, $attributeCode, $requestScope, $requestScopeOnly, $expectedValue)
    {
        $this->_attributeMock->expects(
            $this->once()
        )->method(
            'getAttributeCode'
        )->willReturn(
            $attributeCode
        );
        $this->_model->setRequestScope($requestScope);
        $this->_model->setRequestScopeOnly($requestScopeOnly);
        $this->assertEquals($expectedValue, $this->_model->getRequestValue($request));
    }

    /**
     * @return array
     */
    public function getRequestValueDataProvider()
    {
        $expectedValue = 'EXPECTED_VALUE';
        $requestMockOne = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)->getMock();
        $requestMockOne->expects(
            $this->any()
        )->method(
            'getParam'
        )->with(
            'ATTR_CODE'
        )->willReturn(
            $expectedValue
        );

        $requestMockTwo = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)->getMock();
        $requestMockTwo->expects(
            $this->at(0)
        )->method(
            'getParam'
        )->with(
            'REQUEST_SCOPE'
        )->willReturn(
            ['ATTR_CODE' => $expectedValue]
        );

        $requestMockFour = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)->getMock();
        $requestMockFour->expects(
            $this->at(0)
        )->method(
            'getParam'
        )->with(
            'REQUEST_SCOPE'
        )->willReturn(
            []
        );

        $requestMockThree = $this->getMockBuilder(
            \Magento\Framework\App\Request\Http::class
        )->disableOriginalConstructor()->getMock();
        $requestMockThree->expects(
            $this->once()
        )->method(
            'getParams'
        )->willReturn(
            ['REQUEST' => ['SCOPE' => ['ATTR_CODE' => $expectedValue]]]
        );
        return [
            [$requestMockOne, 'ATTR_CODE', false, false, $expectedValue],
            [$requestMockTwo, 'ATTR_CODE', 'REQUEST_SCOPE', false, $expectedValue],
            [$requestMockThree, 'ATTR_CODE', 'REQUEST/SCOPE', false, $expectedValue],
            [$requestMockFour, 'ATTR_CODE', 'REQUEST_SCOPE', false, false],
        ];
    }
}
