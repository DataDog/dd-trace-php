<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Test\Unit\Model\MessageGenerators;

use Magento\Signifyd\Model\MessageGenerators\PatternGenerator;

/**
 * Contains tests for different variations like empty data, wrong required arguments, or bad placeholders.
 */
class PatternGeneratorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Checks an exception if generators does not receives required data.
     *
     * @covers \Magento\Signifyd\Model\MessageGenerators\PatternGenerator::generate
     */
    public function testGenerateThrowsException()
    {
        $this->expectException(\Magento\Signifyd\Model\MessageGenerators\GeneratorException::class);
        $this->expectExceptionMessage('The "caseId" should not be empty.');

        $data = [];
        $generator = new PatternGenerator('Signifyd Case %1 has been created for order.', ['caseId']);
        $generator->generate($data);
    }

    /**
     * Checks cases with different template placeholders and input data.
     *
     * @covers \Magento\Signifyd\Model\MessageGenerators\PatternGenerator::generate
     * @param string $template
     * @param array $requiredFields
     * @param string $expected
     * @dataProvider messageDataProvider
     */
    public function testGenerate($template, array $requiredFields, $expected)
    {
        $data = [
            'caseId' => 123,
            'reviewDisposition' => 'Good',
            'guaranteeDisposition' => 'Approved',
            'score' => 500,
            'case_score' => 300
        ];

        $generator = new PatternGenerator($template, $requiredFields);
        $actual = $generator->generate($data);
        self::assertEquals($expected, $actual);
    }

    /**
     * Get list of variations with message templates, required fields and expected generated messages.
     *
     * @return array
     */
    public function messageDataProvider()
    {
        return [
            [
                'Signifyd Case %1 has been created for order.',
                ['caseId'],
                'Signifyd Case 123 has been created for order.'
            ],
            [
                'Case Update: Case Review was completed. Review Deposition is %1.',
                ['reviewDisposition'],
                'Case Update: Case Review was completed. Review Deposition is Good.'
            ],
            [
                'Case Update: New score for the order is %1. Previous score was %2.',
                ['score', 'case_score'],
                'Case Update: New score for the order is 500. Previous score was 300.'
            ],
            [
                'Case Update: Case is submitted for guarantee.',
                [],
                'Case Update: Case is submitted for guarantee.'
            ],
        ];
    }
}
