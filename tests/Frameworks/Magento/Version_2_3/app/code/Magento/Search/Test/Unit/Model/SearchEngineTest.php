<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Search\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class SearchEngineTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Search\Model\SearchEngine
     */
    private $searchEngine;

    /**
     * @var \Magento\Framework\Search\AdapterInterface |\PHPUnit\Framework\MockObject\MockObject
     */
    private $adapter;

    protected function setUp(): void
    {
        $helper = new ObjectManager($this);

        $adapterFactory = $this->getMockBuilder(\Magento\Search\Model\AdapterFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->adapter = $this->getMockBuilder(\Magento\Framework\Search\AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $adapterFactory->expects($this->once())->method('create')->willReturn($this->adapter);

        $this->searchEngine = $helper->getObject(
            \Magento\Search\Model\SearchEngine::class,
            [
                'adapterFactory' => $adapterFactory,
            ]
        );
    }

    public function testSearch()
    {
        $request = $this->getMockBuilder(\Magento\Framework\Search\RequestInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $response = $this->getMockBuilder(\Magento\Framework\Search\ResponseInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->adapter->expects($this->once())
            ->method('query')
            ->with($this->equalTo($request))
            ->willReturn($response);

        $result = $this->searchEngine->search($request);
        $this->assertInstanceOf(\Magento\Framework\Search\ResponseInterface::class, $result);
    }
}
