<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\App\Test\Unit;

use \Magento\Framework\App\Area;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AreaTest extends \PHPUnit\Framework\TestCase
{
    const SCOPE_ID = '1';

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Framework\Event\ManagerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventManagerMock;

    /**
     * @var \Magento\Framework\ObjectManagerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \Magento\Framework\App\ObjectManager\ConfigLoader | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $diConfigLoaderMock;

    /**
     * @var \Magento\Framework\TranslateInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $translatorMock;

    /**
     * @var \Psr\Log\LoggerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\App\DesignInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $designMock;

    /**
     * @var \Magento\Framework\App\ScopeResolverInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeResolverMock;

    /**
     * @var \Magento\Framework\View\DesignExceptions | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $designExceptionsMock;

    /**
     * @var string
     */
    protected $areaCode;

    /**
     * @var Area
     */
    protected $object;

    /** @var \Magento\Framework\Phrase\RendererInterface */
    private $defaultRenderer;

    protected function setUp(): void
    {
        $this->defaultRenderer = \Magento\Framework\Phrase::getRenderer();
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->loggerMock = $this->getMockBuilder(\Psr\Log\LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->eventManagerMock = $this->getMockBuilder(\Magento\Framework\Event\ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->translatorMock = $this->getMockBuilder(\Magento\Framework\TranslateInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->diConfigLoaderMock = $this->getMockBuilder(\Magento\Framework\App\ObjectManager\ConfigLoader::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManagerMock = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->designMock = $this->getMockBuilder(\Magento\Framework\App\DesignInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->scopeResolverMock = $this->getMockBuilder(\Magento\Framework\App\ScopeResolverInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $scopeMock = $this->getMockBuilder(\Magento\Framework\App\ScopeInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $scopeMock->expects($this->any())
            ->method('getId')
            ->willReturn(self::SCOPE_ID);
        $this->scopeResolverMock->expects($this->any())
            ->method('getScope')
            ->willReturn($scopeMock);
        $this->designExceptionsMock = $this->getMockBuilder(\Magento\Framework\View\DesignExceptions::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->areaCode = Area::AREA_FRONTEND;

        $this->object = $this->objectManager->getObject(
            \Magento\Framework\App\Area::class,
            [
                'logger' => $this->loggerMock,
                'objectManager' => $this->objectManagerMock,
                'eventManager' => $this->eventManagerMock,
                'translator' => $this->translatorMock,
                'diConfigLoader' => $this->diConfigLoaderMock,
                'design' => $this->designMock,
                'scopeResolver' => $this->scopeResolverMock,
                'designExceptions' => $this->designExceptionsMock,
                'areaCode' => $this->areaCode,
            ]
        );
    }

    protected function tearDown(): void
    {
        \Magento\Framework\Phrase::setRenderer($this->defaultRenderer);
    }

    public function testLoadConfig()
    {
        $this->verifyLoadConfig();
        $this->object->load(Area::PART_CONFIG);
    }

    public function testLoadTranslate()
    {
        $this->translatorMock->expects($this->once())
            ->method('loadData');
        $renderMock = $this->getMockBuilder(\Magento\Framework\Phrase\RendererInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\Phrase\RendererInterface::class)
            ->willReturn($renderMock);
        $this->object->load(Area::PART_TRANSLATE);
    }

    public function testLoadDesign()
    {
        $designMock = $this->getMockBuilder(\Magento\Framework\View\DesignInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\View\DesignInterface::class)
            ->willReturn($designMock);
        $designMock->expects($this->once())
            ->method('setArea')
            ->with($this->areaCode)
            ->willReturnSelf();
        $designMock->expects($this->once())
            ->method('setDefaultDesignTheme');
        $this->object->load(Area::PART_DESIGN);
    }

    public function testLoadUnknownPart()
    {
        $this->objectManagerMock->expects($this->never())
            ->method('configure');
        $this->objectManagerMock->expects($this->never())
            ->method('get');
        $this->object->load('unknown part');
    }

    public function testLoad()
    {
        $this->verifyLoadConfig();
        $this->translatorMock->expects($this->once())
            ->method('loadData');
        $renderMock = $this->getMockBuilder(\Magento\Framework\Phrase\RendererInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $designMock = $this->getMockBuilder(\Magento\Framework\View\DesignInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $designMock->expects($this->once())
            ->method('setArea')
            ->with($this->areaCode)
            ->willReturnSelf();
        $designMock->expects($this->once())
            ->method('setDefaultDesignTheme');
        $this->objectManagerMock->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap(
                [
                    [\Magento\Framework\Phrase\RendererInterface::class, $renderMock],
                    [\Magento\Framework\View\DesignInterface::class, $designMock],
                ]
            );
        $this->object->load();
    }

    private function verifyLoadConfig()
    {
        $configs = ['dummy configs'];
        $this->diConfigLoaderMock->expects($this->once())
            ->method('load')
            ->with($this->areaCode)
            ->willReturn($configs);
        $this->objectManagerMock->expects($this->once())
            ->method('configure')
            ->with($configs);
    }

    public function testDetectDesign()
    {
        $this->designExceptionsMock->expects($this->never())
            ->method('getThemeByRequest');
        $this->designMock->expects($this->once())
            ->method('loadChange')
            ->with(self::SCOPE_ID)
            ->willReturnSelf();
        $designMock = $this->getMockBuilder(\Magento\Framework\View\DesignInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\View\DesignInterface::class)
            ->willReturn($designMock);
        $this->designMock->expects($this->once())
            ->method('changeDesign')
            ->with($designMock)
            ->willReturnSelf();
        $this->object->detectDesign();
    }

    /**
     * @param string|bool $value
     * @param int $callNum
     * @param int $callNum2
     * @dataProvider detectDesignByRequestDataProvider
     */
    public function testDetectDesignByRequest($value, $callNum, $callNum2)
    {
        $this->designExceptionsMock->expects($this->once())
            ->method('getThemeByRequest')
            ->willReturn($value);
        $designMock = $this->getMockBuilder(\Magento\Framework\View\DesignInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $designMock->expects($this->exactly($callNum))
            ->method('setDesignTheme');
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\View\DesignInterface::class)
            ->willReturn($designMock);
        $this->designMock->expects($this->exactly($callNum2))
            ->method('loadChange')
            ->with(self::SCOPE_ID)
            ->willReturnSelf();
        $this->designMock->expects($this->exactly($callNum2))
            ->method('changeDesign')
            ->with($designMock)
            ->willReturnSelf();
        $requestMock = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->object->detectDesign($requestMock);
    }

    /**
     * @return array
     */
    public function detectDesignByRequestDataProvider()
    {
        return [
            [false, 0, 1],
            ['theme', 1, 0],
        ];
    }

    public function testDetectDesignByRequestWithException()
    {
        $exception = new \Exception('exception');
        $this->designExceptionsMock->expects($this->once())
            ->method('getThemeByRequest')
            ->will($this->throwException($exception));
        $designMock = $this->getMockBuilder(\Magento\Framework\View\DesignInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $designMock->expects($this->never())
            ->method('setDesignTheme');
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\View\DesignInterface::class)
            ->willReturn($designMock);
        $this->designMock->expects($this->once())
            ->method('loadChange')
            ->with(self::SCOPE_ID)
            ->willReturnSelf();
        $this->designMock->expects($this->once())
            ->method('changeDesign')
            ->with($designMock)
            ->willReturnSelf();
        $requestMock = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->loggerMock->expects($this->once())
            ->method('critical')
            ->with($exception);
        $this->object->detectDesign($requestMock);
    }
}
