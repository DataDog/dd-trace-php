<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Test\Unit\Model\Widget\Grid\Row;

class UrlGeneratorTest extends \PHPUnit\Framework\TestCase
{
    public function testGetUrl()
    {
        $itemId = 3;
        $urlPath = 'mng/item/edit';

        $itemMock = $this->createPartialMock(\Magento\Framework\DataObject::class, ['getItemId']);
        $itemMock->expects($this->once())->method('getItemId')->willReturn($itemId);

        $urlModelMock = $this->createMock(\Magento\Backend\Model\Url::class);
        $urlModelMock->expects(
            $this->once()
        )->method(
            'getUrl'
        )->willReturn(
            'http://localhost/' . $urlPath . '/flag/1/item_id/' . $itemId
        );

        $model = new \Magento\Backend\Model\Widget\Grid\Row\UrlGenerator(
            $urlModelMock,
            [
                'path' => $urlPath,
                'params' => ['flag' => 1],
                'extraParamsTemplate' => ['item_id' => 'getItemId']
            ]
        );

        $url = $model->getUrl($itemMock);

        $this->assertStringContainsString($urlPath, $url);
        $this->assertStringContainsString('flag/1', $url);
        $this->assertStringContainsString('item_id/' . $itemId, $url);
    }
}
