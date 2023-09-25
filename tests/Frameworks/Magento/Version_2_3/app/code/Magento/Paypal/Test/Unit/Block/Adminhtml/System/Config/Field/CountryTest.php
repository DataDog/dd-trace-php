<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Paypal\Block\Adminhtml\System\Config\Field\Country;

class CountryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Country
     */
    protected $_model;

    /**
     * @var \Magento\Framework\Data\Form\Element\AbstractElement
     */
    protected $_element;

    /**
     * @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_request;

    /**
     * @var \Magento\Framework\View\Helper\Js|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_jsHelper;

    /**
     * @var \Magento\Backend\Model\Url|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_url;

    protected function setUp(): void
    {
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_element = $this->getMockForAbstractClass(
            \Magento\Framework\Data\Form\Element\AbstractElement::class,
            [],
            '',
            false,
            true,
            true,
            ['getHtmlId', 'getElementHtml', 'getName']
        );
        $this->_element->expects($this->any())
            ->method('getHtmlId')
            ->willReturn('html id');
        $this->_element->expects($this->any())
            ->method('getElementHtml')
            ->willReturn('element html');
        $this->_element->expects($this->any())
            ->method('getName')
            ->willReturn('name');
        $this->_request = $this->getMockForAbstractClass(\Magento\Framework\App\RequestInterface::class);
        $this->_jsHelper = $this->createMock(\Magento\Framework\View\Helper\Js::class);
        $this->_url = $this->createMock(\Magento\Backend\Model\Url::class);
        $this->_model = $helper->getObject(
            \Magento\Paypal\Block\Adminhtml\System\Config\Field\Country::class,
            ['request' => $this->_request, 'jsHelper' => $this->_jsHelper, 'url' => $this->_url]
        );
    }

    /**
     * @param null|string $requestCountry
     * @param null|string $requestDefaultCountry
     * @param bool $canUseDefault
     * @param bool $inherit
     * @dataProvider renderDataProvider
     */
    public function testRender($requestCountry, $requestDefaultCountry, $canUseDefault, $inherit)
    {
        $this->_request->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(function ($param) use ($requestCountry, $requestDefaultCountry) {
                if ($param == \Magento\Paypal\Model\Config\StructurePlugin::REQUEST_PARAM_COUNTRY) {
                    return $requestCountry;
                }
                if ($param == Country::REQUEST_PARAM_DEFAULT_COUNTRY) {
                    return $requestDefaultCountry;
                }
                return $param;
            });
        $this->_element->setInherit($inherit);
        $this->_element->setCanUseDefaultValue($canUseDefault);
        $constraints = [
            new \PHPUnit\Framework\Constraint\StringContains('document.observe("dom:loaded", function() {'),
            new \PHPUnit\Framework\Constraint\StringContains(
                '$("' . $this->_element->getHtmlId() . '").observe("change", function () {'
            ),
        ];
        if ($canUseDefault && ($requestCountry == 'US') && $requestDefaultCountry) {
            $constraints[] = new \PHPUnit\Framework\Constraint\StringContains(
                '$("' . $this->_element->getHtmlId() . '_inherit").observe("click", function () {'
            );
        }
        $this->_jsHelper->expects($this->once())
            ->method('getScript')
            ->with(new \PHPUnit\Framework\Constraint\LogicalAnd($constraints));
        $this->_url->expects($this->once())
            ->method('getUrl')
            ->with(
                '*/*/*',
                [
                    'section' => 'section',
                    'website' => 'website',
                    'store' => 'store',
                    \Magento\Paypal\Model\Config\StructurePlugin::REQUEST_PARAM_COUNTRY => '__country__'
                ]
            );
        $this->_model->render($this->_element);
    }

    /**
     * @return array
     */
    public function renderDataProvider()
    {
        return [
            [null, null, false, false],
            [null, null, true, true],
            [null, null, true, false],
            ['IT', null, true, false],
            ['IT', null, true, true],
            ['IT', 'GB', true, false],
            ['US', 'GB', true, true],
            ['US', 'GB', true, false],
            ['US', null, true, false],
        ];
    }
}
