<?php

/**
 * @file
 * Contains \Drupal\Tests\Core\Menu\LocalActionManagerTest.
 */

namespace Drupal\Tests\Core\Menu;

use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\Container;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Menu\LocalActionManager;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Prophecy\Prophet;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;

/**
 * @coversDefaultClass \Drupal\Core\Menu\LocalActionManager
 * @group Menu
 */
class LocalActionManagerTest extends UnitTestCase {

  /**
   * The mocked argument resolver.
   *
   * @var \Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $argumentResolver;

  /**
   * The mocked request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $request;

  /**
   * The mocked module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleHandler;

  /**
   * The mocked router provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeProvider;

  /**
   * The mocked cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cacheBackend;

  /**
   * The mocked access manager.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $accessManager;

  /**
   * The mocked account.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $account;

  /**
   * The mocked factory.
   *
   * @var \Drupal\Component\Plugin\Factory\FactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $factory;

  /**
   * The mocked plugin discovery.
   *
   * @var \Drupal\Component\Plugin\Discovery\DiscoveryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $discovery;

  /**
   * The tested local action manager.
   *
   * @var \Drupal\Tests\Core\Menu\TestLocalActionManager
   */
  protected $localActionManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->argumentResolver = $this->createMock('\Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface');
    $this->request = $this->createMock('Symfony\Component\HttpFoundation\Request');
    $this->routeProvider = $this->createMock('Drupal\Core\Routing\RouteProviderInterface');
    $this->moduleHandler = $this->createMock('Drupal\Core\Extension\ModuleHandlerInterface');
    $this->cacheBackend = $this->createMock('Drupal\Core\Cache\CacheBackendInterface');

    $cache_contexts_manager = $this->prophesize(CacheContextsManager::class);
    $cache_contexts_manager->assertValidTokens(Argument::any())
      ->willReturn(TRUE);

    $container = new Container();
    $container->set('cache_contexts_manager', $cache_contexts_manager->reveal());
    \Drupal::setContainer($container);

    $access_result = (new AccessResultForbidden())->cachePerPermissions();
    $this->accessManager = $this->createMock('Drupal\Core\Access\AccessManagerInterface');
    $this->accessManager->expects($this->any())
      ->method('checkNamedRoute')
      ->willReturn($access_result);
    $this->account = $this->createMock('Drupal\Core\Session\AccountInterface');
    $this->discovery = $this->createMock('Drupal\Component\Plugin\Discovery\DiscoveryInterface');
    $this->factory = $this->createMock('Drupal\Component\Plugin\Factory\FactoryInterface');
    $route_match = $this->createMock('Drupal\Core\Routing\RouteMatchInterface');

    $this->localActionManager = new TestLocalActionManager($this->argumentResolver, $this->request, $route_match, $this->routeProvider, $this->moduleHandler, $this->cacheBackend, $this->accessManager, $this->account, $this->discovery, $this->factory);
  }

  /**
   * @covers ::getTitle
   */
  public function testGetTitle() {
    $local_action = $this->createMock('Drupal\Core\Menu\LocalActionInterface');
    $local_action->expects($this->once())
      ->method('getTitle')
      ->with('test');

    $this->argumentResolver->expects($this->once())
      ->method('getArguments')
      ->with($this->request, [$local_action, 'getTitle'])
      ->willReturn(['test']);

    $this->localActionManager->getTitle($local_action);
  }

  /**
   * @covers ::getActionsForRoute
   *
   * @dataProvider getActionsForRouteProvider
   */
  public function testGetActionsForRoute($route_appears, array $plugin_definitions, array $expected_actions) {
    $this->discovery->expects($this->any())
      ->method('getDefinitions')
      ->willReturn($plugin_definitions);
    $map = [];
    foreach ($plugin_definitions as $plugin_id => $plugin_definition) {
      $plugin = $this->createMock('Drupal\Core\Menu\LocalActionInterface');
      $plugin->expects($this->any())
        ->method('getRouteName')
        ->willReturn($plugin_definition['route_name']);
      $plugin->expects($this->any())
        ->method('getRouteParameters')
        ->willReturn($plugin_definition['route_parameters'] ?? []);
      $plugin->expects($this->any())
        ->method('getTitle')
        ->willReturn($plugin_definition['title']);
      $this->argumentResolver->expects($this->any())
        ->method('getArguments')
        ->with($this->request, [$plugin, 'getTitle'])
        ->willReturn([]);

      $plugin->expects($this->any())
        ->method('getWeight')
        ->willReturn($plugin_definition['weight']);
      $this->argumentResolver->expects($this->any())
        ->method('getArguments')
        ->with($this->request, [$plugin, 'getTitle'])
        ->willReturn([]);
      $map[] = [$plugin_id, [], $plugin];
    }
    $this->factory->expects($this->any())
      ->method('createInstance')
      ->willReturnMap($map);

    $this->assertEquals($expected_actions, $this->localActionManager->getActionsForRoute($route_appears));
  }

  public static function getActionsForRouteProvider() {
    $cache_contexts_manager = (new Prophet())->prophesize(CacheContextsManager::class);
    $cache_contexts_manager->assertValidTokens(Argument::any())
      ->willReturn(TRUE);

    $container = new Container();
    $container->set('cache_contexts_manager', $cache_contexts_manager->reveal());
    \Drupal::setContainer($container);

    // Single available and single expected plugins.
    $data[] = [
      'test_route',
      [
        'plugin_id_1' => [
          'appears_on' => [
            'test_route',
          ],
          'route_name' => 'test_route_2',
          'title' => 'Plugin ID 1',
          'weight' => 0,
        ],
      ],
      [
        '#cache' => [
          'tags' => [],
          'contexts' => ['route', 'user.permissions'],
          'max-age' => 0,
        ],
        'plugin_id_1' => [
          '#theme' => 'menu_local_action',
          '#link' => [
            'title' => 'Plugin ID 1',
            'url' => Url::fromRoute('test_route_2'),
            'localized_options' => '',
          ],
          '#access' => AccessResult::forbidden()->cachePerPermissions(),
          '#weight' => 0,
        ],
      ],
    ];
    // Multiple available and single expected plugins.
    $data[] = [
      'test_route',
      [
        'plugin_id_1' => [
          'appears_on' => [
            'test_route',
          ],
          'route_name' => 'test_route_2',
          'title' => 'Plugin ID 1',
          'weight' => 0,
        ],
        'plugin_id_2' => [
          'appears_on' => [
            'test_route2',
          ],
          'route_name' => 'test_route_3',
          'title' => 'Plugin ID 2',
          'weight' => 0,
        ],
      ],
      [
        '#cache' => [
          'tags' => [],
          'contexts' => ['route', 'user.permissions'],
          'max-age' => 0,
        ],
        'plugin_id_1' => [
          '#theme' => 'menu_local_action',
          '#link' => [
            'title' => 'Plugin ID 1',
            'url' => Url::fromRoute('test_route_2'),
            'localized_options' => '',
          ],
          '#access' => AccessResult::forbidden()->cachePerPermissions(),
          '#weight' => 0,
        ],
      ],
    ];

    // Multiple available and multiple expected plugins and specified weight.
    $data[] = [
      'test_route',
      [
        'plugin_id_1' => [
          'appears_on' => [
            'test_route',
          ],
          'route_name' => 'test_route_2',
          'title' => 'Plugin ID 1',
          'weight' => 1,
        ],
        'plugin_id_2' => [
          'appears_on' => [
            'test_route',
          ],
          'route_name' => 'test_route_3',
          'title' => 'Plugin ID 2',
          'weight' => 0,
        ],
      ],
      [
        '#cache' => [
          'contexts' => ['route', 'user.permissions'],
          'tags' => [],
          'max-age' => 0,
        ],
        'plugin_id_1' => [
          '#theme' => 'menu_local_action',
          '#link' => [
            'title' => 'Plugin ID 1',
            'url' => Url::fromRoute('test_route_2'),
            'localized_options' => '',
          ],
          '#access' => AccessResult::forbidden()->cachePerPermissions(),
          '#weight' => 1,
        ],
        'plugin_id_2' => [
          '#theme' => 'menu_local_action',
          '#link' => [
            'title' => 'Plugin ID 2',
            'url' => Url::fromRoute('test_route_3'),
            'localized_options' => '',
          ],
          '#access' => AccessResult::forbidden()->cachePerPermissions(),
          '#weight' => 0,
        ],
      ],
    ];

    // Two plugins with the same route name but different route parameters.
    $data[] = [
      'test_route',
      [
        'plugin_id_1' => [
          'appears_on' => [
            'test_route',
          ],
          'route_name' => 'test_route_2',
          'route_parameters' => ['test1'],
          'title' => 'Plugin ID 1',
          'weight' => 1,
        ],
        'plugin_id_2' => [
          'appears_on' => [
            'test_route',
          ],
          'route_name' => 'test_route_2',
          'route_parameters' => ['test2'],
          'title' => 'Plugin ID 2',
          'weight' => 0,
        ],
      ],
      [
        '#cache' => [
          'contexts' => ['route', 'user.permissions'],
          'tags' => [],
          'max-age' => 0,
        ],
        'plugin_id_1' => [
          '#theme' => 'menu_local_action',
          '#link' => [
            'title' => 'Plugin ID 1',
            'url' => Url::fromRoute('test_route_2', ['test1']),
            'localized_options' => '',
          ],
          '#access' => AccessResult::forbidden()->cachePerPermissions(),
          '#weight' => 1,
        ],
        'plugin_id_2' => [
          '#theme' => 'menu_local_action',
          '#link' => [
            'title' => 'Plugin ID 2',
            'url' => Url::fromRoute('test_route_2', ['test2']),
            'localized_options' => '',
          ],
          '#access' => AccessResult::forbidden()->cachePerPermissions(),
          '#weight' => 0,
        ],
      ],
    ];

    return $data;
  }

}

class TestLocalActionManager extends LocalActionManager {

  public function __construct(ArgumentResolverInterface $argument_resolver, Request $request, RouteMatchInterface $route_match, RouteProviderInterface $route_provider, ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend, AccessManagerInterface $access_manager, AccountInterface $account, DiscoveryInterface $discovery, FactoryInterface $factory) {
    $this->discovery = $discovery;
    $this->factory = $factory;
    $this->routeProvider = $route_provider;
    $this->accessManager = $access_manager;
    $this->account = $account;
    $this->argumentResolver = $argument_resolver;
    $this->requestStack = new RequestStack();
    $this->requestStack->push($request);
    $this->routeMatch = $route_match;
    $this->moduleHandler = $module_handler;
    $this->alterInfo('menu_local_actions');
    $this->setCacheBackend($cache_backend, 'local_action_plugins', ['local_action']);
  }

}
