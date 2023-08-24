<?php

namespace Drupal\Tests\block_content\Functional;

use Drupal\Component\Render\FormattableMarkup;

/**
 * Tests block content validation constraints.
 *
 * @group block_content
 */
class BlockContentValidationTest extends BlockContentTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the block content validation constraints.
   */
  public function testValidation() {
    // Add a block.
    $description = $this->randomMachineName();
    $block = $this->createBlockContent($description, 'basic');
    // Validate the block.
    $violations = $block->validate();
    // Make sure we have no violations.
    $this->assertCount(0, $violations);
    // Save the block.
    $block->save();

    // Add another block with the same description.
    $block = $this->createBlockContent($description, 'basic');
    // Validate this block.
    $violations = $block->validate();
    // Make sure we have 1 violation.
    $this->assertCount(1, $violations);
    // Make sure the violation is on the info property
    $this->assertEquals('info', $violations[0]->getPropertyPath());
    // Make sure the message is correct.
    $this->assertEquals(new FormattableMarkup('A custom block with Block description %value already exists.', ['%value' => $block->label()]), $violations[0]->getMessage());
  }

}
