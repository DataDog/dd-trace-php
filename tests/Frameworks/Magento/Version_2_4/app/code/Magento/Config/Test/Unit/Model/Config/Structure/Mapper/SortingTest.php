<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Config\Test\Unit\Model\Config\Structure\Mapper;

use Magento\Config\Model\Config\Structure\Mapper\Sorting;
use PHPUnit\Framework\TestCase;

class SortingTest extends TestCase
{
    /**
     * @var Sorting
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new Sorting();
    }

    public function testMap()
    {
        $tabs = [
            'tab_1' => ['sortOrder' => 10],
            'tab_2' => ['sortOrder' => 5],
            'tab_3' => ['sortOrder' => 1],
        ];

        $sections = [
            'section_1' => ['sortOrder' => 10],
            'section_2' => ['sortOrder' => 5],
            'section_3' => ['sortOrder' => 1],
            'section_4' => [
                'sortOrder' => 500,
                'children' => [
                    'group_1' => ['sortOrder' => 150],
                    'group_2' => ['sortOrder' => 20],
                    'group_3' => [
                        'sortOrder' => 30,
                        'children' => [
                            'field_1' => ['sortOrder' => 200],
                            'field_2' => ['sortOrder' => 100],
                            'subGroup' => [
                                'sortOrder' => 0,
                                'children' => [
                                    'field_4' => ['sortOrder' => 200],
                                    'field_5' => ['sortOrder' => 100],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $data = ['config' => ['system' => ['tabs' => $tabs, 'sections' => $sections]]];

        $expected = [
            'config' => [
                'system' => [
                    'tabs' => [
                        'tab_3' => ['sortOrder' => 1],
                        'tab_2' => ['sortOrder' => 5],
                        'tab_1' => ['sortOrder' => 10],
                    ],
                    'sections' => [
                        'section_3' => ['sortOrder' => 1],
                        'section_2' => ['sortOrder' => 5],
                        'section_1' => ['sortOrder' => 10],
                        'section_4' => [
                            'sortOrder' => 500,
                            'children' => [
                                'group_2' => ['sortOrder' => 20],
                                'group_3' => [
                                    'sortOrder' => 30,
                                    'children' => [
                                        'subGroup' => [
                                            'sortOrder' => 0,
                                            'children' => [
                                                'field_5' => ['sortOrder' => 100],
                                                'field_4' => ['sortOrder' => 200],
                                            ],
                                        ],
                                        'field_2' => ['sortOrder' => 100],
                                        'field_1' => ['sortOrder' => 200],
                                    ],
                                ],
                                'group_1' => ['sortOrder' => 150],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $actual = $this->_model->map($data);
        $this->assertEquals($expected, $actual);
    }
}
