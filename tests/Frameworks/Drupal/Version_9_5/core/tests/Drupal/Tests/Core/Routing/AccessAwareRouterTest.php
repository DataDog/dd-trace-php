<?php

namespace Drupal\Tests\Core\Routing;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\AccessAwareRouter;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;

/**
 * @coversDefaultClass \Drupal\Core\Routing\AccessAwareRouter
 * @group Routing
 */
class AccessAwareRouterTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Routing\Router
   */
  protected $router;

  /**
   * @var \Symfony\Component\Routing\Route
   */
  protected $route;

  /**
   * @var \Drupal\Core\Routing\Router|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $coreRouter;

  /**
   * @var \Drupal\Core\Access\AccessManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $accessManager;

  /**
   * @var \Drupal\Core\Session\AccountInterface||\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * @var \Drupal\Core\Routing\AccessAwareRouter
   */
  protected $accessAwareRouter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->route = new Route('test');
    $this->accessManager = $this->createMock('Drupal\Core\Access\AccessManagerInterface');
    $this->currentUser = $this->createMock('Drupal\Core\Session\AccountInterface');
  }

  /**
   * Sets up a chain router with matchRequest.
   */
  protected function setupRouter() {
    $this->router = $this->getMockBuilder('Drupal\Core\Routing\Router')
      ->disableOriginalConstructor()
      ->getMock();
    $this->router->expects($this->once())
      ->method('matchRequest')
      ->willReturn([RouteObjectInterface::ROUTE_OBJECT => $this->route]);
    $this->accessAwareRouter = new AccessAwareRouter($this->router, $this->accessManager, $this->currentUser);
  }

  /**
   * Tests the matchRequest() function for access allowed.
   */
  public function testMatchRequestAllowed() {
    $this->setupRouter();
    $request = new Request();
    $access_result = AccessResult::allowed();
    $this->accessManager->expects($this->once())
      ->method('checkRequest')
      ->with($request)
      ->willReturn($access_result);
    $parameters = $this->accessAwareRouter->matchRequest($request);
    $expected = [
      RouteObjectInterface::ROUTE_OBJECT => $this->route,
      AccessAwareRouterInterface::ACCESS_RESULT => $access_result,
    ];
    $this->assertSame($expected, $request->attributes->all());
    $this->assertSame($expected, $parameters);
  }

  /**
   * Tests the matchRequest() function for access denied.
   */
  public function testMatchRequestDenied() {
    $this->setupRouter();
    $request = new Request();
    $access_result = AccessResult::forbidden();
    $this->accessManager->expects($this->once())
      ->method('checkRequest')
      ->with($request)
      ->willReturn($access_result);
    $this->expectException(AccessDeniedHttpException::class);
    $this->accessAwareRouter->matchRequest($request);
  }

  /**
   * Tests the matchRequest() function for access denied with reason message.
   */
  public function testCheckAccessResultWithReason() {
    $this->setupRouter();
    $request = new Request();
    $reason = $this->getRandomGenerator()->string();
    $access_result = AccessResult::forbidden($reason);
    $this->accessManager->expects($this->once())
      ->method('checkRequest')
      ->with($request)
      ->willReturn($access_result);
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage($reason);
    $this->accessAwareRouter->matchRequest($request);
  }

  /**
   * Ensure that methods are passed to the wrapped router.
   *
   * @covers ::__call
   */
  public function testCall() {
    $mock_router = $this->createMock('Symfony\Component\Routing\RouterInterface');

    $this->router = $this->getMockBuilder('Drupal\Core\Routing\Router')
      ->disableOriginalConstructor()
      ->addMethods(['add'])
      ->getMock();
    $this->router->expects($this->once())
      ->method('add')
      ->with($mock_router)
      ->willReturnSelf();
    $this->accessAwareRouter = new AccessAwareRouter($this->router, $this->accessManager, $this->currentUser);

    $this->accessAwareRouter->add($mock_router);
  }

}
