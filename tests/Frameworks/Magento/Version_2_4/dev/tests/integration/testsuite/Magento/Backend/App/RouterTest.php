<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\App;

/**
 * @magentoAppArea adminhtml
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RouterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\App\Router
     */
    protected $model;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->model = $this->objectManager->create(\Magento\Backend\App\Router::class);
    }

    public function testRouterCanProcessRequestsWithProperPathInfo()
    {
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->expects($this->once())->method('getPathInfo')->willReturn('backend/admin/dashboard');

        $this->assertInstanceOf(\Magento\Backend\Controller\Adminhtml\Dashboard::class, $this->model->match($request));
    }

    /**
     * @param string $module
     * @param string $controller
     * @param string $className
     *
     * @dataProvider getControllerClassNameDataProvider
     */
    public function testGetControllerClassName($module, $controller, $className)
    {
        $this->assertEquals($className, $this->model->getActionClassName($module, $controller));
    }

    public function getControllerClassNameDataProvider()
    {
        return [
            ['Magento_TestModule', 'controller', \Magento\TestModule\Controller\Adminhtml\Controller::class],
        ];
    }

    public function testMatchCustomNoRouteAction()
    {
        if (!\Magento\TestFramework\Helper\Bootstrap::canTestHeaders()) {
            $this->markTestSkipped('Can\'t test get match without sending headers');
        }

        $routers = [
            'testmodule' => [
                'frontName' => 'testfixture',
                'id' => 'testfixture',
                'modules' => ['Magento_TestFixture'],
            ],
        ];

        $routeConfig = $this->getMockBuilder(\Magento\Framework\App\Route\Config::class)
            ->setMethods(['_getRoutes'])
            ->setConstructorArgs(
                [
                    'reader' => $this->objectManager->get(\Magento\Framework\App\Route\Config\Reader::class),
                    'cache' => $this->objectManager->get(\Magento\Framework\Config\CacheInterface::class),
                    'configScope' => $this->objectManager->get(\Magento\Framework\Config\ScopeInterface::class),
                    'areaList' => $this->objectManager->get(\Magento\Framework\App\AreaList::class),
                    'cacheId' => 'RoutesConfig'
                ]
            )
            ->getMock();

        $routeConfig->expects($this->any())->method('_getRoutes')->willReturn($routers);

        $defaultRouter = $this->objectManager->create(
            \Magento\Backend\App\Router::class,
            ['routeConfig' => $routeConfig]
        );

        /** @var $request \Magento\TestFramework\Request */
        $request = $this->objectManager->get(\Magento\TestFramework\Request::class);

        $request->setPathInfo('backend/testfixture/test_controller');
        $controller = $defaultRouter->match($request);
        $this->assertInstanceOf(\Magento\TestFixture\Controller\Adminhtml\Noroute::class, $controller);
        $this->assertEquals('noroute', $request->getActionName());
    }
}
