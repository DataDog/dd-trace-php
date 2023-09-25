<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Downloadable\Test\Unit\Ui\DataProvider\Product\Form\Modifier\Data;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Downloadable\Ui\DataProvider\Product\Form\Modifier\Data\Links;
use \Magento\Framework\Escaper;
use Magento\Downloadable\Model\Product\Type;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Downloadable\Helper\File as DownloadableFile;
use Magento\Framework\UrlInterface;
use Magento\Downloadable\Model\Link as LinkModel;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Class LinksTest
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LinksTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    /**
     * @var LocatorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $locatorMock;

    /**
     * @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeConfigMock;

    /**
     * @var Escaper|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $escaperMock;

    /**
     * @var DownloadableFile|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $downloadableFileMock;

    /**
     * @var UrlInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $urlBuilderMock;

    /**
     * @var LinkModel|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $linkModelMock;

    /**
     * @var ProductInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productMock;

    /**
     * @var Links
     */
    protected $links;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->productMock = $this->getMockBuilder(ProductInterface::class)
            ->setMethods(['getLinksTitle', 'getId', 'getTypeId'])
            ->getMockForAbstractClass();
        $this->locatorMock = $this->getMockForAbstractClass(LocatorInterface::class);
        $this->scopeConfigMock = $this->getMockForAbstractClass(ScopeConfigInterface::class);
        $this->escaperMock = $this->createMock(Escaper::class);
        $this->downloadableFileMock = $this->createMock(DownloadableFile::class);
        $this->urlBuilderMock = $this->getMockForAbstractClass(UrlInterface::class);
        $this->linkModelMock = $this->createMock(LinkModel::class);
        $this->links = $this->objectManagerHelper->getObject(
            Links::class,
            [
                'escaper' => $this->escaperMock,
                'locator' => $this->locatorMock,
                'scopeConfig' => $this->scopeConfigMock,
                'downloadableFile' => $this->downloadableFileMock,
                'urlBuilder' => $this->urlBuilderMock,
                'linkModel' => $this->linkModelMock,
            ]
        );
    }

    /**
     * @param int|null $id
     * @param string $typeId
     * @param \PHPUnit\Framework\MockObject\Matcher\InvokedCount $expectedGetTitle
     * @param \PHPUnit\Framework\MockObject\Matcher\InvokedCount $expectedGetValue
     * @return void
     * @dataProvider getLinksTitleDataProvider
     */
    public function testGetLinksTitle($id, $typeId, $expectedGetTitle, $expectedGetValue)
    {
        $title = 'My Title';
        $this->locatorMock->expects($this->any())
            ->method('getProduct')
            ->willReturn($this->productMock);
        $this->productMock->expects($this->once())
            ->method('getId')
            ->willReturn($id);
        $this->productMock->expects($this->any())
            ->method('getTypeId')
            ->willReturn($typeId);
        $this->productMock->expects($expectedGetTitle)
            ->method('getLinksTitle')
            ->willReturn($title);
        $this->scopeConfigMock->expects($expectedGetValue)
            ->method('getValue')
            ->willReturn($title);

        $this->assertEquals($title, $this->links->getLinksTitle());
    }

    /**
     * @return array
     */
    public function getLinksTitleDataProvider()
    {
        return [
            [
                'id' => 1,
                'typeId' => Type::TYPE_DOWNLOADABLE,
                'expectedGetTitle' => $this->once(),
                'expectedGetValue' => $this->never(),
            ],
            [
                'id' => null,
                'typeId' => Type::TYPE_DOWNLOADABLE,
                'expectedGetTitle' => $this->never(),
                'expectedGetValue' => $this->once(),
            ],
            [
                'id' => 1,
                'typeId' => 'someType',
                'expectedGetTitle' => $this->never(),
                'expectedGetValue' => $this->once(),
            ],
            [
                'id' => null,
                'typeId' => 'someType',
                'expectedGetTitle' => $this->never(),
                'expectedGetValue' => $this->once(),
            ],
        ];
    }
}
