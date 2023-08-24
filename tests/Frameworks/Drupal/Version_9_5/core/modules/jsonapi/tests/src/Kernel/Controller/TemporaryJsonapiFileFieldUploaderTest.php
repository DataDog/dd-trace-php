<?php

namespace Drupal\Tests\jsonapi\Kernel\Controller;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\jsonapi\Controller\TemporaryJsonapiFileFieldUploader;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\jsonapi\Kernel\JsonapiKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * @coversDefaultClass \Drupal\jsonapi\Controller\TemporaryJsonapiFileFieldUploader
 * @group jsonapi
 */
class TemporaryJsonapiFileFieldUploaderTest extends JsonapiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'jsonapi',
    'serialization',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Add the entity schemas.
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    // Add the additional table schemas.
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    NodeType::create([
      'type' => 'lorem',
    ])->save();
    $type = NodeType::create([
      'type' => 'article',
    ]);
    $type->save();
    $type = NodeType::create([
      'type' => 'page',
    ]);
    $type->save();
    $this->createEntityReferenceField('node', 'article', 'field_relationships', 'Relationship', 'node', 'default', ['target_bundles' => ['article']], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    Role::create([
      'id' => 'article editor',
      'label' => 'article editor',
      'permissions' => [
        'access content',
        'create article content',
        'edit any article content',
      ],
    ])->save();

    Role::create([
      'id' => 'page editor',
      'label' => 'page editor',
      'permissions' => [
        'access content',
        'create page content',
        'edit any page content',
      ],
    ])->save();

    Role::create([
      'id' => 'editor',
      'label' => 'editor',
      'permissions' => [
        'bypass node access',
      ],
    ])->save();
  }

  /**
   * @covers ::checkFileUploadAccess
   */
  public function testCheckFileUploadAccessWithBaseField() {
    // Create a set of users for access testing.
    $article_editor = User::create([
      'name' => 'article editor',
      'mail' => 'article@localhost',
      'status' => 1,
      // Do not use UID 1 as that has access to everything.
      'uid' => 2,
      'roles' => ['article editor'],
    ]);
    $page_editor = User::create([
      'name' => 'page editor',
      'mail' => 'page@localhost',
      'status' => 1,
      'uid' => 3,
      'roles' => ['page editor'],
    ]);
    $editor = User::create([
      'name' => 'editor',
      'mail' => 'editor@localhost',
      'status' => 1,
      'uid' => 3,
      'roles' => ['editor'],
    ]);
    $no_access_user = User::create([
      'name' => 'no access',
      'mail' => 'user@localhost',
      'status' => 1,
      'uid' => 4,
    ]);

    // Create an entity to test access against.
    $node = Node::create([
      'title' => 'dummy_title',
      'type' => 'article',
      'uid' => 1,
    ]);

    // While the method is only used to check file fields it should work without
    // error for any field whether it is a base field or a bundle field.
    $base_field_definition = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('node')['title'];
    $bundle_field_definition = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'article')['field_relationships'];

    // Tests the expected access result for each user.
    // The $article_editor account can edit any article.
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($article_editor, $base_field_definition, $node);
    $this->assertTrue($result->isAllowed());
    // The article editor cannot create a node of undetermined type.
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($article_editor, $base_field_definition);
    $this->assertFalse($result->isAllowed());
    // The article editor can edit any article.
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($article_editor, $bundle_field_definition, $node);
    $this->assertTrue($result->isAllowed());
    // The article editor can create an article. The type can be determined
    // because the field is a bundle field.
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($article_editor, $bundle_field_definition);
    $this->assertTrue($result->isAllowed());

    // The $editor account has the bypass node access permissions and can edit
    // and create all node types.
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($editor, $base_field_definition, $node);
    $this->assertTrue($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($editor, $base_field_definition);
    $this->assertTrue($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($editor, $bundle_field_definition, $node);
    $this->assertTrue($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($editor, $bundle_field_definition);
    $this->assertTrue($result->isAllowed());

    // The $page_editor account can only edit and create pages therefore has no
    // access.
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($page_editor, $base_field_definition, $node);
    $this->assertFalse($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($page_editor, $base_field_definition);
    $this->assertFalse($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($page_editor, $bundle_field_definition, $node);
    $this->assertFalse($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($page_editor, $bundle_field_definition);
    $this->assertFalse($result->isAllowed());

    // The $no_access_user account has no access at all.
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($no_access_user, $base_field_definition, $node);
    $this->assertFalse($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($no_access_user, $base_field_definition);
    $this->assertFalse($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($no_access_user, $bundle_field_definition, $node);
    $this->assertFalse($result->isAllowed());
    $result = TemporaryJsonapiFileFieldUploader::checkFileUploadAccess($no_access_user, $bundle_field_definition);
    $this->assertFalse($result->isAllowed());
  }

}
