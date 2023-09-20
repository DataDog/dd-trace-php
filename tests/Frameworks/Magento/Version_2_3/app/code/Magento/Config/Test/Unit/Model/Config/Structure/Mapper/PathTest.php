<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Config\Test\Unit\Model\Config\Structure\Mapper;

class PathTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Config\Model\Config\Structure\Mapper\Path
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new \Magento\Config\Model\Config\Structure\Mapper\Path();
    }

    public function testMap()
    {
        $data = [
            'config' => [
                'system' => [
                    'sections' => [
                        'section_1' => [
                            'id' => 'section_1',
                            'children' => [
                                'group_1' => [
                                    'id' => 'group_1',
                                    'children' => [
                                        'field_1' => ['id' => 'field_1'],
                                        'group_1.1' => [
                                            'id' => 'group_1.1',
                                            'children' => ['field_1.2' => ['id' => 'field_1.2']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            'config' => [
                'system' => [
                    'sections' => [
                        'section_1' => [
                            'id' => 'section_1',
                            'children' => [
                                'group_1' => [
                                    'id' => 'group_1',
                                    'children' => [
                                        'field_1' => ['id' => 'field_1', 'path' => 'section_1/group_1'],
                                        'group_1.1' => [
                                            'id' => 'group_1.1',
                                            'children' => [
                                                'field_1.2' => [
                                                    'id' => 'field_1.2',
                                                    'path' => 'section_1/group_1/group_1.1',
                                                ],
                                            ],
                                            'path' => 'section_1/group_1',
                                        ],
                                    ],
                                    'path' => 'section_1',
                                ],
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
