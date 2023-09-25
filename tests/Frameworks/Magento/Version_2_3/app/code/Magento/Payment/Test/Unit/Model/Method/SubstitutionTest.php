<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Payment\Test\Unit\Model\Method;

class SubstitutionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Payment\Model\Method\Substitution
     */
    protected $model;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $this->objectManager->getObject(\Magento\Payment\Model\Method\Substitution::class);
    }

    public function testGetTitle()
    {
        $infoMock = $this->getMockBuilder(
            \Magento\Payment\Model\Info::class
        )->disableOriginalConstructor()->setMethods(
            []
        )->getMock();

        $this->model->setInfoInstance($infoMock);
        $expectedResult = 'StringTitle';
        $infoMock->expects(
            $this->once()
        )->method(
            'getAdditionalInformation'
        )->with(
            \Magento\Payment\Model\Method\Substitution::INFO_KEY_TITLE
        )->willReturn(
            
                $expectedResult
            
        );

        $this->assertEquals($expectedResult, $this->model->getTitle());
    }
}
