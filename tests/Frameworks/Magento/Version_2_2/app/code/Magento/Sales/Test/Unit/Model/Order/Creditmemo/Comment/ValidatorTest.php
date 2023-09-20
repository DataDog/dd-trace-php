<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\Order\Creditmemo\Comment;

/**
 * Class ValidatorTest
 */
class ValidatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Model\Order\Creditmemo\Comment\Validator
     */
    protected $validator;

    /**
     * @var \Magento\Sales\Model\Order\Creditmemo\Comment|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $commentModelMock;

    /**
     * Set up
     */
    protected function setUp()
    {
        $this->commentModelMock = $this->createPartialMock(
            \Magento\Sales\Model\Order\Creditmemo\Comment::class,
            ['hasData', 'getData', '__wakeup']
        );
        $this->validator = new \Magento\Sales\Model\Order\Creditmemo\Comment\Validator();
    }

    /**
     * Run test validate
     *
     * @param $commentDataMap
     * @param $commentData
     * @param $expectedWarnings
     * @dataProvider providerCommentData
     */
    public function testValidate($commentDataMap, $commentData, $expectedWarnings)
    {
        $this->commentModelMock->expects($this->any())
            ->method('hasData')
            ->will($this->returnValueMap($commentDataMap));
        $this->commentModelMock->expects($this->once())
            ->method('getData')
            ->will($this->returnValue($commentData));
        $actualWarnings = $this->validator->validate($this->commentModelMock);
        $this->assertEquals($expectedWarnings, $actualWarnings);
    }

    /**
     * Provides comment data for tests
     *
     * @return array
     */
    public function providerCommentData()
    {
        return [
            [
                [
                    ['parent_id', true],
                    ['comment', true],
                ],
                [
                    'parent_id' => 25,
                    'comment' => 'Hello world!'
                ],
                [],
            ],
            [
                [
                    ['parent_id', true],
                    ['comment', false],
                ],
                [
                    'parent_id' => 0,
                    'comment' => null
                ],
                [
                    'parent_id' => 'Parent Creditmemo Id can not be empty',
                    'comment' => 'Comment is a required field'
                ]
            ]
        ];
    }
}
