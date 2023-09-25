<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Directory\Test\Unit\Model\Config\Source;

use Magento\Directory\Model\Config\Source\Allregion;
use Magento\Directory\Model\Region;
use Magento\Directory\Model\ResourceModel\Country\Collection;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class AllRegionTest extends TestCase
{
    /**
     * @var \Magento\Directory\Model\Config\Source\AllRegion
     */
    protected $model;

    /**
     * @var Collection
     */
    protected $countryCollection;

    /**
     * @var \Magento\Directory\Model\ResourceModel\Region\Collection
     */
    protected $regionCollection;

    protected function setUp(): void
    {
        $objectManagerHelper = new ObjectManager($this);

        $countryCollectionFactory = $this->getMockBuilder(
            CollectionFactory::class
        )->setMethods(['create', '__wakeup', '__sleep'])->disableOriginalConstructor()
            ->getMock();

        $this->countryCollection = $this->getMockBuilder(
            Collection::class
        )->setMethods(['load', 'toOptionArray', '__wakeup', '__sleep'])
            ->disableOriginalConstructor()
            ->getMock();
        $countryCollectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->countryCollection);
        $this->countryCollection->expects($this->once())
            ->method('load')
            ->willReturnSelf();

        $regionCollectionFactory = $this->getMockBuilder(
            \Magento\Directory\Model\ResourceModel\Region\CollectionFactory::class
        )->disableOriginalConstructor()
            ->setMethods(['create', '__wakeup', '__sleep'])->getMock();
        $this->regionCollection = $this->getMockBuilder(\Magento\Directory\Model\ResourceModel\Region\Collection::class)
            ->disableOriginalConstructor()
            ->setMethods(['load', 'getIterator', '__wakeup', '__sleep'])
            ->getMock();
        $regionCollectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->regionCollection);
        $this->regionCollection->expects($this->once())
            ->method('load')
            ->willReturn($this->regionCollection);

        $this->model = $objectManagerHelper->getObject(
            Allregion::class,
            [
                'countryCollectionFactory' => $countryCollectionFactory,
                'regionCollectionFactory' => $regionCollectionFactory
            ]
        );
    }

    /**
     * @dataProvider toOptionArrayDataProvider
     * @param bool $isMultiselect
     * @param array $countries
     * @param array $regions
     * @param array $expectedResult
     */
    public function testToOptionArray($isMultiselect, $countries, $regions, $expectedResult)
    {
        $this->countryCollection->expects($this->once())
            ->method('toOptionArray')
            ->with(false)
            ->willReturn(new \ArrayIterator($countries));
        $this->regionCollection->expects($this->once())
            ->method('getIterator')
            ->willReturn(new \ArrayIterator($regions));

        $this->assertEquals($expectedResult, $this->model->toOptionArray($isMultiselect));
    }

    /**
     * Return data sets for testToOptionArray()
     *
     * @return array
     */
    public function toOptionArrayDataProvider()
    {
        return [
            [
                false,
                [
                    $this->generateCountry('France', 'fr'),
                ],
                [
                    $this->generateRegion('fr', 1, 'Paris')
                ],
                [
                    [
                        'label' => '',
                        'value' => '',
                    ],
                    [
                        'label' => 'France',
                        'value' => [
                            [
                                'label' => 'Paris',
                                'value' => 1,
                            ],
                        ]
                    ]
                ],
            ],
            [
                true,
                [
                    $this->generateCountry('France', 'fr'),
                ],
                [
                    $this->generateRegion('fr', 1, 'Paris'),
                    $this->generateRegion('fr', 2, 'Marseille')
                ],
                [
                    [
                        'label' => 'France',
                        'value' => [
                            [
                                'label' => 'Paris',
                                'value' => 1,
                            ],
                            [
                                'label' => 'Marseille',
                                'value' => 2
                            ],
                        ],
                    ]
                ]
            ],
            [
                true,
                [
                    $this->generateCountry('France', 'fr'),
                    $this->generateCountry('Germany', 'de'),
                ],
                [
                    $this->generateRegion('fr', 1, 'Paris'),
                    $this->generateRegion('de', 2, 'Berlin')
                ],
                [
                    [
                        'label' => 'France',
                        'value' => [
                            [
                                'label' => 'Paris',
                                'value' => 1,
                            ],
                        ],
                    ],
                    [
                        'label' => 'Germany',
                        'value' => [
                            [
                                'label' => 'Berlin',
                                'value' => 2,
                            ],
                        ]
                    ]
                ]
            ],
        ];
    }

    /**
     * Generate a country array.
     *
     * @param string $countryLabel
     * @param string $countryValue
     * @return array
     */
    private function generateCountry($countryLabel, $countryValue)
    {
        return [
            'label' => $countryLabel,
            'value' => $countryValue
        ];
    }

    /**
     * Generate a mocked region.
     *
     * @param string $countryId
     * @param string $id
     * @param string $defaultName
     * @return Region
     */
    private function generateRegion($countryId, $id, $defaultName)
    {
        $region = $this->getMockBuilder(Region::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCountryId', 'getId', 'getDefaultName', '__wakeup', '__sleep'])
            ->getMock();
        $region->expects($this->once())
            ->method('getCountryId')
            ->willReturn($countryId);
        $region->expects($this->once())
            ->method('getId')
            ->willReturn($id);
        $region->expects($this->once())
            ->method('getDefaultName')
            ->willReturn($defaultName);

        return $region;
    }
}
