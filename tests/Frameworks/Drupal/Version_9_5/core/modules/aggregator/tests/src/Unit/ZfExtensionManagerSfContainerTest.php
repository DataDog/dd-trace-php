<?php

namespace Drupal\Tests\aggregator\Unit;

use Drupal\aggregator\ZfExtensionManagerSfContainer;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Laminas\Feed\Reader\Extension\Atom\Entry;
use Laminas\Feed\Reader\StandaloneExtensionManager;

/**
 * @coversDefaultClass \Drupal\aggregator\ZfExtensionManagerSfContainer
 * @group aggregator
 * @group legacy
 */
class ZfExtensionManagerSfContainerTest extends UnitTestCase {

  /**
   * @covers ::setContainer
   * @covers ::setStandalone
   * @covers ::get
   */
  public function testGet() {
    $service = new \stdClass();
    $service->value = 'my_value';
    $container = new ContainerBuilder();
    $container->set('foo', $service);
    $bridge = new ZfExtensionManagerSfContainer();
    $bridge->setContainer($container);
    $this->assertEquals($service, $bridge->get('foo'));
    $bridge->setStandalone(StandaloneExtensionManager::class);
    $this->assertInstanceOf(Entry::class, $bridge->get('Atom\Entry'));
    // Ensure that the standalone service is checked before the container.
    $container->set('atomentry', $service);
    $this->assertInstanceOf(Entry::class, $bridge->get('Atom\Entry'));
  }

  /**
   * @covers ::setContainer
   * @covers ::setStandalone
   * @covers ::has
   */
  public function testHas() {
    $service = new \stdClass();
    $service->value = 'my_value';
    $container = new ContainerBuilder();
    $container->set('foo', $service);
    $bridge = new ZfExtensionManagerSfContainer();
    $bridge->setContainer($container);
    $this->assertTrue($bridge->has('foo'));
    $this->assertFalse($bridge->has('bar'));
    $this->assertFalse($bridge->has('Atom\Entry'));
    $bridge->setStandalone(StandaloneExtensionManager::class);
    $this->assertTrue($bridge->has('Atom\Entry'));
  }

  /**
   * @covers ::setStandalone
   */
  public function testSetStandaloneException() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Drupal\Tests\aggregator\Unit\ZfExtensionManagerSfContainerTest must implement Laminas\Feed\Reader\ExtensionManagerInterface or Laminas\Feed\Writer\ExtensionManagerInterface');
    $bridge = new ZfExtensionManagerSfContainer();
    $bridge->setStandalone(static::class);
  }

  /**
   * @covers ::get
   */
  public function testGetContainerException() {
    $this->expectException(ServiceNotFoundException::class);
    $this->expectExceptionMessage('You have requested a non-existent service "test.foo".');
    $container = new ContainerBuilder();
    $bridge = new ZfExtensionManagerSfContainer('test.');
    $bridge->setContainer($container);
    $bridge->setStandalone(StandaloneExtensionManager::class);
    $bridge->get('foo');
  }

  /**
   * @covers ::__construct
   * @covers ::has
   * @covers ::get
   */
  public function testPrefix() {
    $service = new \stdClass();
    $service->value = 'my_value';
    $container = new ContainerBuilder();
    $container->set('foo.bar', $service);
    $bridge = new ZfExtensionManagerSfContainer('foo.');
    $bridge->setContainer($container);
    $this->assertTrue($bridge->has('bar'));
    $this->assertFalse($bridge->has('baz'));
    $this->assertEquals($service, $bridge->get('bar'));
  }

  /**
   * @covers ::canonicalizeName
   * @dataProvider canonicalizeNameProvider
   */
  public function testCanonicalizeName($name, $canonical_name) {
    $service = new \stdClass();
    $service->value = 'my_value';
    $container = new ContainerBuilder();
    $container->set($canonical_name, $service);
    $bridge = new ZfExtensionManagerSfContainer();
    $bridge->setContainer($container);
    $this->assertTrue($bridge->has($name));
    $this->assertEquals($service, $bridge->get($name));
  }

  /**
   * Data provider for testReverseProxyEnabled.
   *
   * Replacements:
   *   array('-' => '', '_' => '', ' ' => '', '\\' => '', '/' => '')
   */
  public function canonicalizeNameProvider() {
    return [
      [
        'foobar',
        'foobar',
      ],
      [
        'foo-bar',
        'foobar',
      ],
      [
        'foo_bar',
        'foobar',
      ],
      [
        'foo bar',
        'foobar',
      ],
      [
        'foo\\bar',
        'foobar',
      ],
      [
        'foo/bar',
        'foobar',
      ],
      // There is also a strtolower in canonicalizeName.
      [
        'Foo/bAr',
        'foobar',
      ],
      [
        'foo/-_\\ bar',
        'foobar',
      ],
    ];
  }

}
