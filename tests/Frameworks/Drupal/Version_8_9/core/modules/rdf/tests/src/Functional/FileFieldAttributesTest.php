<?php

namespace Drupal\Tests\rdf\Functional;

use Drupal\Core\Url;
use Drupal\Tests\file\Functional\FileFieldTestBase;
use Drupal\file\Entity\File;
use Drupal\Tests\rdf\Traits\RdfParsingTrait;

/**
 * Tests the RDFa markup of filefields.
 *
 * @group rdf
 */
class FileFieldAttributesTest extends FileFieldTestBase {

  use RdfParsingTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['rdf', 'file'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * URI of the front page of the Drupal site.
   *
   * @var string
   */
  protected $baseUri;

  /**
   * The name of the file field used in the test.
   *
   * @var string
   */
  protected $fieldName;

  /**
   * The file object used in the test.
   *
   * @var \Drupal\file\FileInterface
   */
  protected $file;

  /**
   * The node object used in the test.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  protected function setUp() {
    parent::setUp();
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->fieldName = strtolower($this->randomMachineName());

    $type_name = 'article';
    $this->createFileField($this->fieldName, 'node', $type_name);

    // Set the teaser display to show this field.
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'article', 'teaser')
      ->setComponent($this->fieldName, ['type' => 'file_default'])
      ->save();

    // Set the RDF mapping for the new field.
    $mapping = rdf_get_mapping('node', 'article');
    $mapping->setFieldMapping($this->fieldName, ['properties' => ['rdfs:seeAlso'], 'mapping_type' => 'rel'])->save();

    $test_file = $this->getTestFile('text');

    // Create a new node with the uploaded file.
    $nid = $this->uploadNodeFile($test_file, $this->fieldName, $type_name);

    $node_storage->resetCache([$nid]);
    $this->node = $node_storage->load($nid);
    $this->file = File::load($this->node->{$this->fieldName}->target_id);

    // Prepares commonly used URIs.
    $this->baseUri = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
  }

  /**
   * Tests if file fields in teasers have correct resources.
   *
   * Ensure that file fields have the correct resource as the object in RDFa
   * when displayed as a teaser.
   */
  public function testNodeTeaser() {
    // Render the teaser.
    $node_render_array = \Drupal::entityTypeManager()
      ->getViewBuilder('node')
      ->view($this->node, 'teaser');
    $html = \Drupal::service('renderer')->renderRoot($node_render_array);

    $node_uri = $this->node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $file_uri = file_create_url($this->file->getFileUri());

    // Node relation to attached file.
    $expected_value = [
      'type' => 'uri',
      'value' => $file_uri,
    ];
    $this->assertTrue($this->hasRdfProperty($html, $this->baseUri, $node_uri, 'http://www.w3.org/2000/01/rdf-schema#seeAlso', $expected_value), 'Node to file relation found in RDF output (rdfs:seeAlso).');
    $this->drupalGet('node');
  }

}
