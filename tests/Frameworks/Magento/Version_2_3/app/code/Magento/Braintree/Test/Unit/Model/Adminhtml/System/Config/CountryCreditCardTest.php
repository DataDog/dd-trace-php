<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Braintree\Test\Unit\Model\Adminhtml\System\Config;

use Magento\Braintree\Model\Adminhtml\System\Config\CountryCreditCard;
use Magento\Framework\Math\Random;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Class CountryCreditCardTest
 *
 */
class CountryCreditCardTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Braintree\Model\Adminhtml\System\Config\CountryCreditCard
     */
    protected $model;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceMock;

    /**
     * @var \Magento\Framework\Math\Random|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $mathRandomMock;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json|\PHPUnit\Framework\MockObject\MockObject
     */
    private $serializerMock;

    protected function setUp(): void
    {
        $this->resourceMock = $this->getMockForAbstractClass(AbstractResource::class);
        $this->mathRandomMock = $this->getMockBuilder(Random::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->serializerMock = $this->createMock(\Magento\Framework\Serialize\Serializer\Json::class);

        $this->objectManager = new ObjectManager($this);
        $this->model = $this->objectManager->getObject(
            CountryCreditCard::class,
            [
                'mathRandom' => $this->mathRandomMock,
                'resource' => $this->resourceMock,
                'serializer' => $this->serializerMock
            ]
        );
    }

    /**
     * @dataProvider beforeSaveDataProvider
     * @param array $value
     * @param array $expectedValue
     * @param string $encodedValue
     */
    public function testBeforeSave(array $value, array $expectedValue, $encodedValue)
    {
        $this->model->setValue($value);

        $this->serializerMock->expects($this->once())
            ->method('serialize')
            ->with($expectedValue)
            ->willReturn($encodedValue);

        $this->model->beforeSave();
        $this->assertEquals($encodedValue, $this->model->getValue());
    }

    /**
     * Get data for testing credit card types
     * @return array
     */
    public function beforeSaveDataProvider()
    {
        return [
            'empty_value' => [
                'value' => [],
                'expected' => [],
                'encoded' => '[]'
            ],
            'not_array' => [
                'value' => ['US'],
                'expected' => [],
                'encoded' => '[]'
            ],
            'array_with_invalid_format' => [
                'value' => [
                    [
                        'country_id' => 'US',
                    ],
                ],
                'expected' => [],
                'encoded' => '[]'
            ],
            'array_with_two_countries' => [
                'value' => [
                    [
                        'country_id' => 'AF',
                        'cc_types' => ['AE', 'VI']
                    ],
                    [
                        'country_id' => 'US',
                        'cc_types' => ['AE', 'VI', 'MA']
                    ],
                    '__empty' => "",
                ],
                'expected' => [
                    'AF' => ['AE', 'VI'],
                    'US' => ['AE', 'VI', 'MA'],
                ],
                'encoded' => '{"AF":["AE","VI"],"US":["AE","VI","MA"]}'
            ],
            'array_with_two_same_countries' => [
                'value' => [
                    [
                        'country_id' => 'AF',
                        'cc_types' => ['AE', 'VI']
                    ],
                    [
                        'country_id' => 'US',
                        'cc_types' => ['AE', 'VI', 'MA']
                    ],
                    [
                        'country_id' => 'US',
                        'cc_types' => ['VI', 'OT']
                    ],
                    '__empty' => "",
                ],
                'expected' => [
                    'AF' => ['AE', 'VI'],
                    'US' => ['AE', 'VI', 'MA', 'OT'],
                ],
                'encoded' => '{"AF":["AE","VI"],"US":["AE","VI","MA","OT"]}'
            ],
        ];
    }

    /**
     * @param string $encodedValue
     * @param array|null $value
     * @param array $hashData
     * @param array|null $expected
     * @param int $unserializeCalledNum
     * @dataProvider afterLoadDataProvider
     */
    public function testAfterLoad(
        $encodedValue,
        $value,
        array $hashData,
        $expected,
        $unserializeCalledNum = 1
    ) {
        $this->model->setValue($encodedValue);
        $index = 0;
        foreach ($hashData as $hash) {
            $this->mathRandomMock->expects($this->at($index))
                ->method('getUniqueHash')
                ->willReturn($hash);
            $index++;
        }

        $this->serializerMock->expects($this->exactly($unserializeCalledNum))
            ->method('unserialize')
            ->with($encodedValue)
            ->willReturn($value);

        $this->model->afterLoad();
        $this->assertEquals($expected, $this->model->getValue());
    }

    /**
     * Get data to test saved credit cards types
     *
     * @return array
     */
    public function afterLoadDataProvider()
    {
        return [
            'empty' => [
                'encoded' => '[]',
                'value' => [],
                'randomHash' => [],
                'expected' => []
            ],
            'null' => [
                'encoded' => '',
                'value' => null,
                'randomHash' => [],
                'expected' => null,
                0
            ],
            'valid data' => [
                'encoded' => '{"US":["AE","VI","MA"],"AF":["AE","MA"]}',
                'value' => [
                    'US' => ['AE', 'VI', 'MA'],
                    'AF' => ['AE', 'MA']
                ],
                'randomHash' => ['hash_1', 'hash_2'],
                'expected' => [
                    'hash_1' => [
                        'country_id' => 'US',
                        'cc_types' => ['AE', 'VI', 'MA']
                    ],
                    'hash_2' => [
                        'country_id' => 'AF',
                        'cc_types' => ['AE', 'MA']
                    ]
                ]
            ]
        ];
    }
}
