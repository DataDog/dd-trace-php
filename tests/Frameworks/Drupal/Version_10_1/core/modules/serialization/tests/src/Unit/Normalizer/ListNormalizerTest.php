<?php

namespace Drupal\Tests\serialization\Unit\Normalizer;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\serialization\Normalizer\ListNormalizer;
use Drupal\Core\TypedData\Plugin\DataType\ItemList;
use Symfony\Component\Serializer\Serializer;

/**
 * @coversDefaultClass \Drupal\serialization\Normalizer\ListNormalizer
 * @group serialization
 */
class ListNormalizerTest extends UnitTestCase {

  /**
   * The ListNormalizer instance.
   *
   * @var \Drupal\serialization\Normalizer\ListNormalizer
   */
  protected $normalizer;

  /**
   * The mock list instance.
   *
   * @var \Drupal\Core\TypedData\ListInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $list;

  /**
   * The expected list values to use for testing.
   *
   * @var array
   */
  protected $expectedListValues = ['test', 'test', 'test'];

  /**
   * The mocked typed data.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\Core\TypedData\TypedDataInterface
   */
  protected $typedData;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the TypedDataManager to return a TypedDataInterface mock.
    $this->typedData = $this->createMock('Drupal\Core\TypedData\TypedDataInterface');
    $typed_data_manager = $this->createMock(TypedDataManagerInterface::class);
    $typed_data_manager->expects($this->any())
      ->method('getPropertyInstance')
      ->willReturn($this->typedData);

    // Set up a mock container as ItemList() will call for the 'typed_data_manager'
    // service.
    $container = $this->getMockBuilder('Symfony\Component\DependencyInjection\ContainerBuilder')
      ->onlyMethods(['get'])
      ->getMock();
    $container->expects($this->any())
      ->method('get')
      ->with($this->equalTo('typed_data_manager'))
      ->willReturn($typed_data_manager);

    \Drupal::setContainer($container);

    $this->normalizer = new ListNormalizer();

    $this->list = new ItemList(new DataDefinition());
    $this->list->setValue($this->expectedListValues);
  }

  /**
   * Tests the supportsNormalization() method.
   */
  public function testSupportsNormalization() {
    $this->assertTrue($this->normalizer->supportsNormalization($this->list));
    $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
  }

  /**
   * Tests the normalize() method.
   */
  public function testNormalize() {
    $serializer = $this->prophesize(Serializer::class);
    $serializer->normalize($this->typedData, 'json', ['mu' => 'nu'])
      ->shouldBeCalledTimes(3)
      ->willReturn('test');

    $this->normalizer->setSerializer($serializer->reveal());

    $normalized = $this->normalizer->normalize($this->list, 'json', ['mu' => 'nu']);

    $this->assertEquals($this->expectedListValues, $normalized);
  }

}
