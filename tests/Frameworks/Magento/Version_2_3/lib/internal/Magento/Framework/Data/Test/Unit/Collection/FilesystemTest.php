<?php
/***
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Data\Test\Unit\Collection;

class FilesystemTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\Data\Collection\Filesystem */
    private $model;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(\Magento\Framework\Data\Collection\Filesystem::class);
    }

    /**
     * @param $field
     * @param $filterValue
     * @param $row
     * @param $expected
     *
     * @dataProvider testFilterCallbackLikeDataProvider
     */
    public function testFilterCallbackLike($field, $filterValue, $row, $expected)
    {
        $filterValue = new \Zend_Db_Expr($filterValue);

        $this->assertEquals($expected, $this->model->filterCallbackLike($field, $filterValue, $row));
    }

    /**
     * @return array
     */
    public function testFilterCallbackLikeDataProvider()
    {
        $field     = 'field';
        $testValue = '\'\'\'test\'\'\'Filter\'\'\'Value\'\'\'';
        return [
            [$field, '\'%test%\'', [$field => $testValue,], true],
            [$field, '%\'test%', [$field => $testValue,], true],
            [$field, '%\'test\'%', [$field => $testValue,], true],
            [$field, '%\'\'test%', [$field => $testValue,], true],
            [$field, '%\'\'test\'\'%', [$field => $testValue,], true],
            [$field, '%\'\'\'test%', [$field => $testValue,], true],
            [$field, '%\'\'\'test\'\'\'%', [$field => $testValue,], true],
            [$field, '%\'\'\'\'test%', [$field => $testValue,], false],

            [$field, '\'%Value%\'', [$field => $testValue,], true],
            [$field, '%Value\'%', [$field => $testValue,], true],
            [$field, '%\'Value\'%', [$field => $testValue,], true],
            [$field, '%Value\'\'%', [$field => $testValue,], true],
            [$field, '%\'\'Value\'\'%', [$field => $testValue,], true],
            [$field, '%Value\'\'\'%', [$field => $testValue,], true],
            [$field, '%\'\'\'Value\'\'\'%', [$field => $testValue,], true],
            [$field, '%Value%\'\'\'\'%', [$field => $testValue,], false],

            [$field, '\'%\'\'\'test\'\'\'Filter\'\'\'Value\'\'\'%\'', [$field => $testValue,], true],
            [$field, '\'\'\'%\'\'\'test\'\'\'Filter\'\'\'Value\'\'\'%\'\'\'', [$field => $testValue,], true],
            [$field, '%test\'\'\'Filter\'\'\'Value%', [$field => $testValue,], true],
            [$field, '%test\'\'\'Filter\'\'\'Value\'\'\'%', [$field => $testValue,], true],
            [$field, '%\'\'\'test\'\'\'Filter\'\'\'Value%', [$field => $testValue,], true],
            [$field, '%\'\'\'Filter\'\'\'Value\'\'\'%', [$field => $testValue,], true],
            [$field, '%Filter\'\'\'Value\'\'\'%', [$field => $testValue,], true],
            [$field, '%\'\'\'Filter\'\'\'Value%', [$field => $testValue,], true],
            [$field, '%Filter\'\'\'Value%', [$field => $testValue,], true],
            [$field, '%Filter\'\'\'\'Value%', [$field => $testValue,], false],

            [$field, '\'%\'\'\'Filter\'\'\'%\'', [$field => $testValue,], true],
            [$field, '%Filter\'\'\'%', [$field => $testValue,], true],
            [$field, '%\'\'\'Filter%', [$field => $testValue,], true],
            [$field, '%\'Filter%', [$field => $testValue,], true],
            [$field, '%Filter\'%', [$field => $testValue,], true],
            [$field, '%Filter%', [$field => $testValue,], true],
            [$field, '%Filter\'\'\'\'%', [$field => $testValue,], false],

            [$field, '\'%no_match_value%\'', [$field => $testValue,], false],
        ];
    }
}
