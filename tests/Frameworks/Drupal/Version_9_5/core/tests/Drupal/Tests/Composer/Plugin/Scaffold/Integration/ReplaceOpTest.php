<?php

namespace Drupal\Tests\Composer\Plugin\Scaffold\Integration;

use Drupal\Composer\Plugin\Scaffold\Operations\ReplaceOp;
use Drupal\Composer\Plugin\Scaffold\ScaffoldOptions;
use Drupal\Tests\Composer\Plugin\Scaffold\Fixtures;
use Drupal\Tests\Traits\PhpUnitWarnings;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\Composer\Plugin\Scaffold\Operations\ReplaceOp
 *
 * @group Scaffold
 */
class ReplaceOpTest extends TestCase {
  use PhpUnitWarnings;

  /**
   * @covers ::process
   */
  public function testProcess() {
    $fixtures = new Fixtures();
    $destination = $fixtures->destinationPath('[web-root]/robots.txt');
    $source = $fixtures->sourcePath('drupal-assets-fixture', 'robots.txt');
    $options = ScaffoldOptions::create([]);
    $sut = new ReplaceOp($source, TRUE);
    // Assert that there is no target file before we run our test.
    $this->assertFileDoesNotExist($destination->fullPath());
    // Test the system under test.
    $sut->process($destination, $fixtures->io(), $options);
    // Assert that the target file was created.
    $this->assertFileExists($destination->fullPath());
    // Assert the target contained the contents from the correct scaffold file.
    $contents = trim(file_get_contents($destination->fullPath()));
    $this->assertEquals('# Test version of robots.txt from drupal/core.', $contents);
    // Confirm that expected output was written to our io fixture.
    $output = $fixtures->getOutput();
    $this->assertStringContainsString('Copy [web-root]/robots.txt from assets/robots.txt', $output);
  }

  /**
   * @covers ::process
   */
  public function testEmptyFile() {
    $fixtures = new Fixtures();
    $destination = $fixtures->destinationPath('[web-root]/empty_file.txt');
    $source = $fixtures->sourcePath('empty-file', 'empty_file.txt');
    $options = ScaffoldOptions::create([]);
    $sut = new ReplaceOp($source, TRUE);
    // Assert that there is no target file before we run our test.
    $this->assertFileDoesNotExist($destination->fullPath());
    // Test the system under test.
    $sut->process($destination, $fixtures->io(), $options);
    // Assert that the target file was created.
    $this->assertFileExists($destination->fullPath());
    // Assert the target contained the contents from the correct scaffold file.
    $this->assertSame('', file_get_contents($destination->fullPath()));
    // Confirm that expected output was written to our io fixture.
    $output = $fixtures->getOutput();
    $this->assertStringContainsString('Copy [web-root]/empty_file.txt from assets/empty_file.txt', $output);
  }

}
