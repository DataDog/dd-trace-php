<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Downloadable\Test\Unit\Model\Product;

use Magento\Downloadable\Model\Product\TypeHandler\TypeHandlerInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Class TypeTest
 * Test for \Magento\Downloadable\Model\Product\Type
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TypeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Downloadable\Model\Product\Type
     */
    private $target;

    /**
     * @var TypeHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $typeHandler;

    /**
     * @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    private $product;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json|\PHPUnit\Framework\MockObject\MockObject
     */
    private $serializerMock;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Downloadable\Model\ResourceModel\Link\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $linksFactory;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $fileStorageDb = $this->getMockBuilder(
            \Magento\MediaStorage\Helper\File\Storage\Database::class
        )->disableOriginalConstructor()->getMock();
        $filesystem = $this->getMockBuilder(\Magento\Framework\Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $coreRegistry = $this->createMock(\Magento\Framework\Registry::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $productFactoryMock = $this->createMock(\Magento\Catalog\Model\ProductFactory::class);
        $sampleResFactory = $this->createMock(\Magento\Downloadable\Model\ResourceModel\SampleFactory::class);
        $linkResource = $this->createMock(\Magento\Downloadable\Model\ResourceModel\Link::class);
        $this->linksFactory = $this->createPartialMock(
            \Magento\Downloadable\Model\ResourceModel\Link\CollectionFactory::class,
            ['create']
        );
        $samplesFactory = $this->createMock(\Magento\Downloadable\Model\ResourceModel\Sample\CollectionFactory::class);
        $sampleFactory = $this->createMock(\Magento\Downloadable\Model\SampleFactory::class);
        $linkFactory = $this->createMock(\Magento\Downloadable\Model\LinkFactory::class);

        $entityTypeMock = $this->createMock(\Magento\Eav\Model\Entity\Type::class);
        $resourceProductMock = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Product::class,
            ['getEntityType']
        );
        $resourceProductMock->expects($this->any())->method('getEntityType')->willReturn($entityTypeMock);

        $this->serializerMock = $this->getMockBuilder(Json::class)
            ->setConstructorArgs(['serialize', 'unserialize'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->serializerMock->expects($this->any())
            ->method('serialize')
            ->willReturnCallback(
                function ($value) {
                    return json_encode($value);
                }
            );

        $this->serializerMock->expects($this->any())
            ->method('unserialize')
            ->willReturnCallback(
                function ($value) {
                    return json_decode($value, true);
                }
            );

        $this->product = $this->createPartialMock(\Magento\Catalog\Model\Product::class, [
                'getResource',
                'canAffectOptions',
                'getLinksPurchasedSeparately',
                'setTypeHasRequiredOptions',
                'setRequiredOptions',
                'getDownloadableData',
                'setTypeHasOptions',
                'setLinksExist',
                'getDownloadableLinks',
                '__wakeup',
                'getCustomOption',
                'addCustomOption',
                'getEntityId'
            ]);
        $this->product->expects($this->any())->method('getResource')->willReturn($resourceProductMock);
        $this->product->expects($this->any())->method('setTypeHasRequiredOptions')->with($this->equalTo(true))->willReturnSelf(
            
        );
        $this->product->expects($this->any())->method('setRequiredOptions')->with($this->equalTo(true))->willReturnSelf(
            
        );
        $this->product->expects($this->any())->method('setTypeHasOptions')->with($this->equalTo(false));
        $this->product->expects($this->any())->method('setLinksExist')->with($this->equalTo(false));
        $this->product->expects($this->any())->method('canAffectOptions')->with($this->equalTo(true));

        $eavConfigMock = $this->createPartialMock(\Magento\Eav\Model\Config::class, ['getEntityAttributes']);
        $eavConfigMock->expects($this->any())
            ->method('getEntityAttributes')
            ->with($this->equalTo($entityTypeMock), $this->equalTo($this->product))
            ->willReturn([]);

        $this->typeHandler = $this->getMockBuilder(\Magento\Downloadable\Model\Product\TypeHandler\TypeHandler::class)
            ->disableOriginalConstructor()
            ->setMethods(['save'])
            ->getMock();

        $this->target = $this->objectManager->getObject(
            \Magento\Downloadable\Model\Product\Type::class,
            [
                'eventManager' => $eventManager,
                'fileStorageDb' => $fileStorageDb,
                'filesystem' => $filesystem,
                'coreRegistry' => $coreRegistry,
                'logger' => $logger,
                'productFactory' => $productFactoryMock,
                'sampleResFactory' => $sampleResFactory,
                'linkResource' => $linkResource,
                'linksFactory' => $this->linksFactory,
                'samplesFactory' => $samplesFactory,
                'sampleFactory' => $sampleFactory,
                'linkFactory' => $linkFactory,
                'eavConfig' => $eavConfigMock,
                'typeHandler' => $this->typeHandler,
                'serializer' => $this->serializerMock
            ]
        );
    }

    public function testHasWeightFalse()
    {
        $this->assertFalse($this->target->hasWeight(), 'This product has weight, but it should not');
    }

    public function testBeforeSave()
    {
        $result = $this->target->beforeSave($this->product);
        $this->assertEquals($result, $this->target);
    }

    public function testHasLinks()
    {
        $this->product->expects($this->any())->method('getLinksPurchasedSeparately')->willReturn(true);
        $this->product->expects($this->exactly(2))
            ->method('getDownloadableLinks')
            ->willReturn(['link1', 'link2']);
        $this->assertTrue($this->target->hasLinks($this->product));
    }

    public function testCheckProductBuyState()
    {
        $optionMock = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item\Option::class)
            ->setMethods(['getValue'])
            ->disableOriginalConstructor()
            ->getMock();

        $optionMock->expects($this->any())->method('getValue')->willReturn('{}');

        $this->product->expects($this->any())
            ->method('getCustomOption')
            ->with('info_buyRequest')
            ->willReturn($optionMock);

        $this->product->expects($this->any())
            ->method('getLinksPurchasedSeparately')
            ->willReturn(false);

        $this->product->expects($this->any())
            ->method('getEntityId')
            ->willReturn(123);

        $linksCollectionMock = $this->createPartialMock(
            \Magento\Downloadable\Model\ResourceModel\Link\Collection::class,
            ['addProductToFilter', 'getAllIds']
        );

        $linksCollectionMock->expects($this->once())
            ->method('addProductToFilter')
            ->with(123)
            ->willReturnSelf();

        $linksCollectionMock->expects($this->once())
            ->method('getAllIds')
            ->willReturn([1, 2, 3]);

        $this->linksFactory->expects($this->any())
            ->method('create')
            ->willReturn($linksCollectionMock);

        $this->product->expects($this->once())
            ->method('addCustomOption')
            ->with('info_buyRequest', '{"links":[1,2,3]}');

        $this->target->checkProductBuyState($this->product);
    }

    /**
     */
    public function testCheckProductBuyStateException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Please specify product link(s).');

        $optionMock = $this->createPartialMock(\Magento\Quote\Model\Quote\Item\Option::class, ['getValue']);

        $optionMock->expects($this->any())->method('getValue')->willReturn('{}');

        $this->product->expects($this->any())
            ->method('getCustomOption')
            ->with('info_buyRequest')
            ->willReturn($optionMock);

        $this->product->expects($this->any())
            ->method('getLinksPurchasedSeparately')
            ->willReturn(true);

        $this->target->checkProductBuyState($this->product);
    }
}
