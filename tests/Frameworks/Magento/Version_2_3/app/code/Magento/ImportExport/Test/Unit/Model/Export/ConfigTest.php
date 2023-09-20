<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ImportExport\Test\Unit\Model\Export;

class ConfigTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\ImportExport\Model\Export\Config\Reader|\PHPUnit\Framework\MockObject\MockObject
     */
    private $readerMock;

    /**
     * @var \Magento\Framework\Config\CacheInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cacheMock;

    /**
     * @var \Magento\Framework\Serialize\SerializerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $serializerMock;

    /**
     * @var string
     */
    private $cacheId = 'some_id';

    /**
     * @var \Magento\ImportExport\Model\Export\Config
     */
    private $model;

    protected function setUp(): void
    {
        $this->readerMock = $this->createMock(\Magento\ImportExport\Model\Export\Config\Reader::class);
        $this->cacheMock = $this->createMock(\Magento\Framework\Config\CacheInterface::class);
        $this->serializerMock = $this->createMock(\Magento\Framework\Serialize\SerializerInterface::class);
    }

    /**
     * @param array $value
     * @param null|string $expected
     * @dataProvider getEntitiesDataProvider
     */
    public function testGetEntities($value, $expected)
    {
        $this->cacheMock->expects(
            $this->any()
        )->method(
            'load'
        )->with(
            $this->cacheId
        )->willReturn(
            false
        );
        $this->readerMock->expects($this->any())->method('read')->willReturn($value);
        $this->model = new \Magento\ImportExport\Model\Export\Config(
            $this->readerMock,
            $this->cacheMock,
            $this->cacheId,
            $this->serializerMock
        );
        $this->assertEquals($expected, $this->model->getEntities('entities'));
    }

    /**
     * @return array
     */
    public function getEntitiesDataProvider()
    {
        return [
            'entities_key_exist' => [['entities' => 'value'], 'value'],
            'return_default_value' => [['key_one' => 'value'], null]
        ];
    }

    /**
     * @param array $configData
     * @param string $entity
     * @param string[] $expectedResult
     * @dataProvider getEntityTypesDataProvider
     */
    public function testGetEntityTypes($configData, $entity, $expectedResult)
    {
        $this->cacheMock->expects(
            $this->any()
        )->method(
            'load'
        )->with(
            $this->cacheId
        )->willReturn(
            false
        );
        $this->readerMock->expects($this->any())->method('read')->willReturn($configData);
        $this->model = new \Magento\ImportExport\Model\Export\Config(
            $this->readerMock,
            $this->cacheMock,
            $this->cacheId,
            $this->serializerMock
        );
        $this->assertEquals($expectedResult, $this->model->getEntityTypes($entity));
    }

    /**
     * @return array
     */
    public function getEntityTypesDataProvider()
    {
        return [
            'valid type' => [
                [
                    'entities' => [
                        'catalog_product' => [
                            'types' => ['configurable', 'simple'],
                        ],
                    ],
                ],
                'catalog_product',
                ['configurable', 'simple'],
            ],
            'not existing entity' => [
                [
                    'entities' => [
                        'catalog_product' => [
                            'types' => ['configurable', 'simple'],
                        ],
                    ],
                ],
                'not existing entity',
                [],
            ],
        ];
    }

    /**
     * @param array $value
     * @param null|string $expected
     * @dataProvider getFileFormatsDataProvider
     */
    public function testGetFileFormats($value, $expected)
    {
        $this->cacheMock->expects(
            $this->any()
        )->method(
            'load'
        )->with(
            $this->cacheId
        )->willReturn(
            false
        );
        $this->readerMock->expects($this->any())->method('read')->willReturn($value);
        $this->model = new \Magento\ImportExport\Model\Export\Config(
            $this->readerMock,
            $this->cacheMock,
            $this->cacheId,
            $this->serializerMock
        );
        $this->assertEquals($expected, $this->model->getFileFormats('fileFormats'));
    }

    /**
     * @return array
     */
    public function getFileFormatsDataProvider()
    {
        return [
            'fileFormats_key_exist' => [['fileFormats' => 'value'], 'value'],
            'return_default_value' => [['key_one' => 'value'], null]
        ];
    }
}
