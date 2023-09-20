<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Ui\Test\Unit\DataProvider;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Ui\DataProvider\EavValidationRules;

/**
 * Class EavValidationRulesTest
 */
class EavValidationRulesTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Ui\DataProvider\EavValidationRules
     */
    protected $subject;

    /**
     * @var \Magento\Eav\Model\Entity\Attribute\AbstractAttribute|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $attributeMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->attributeMock =
            $this->getMockBuilder(AbstractAttribute::class)
                ->setMethods(['getFrontendInput', 'getValidateRules'])
                ->disableOriginalConstructor()
                ->getMockForAbstractClass();

        $this->subject = new EavValidationRules();
    }

    /**
     * @param string $attributeInputType
     * @param mixed $validateRules
     * @param array $data
     * @param array $expected
     * @dataProvider buildDataProvider
     */
    public function testBuild($attributeInputType, $validateRules, $data, $expected): void
    {
        $this->attributeMock->expects($this->once())->method('getFrontendInput')->willReturn($attributeInputType);
        $this->attributeMock->expects($this->any())->method('getValidateRules')->willReturn($validateRules);
        $validationRules = $this->subject->build($this->attributeMock, $data);
        $this->assertEquals($expected, $validationRules);
    }

    /**
     * @return array
     */
    public function buildDataProvider()
    {
        return [
            ['', '', [], []],
            ['', null, [], []],
            ['', false, [], []],
            ['', [], [], []],
            ['', '', ['required' => 1], ['required-entry' => true]],
            ['price', '', [], ['validate-zero-or-greater' => true]],
            ['price', '', ['required' => 1], ['validate-zero-or-greater' => true, 'required-entry' => true]],
            ['', ['input_validation' => 'email'], [], ['validate-email' => true]],
            ['', ['input_validation' => 'date'], [], ['validate-date' => true]],
            ['', ['input_validation' => 'other'], [], []],
            ['', ['max_text_length' => '254'], ['required' => 1], ['required-entry' => true]],
            [
                '',
                ['input_validation' => 'other', 'max_text_length' => '254'],
                ['required' => 1],
                ['max_text_length' => 254, 'required-entry' => true]
            ],
            [
                '',
                ['input_validation' => 'other', 'max_text_length' => '254', 'min_text_length' => 1],
                [],
                ['max_text_length' => 254, 'min_text_length' => 1]
            ],
            [
                '',
                ['max_text_length' => '254', 'input_validation' => 'date'],
                [],
                ['max_text_length' => 254, 'validate-date' => true]
            ],
        ];
    }
}
