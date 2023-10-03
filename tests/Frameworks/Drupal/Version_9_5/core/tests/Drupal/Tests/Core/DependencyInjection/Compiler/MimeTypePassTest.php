<?php

namespace Drupal\Tests\Core\DependencyInjection\Compiler;

use Drupal\Core\DependencyInjection\Compiler\MimeTypePass;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface as LegacyMimeTypeGuesserInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Drupal\Core\File\MimeType\MimeTypeGuesser;

/**
 * @coversDefaultClass \Drupal\Core\DependencyInjection\Compiler\MimeTypePass
 * @group DependencyInjection
 * @group legacy
 * @runInSeparateProcess
 */
class MimeTypePassTest extends UnitTestCase {

  protected function buildContainer($environment = 'dev') {
    $container = new ContainerBuilder();
    $container->setParameter('kernel.environment', $environment);
    return $container;
  }

  /**
   * Tests backwards compatibility shim for MimeTypeGuesser interface changes.
   */
  public function testProcessLegacy() {
    $this->expectDeprecation('The "Drupal\Tests\Core\DependencyInjection\Compiler\LegacyMimeTypeGuesser" class implements "Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface" that is deprecated since Symfony 4.3, use {@link MimeTypesInterface} instead.');
    $container = $this->buildContainer();
    $container
      ->register('file.mime_type.guesser', MimeTypeGuesser::class);

    $container
      ->register('handler1', __NAMESPACE__ . '\NewMimeTypeGuesser')
      ->addTag('mime_type_guesser', ['priority' => 200]);
    $container
      ->register('handler2', __NAMESPACE__ . '\LegacyMimeTypeGuesser')
      ->addTag('mime_type_guesser', ['priority' => 100]);

    $handler_pass = new MimeTypePass();
    $handler_pass->process($container);
    $method_calls = $container->getDefinition('file.mime_type.guesser')->getMethodCalls();
    $this->assertCount(2, $method_calls);
    $this->assertSame(200, $method_calls[0][1][1]);
    $this->assertSame(100, $method_calls[1][1][1]);
  }

}

class NewMimeTypeGuesser implements MimeTypeGuesserInterface {

  public function guessMimeType(string $string): string {
    return '';
  }

  public function isGuesserSupported(): bool {
    return TRUE;
  }

}

class LegacyMimeTypeGuesser implements LegacyMimeTypeGuesserInterface {

  public function guess($string) {}

}
