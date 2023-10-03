<?php

namespace Drupal\FunctionalTests\Entity;

use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\entity_test_revlog\Entity\EntityTestWithRevisionLog;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests version history page.
 *
 * @group Entity
 * @coversDefaultClass \Drupal\Core\Entity\Controller\VersionHistoryController
 */
class RevisionVersionHistoryTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'entity_test_revlog',
    'user',
  ];

  /**
   * Test all revisions appear, in order of revision creation.
   */
  public function testOrder(): void {
    /** @var \Drupal\entity_test_revlog\Entity\EntityTestWithRevisionLog $entity */
    $entity = EntityTestWithRevisionLog::create(['type' => 'entity_test_revlog']);
    // Need label to be able to assert order.
    $entity->setName('view all revisions');
    $user = $this->drupalCreateUser([], 'first revision');
    $entity->setRevisionUser($user);
    $entity->setNewRevision();
    $entity->save();

    $entity->setNewRevision();
    $user = $this->drupalCreateUser([], 'second revision');
    $entity->setRevisionUser($user);
    $entity->save();

    $entity->setNewRevision();
    $user = $this->drupalCreateUser([], 'third revision');
    $entity->setRevisionUser($user);
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementsCount('css', 'table tbody tr', 3);
    // Order is newest to oldest revision by creation order.
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', 'third revision');
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(2)', 'second revision');
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(3)', 'first revision');
  }

  /**
   * Test current revision is indicated.
   *
   * @covers \Drupal\Core\Entity\Controller\VersionHistoryController::revisionOverview
   */
  public function testCurrentRevision(): void {
    /** @var \Drupal\entity_test\Entity\EntityTestRev $entity */
    $entity = EntityTestRev::create(['type' => 'entity_test_rev']);
    // Need label to be able to assert order.
    $entity->setName('view all revisions');
    $entity->setNewRevision();
    $entity->save();

    $entity->setNewRevision();
    $entity->save();

    $entity->setNewRevision();
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementsCount('css', 'table tbody tr', 3);
    // Current revision text is found on the latest revision row.
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', 'Current revision');
    $this->assertSession()->elementTextNotContains('css', 'table tbody tr:nth-child(2)', 'Current revision');
    $this->assertSession()->elementTextNotContains('css', 'table tbody tr:nth-child(3)', 'Current revision');
    // Current revision row has 'revision-current' class.
    $this->assertSession()->elementAttributeContains('css', 'table tbody tr:nth-child(1)', 'class', 'revision-current');
  }

  /**
   * Test description with entity implementing revision log.
   *
   * @covers ::getRevisionDescription
   */
  public function testDescriptionRevLog(): void {
    /** @var \Drupal\entity_test_revlog\Entity\EntityTestWithRevisionLog $entity */
    $entity = EntityTestWithRevisionLog::create(['type' => 'entity_test_revlog']);
    $entity->setName('view all revisions');
    $user = $this->drupalCreateUser([], $this->randomMachineName());
    $entity->setRevisionUser($user);
    $entity->setRevisionCreationTime((new \DateTime('2 February 2013 4:00:00pm'))->getTimestamp());
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', '02/02/2013 - 16:00');
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', $user->getAccountName());
  }

  /**
   * Test description with entity implementing revision log, with empty values.
   *
   * @covers ::getRevisionDescription
   */
  public function testDescriptionRevLogNullValues(): void {
    $entity = EntityTestWithRevisionLog::create(['type' => 'entity_test_revlog']);
    $entity->setName('view all revisions')->save();

    // Check entity values are still null after saving; they did not receive
    // values from currentUser or some other global context.
    $this->assertNull($entity->getRevisionUser());
    $this->assertNull($entity->getRevisionUserId());
    $this->assertNull($entity->getRevisionLogMessage());

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', 'by Anonymous (not verified)');
  }

  /**
   * Test description with entity, without revision log, no label access.
   *
   * @covers ::getRevisionDescription
   */
  public function testDescriptionNoRevLogNoLabelAccess(): void {
    /** @var \Drupal\entity_test\Entity\EntityTestRev $entity */
    $entity = EntityTestRev::create(['type' => 'entity_test_rev']);
    $entity->setName('view all revisions');
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', '- Restricted access -');
    $this->assertSession()->elementTextNotContains('css', 'table tbody tr:nth-child(1)', $entity->getName());
  }

  /**
   * Test description with entity, without revision log, with label access.
   *
   * @covers ::getRevisionDescription
   */
  public function testDescriptionNoRevLogWithLabelAccess(): void {
    // Permission grants 'view label' access.
    $this->drupalLogin($this->createUser(['view test entity']));

    /** @var \Drupal\entity_test\Entity\EntityTestRev $entity */
    $entity = EntityTestRev::create(['type' => 'entity_test_rev']);
    $entity->setName('view all revisions');
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', $entity->getName());
    $this->assertSession()->elementTextNotContains('css', 'table tbody tr:nth-child(1)', '- Restricted access -');
  }

  /**
   * Test revision link, without access to revision page.
   *
   * @covers ::getRevisionDescription
   */
  public function testDescriptionLinkNoAccess(): void {
    /** @var \Drupal\entity_test_revlog\Entity\EntityTestWithRevisionLog $entity */
    $entity = EntityTestWithRevisionLog::create(['type' => 'entity_test_revlog']);
    $entity->setName('view all revisions');
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementsCount('css', 'table tbody tr', 1);
    $this->assertSession()->elementsCount('css', 'table tbody tr a', 0);
  }

  /**
   * Test revision link, with access to revision page.
   *
   * Test two revisions. Usually the latest revision only checks canonical
   * route access, whereas all others will check individual revision access.
   *
   * @covers ::getRevisionDescription
   */
  public function testDescriptionLinkWithAccess(): void {
    /** @var \Drupal\entity_test_revlog\Entity\EntityTestWithRevisionLog $entity */
    $entity = EntityTestWithRevisionLog::create(['type' => 'entity_test_revlog']);
    // Revision has access to individual revision.
    $entity->setName('view all revisions, view revision');
    $entity->save();
    $firstRevisionId = $entity->getRevisionId();

    // Revision has access to canonical route.
    $entity->setName('view all revisions, view');
    $entity->setNewRevision();
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $row1Link = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1) a');
    $this->assertEquals($entity->toUrl()->toString(), $row1Link->getAttribute('href'));
    // Reload revision so object has the properties to build a revision link.
    $firstRevision = \Drupal::entityTypeManager()->getStorage('entity_test_revlog')
      ->loadRevision($firstRevisionId);
    $row2Link = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(2) a');
    $this->assertEquals($firstRevision->toUrl('revision')->toString(), $row2Link->getAttribute('href'));
  }

  /**
   * Test revision log message if supported, and HTML tags are stripped.
   *
   * @covers ::getRevisionDescription
   */
  public function testDescriptionRevisionLogMessage(): void {
    /** @var \Drupal\entity_test_revlog\Entity\EntityTestWithRevisionLog $entity */
    $entity = EntityTestWithRevisionLog::create(['type' => 'entity_test_revlog']);
    $entity->setName('view all revisions');
    $entity->setRevisionLogMessage('<em>Hello</em> <script>world</script> <strong>123</strong>');
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    // Script tags are stripped, while admin-safe tags are retained.
    $this->assertSession()->elementContains('css', 'table tbody tr:nth-child(1)', '<em>Hello</em> world <strong>123</strong>');
  }

  /**
   * Test revert operation.
   *
   * @covers ::buildRevertRevisionLink
   */
  public function testOperationRevertRevision(): void {
    /** @var \Drupal\entity_test_revlog\Entity\EntityTestWithRevisionLog $entity */
    $entity = EntityTestWithRevisionLog::create(['type' => 'entity_test_revlog']);
    $entity->setName('view all revisions');
    $entity->save();

    $entity->setName('view all revisions, revert');
    $entity->setNewRevision();
    $entity->save();

    $entity->setName('view all revisions, revert');
    $entity->setNewRevision();
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementsCount('css', 'table tbody tr', 3);

    // Latest revision does not have revert revision operation: reverting latest
    // revision is not permitted.
    $row1 = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1)');
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', 'Current revision');
    $this->assertSession()->elementNotExists('named', ['link', 'Revert'], $row1);

    // Revision 2 has revert revision operation: granted access.
    $row2 = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(2)');
    $this->assertSession()->elementExists('named', ['link', 'Revert'], $row2);

    // Revision 3 does not have revert revision operation: no access.
    $row3 = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(3)');
    $this->assertSession()->elementNotExists('named', ['link', 'Revert'], $row3);

    // Reverting latest is allowed if entity access permits it.
    $entity->setName('view all revisions, revert, force allow revert');
    $entity->setNewRevision();
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementsCount('css', 'table tbody tr', 4);

    $row1 = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1)');
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', 'Current revision');
    $this->assertSession()->elementExists('named', ['link', 'Revert'], $row1);
  }

  /**
   * Test delete operation.
   *
   * @covers ::buildDeleteRevisionLink
   */
  public function testOperationDeleteRevision(): void {
    /** @var \Drupal\entity_test_revlog\Entity\EntityTestWithRevisionLog $entity */
    $entity = EntityTestWithRevisionLog::create(['type' => 'entity_test_revlog']);
    $entity->setName('view all revisions');
    $entity->save();

    $entity->setName('view all revisions, delete revision');
    $entity->setNewRevision();
    $entity->save();

    $entity->setName('view all revisions, delete revision');
    $entity->setNewRevision();
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementsCount('css', 'table tbody tr', 3);

    // Latest revision does not have delete revision operation: deleting latest
    // revision is not permitted.
    $row1 = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1)');
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', 'Current revision');
    $this->assertSession()->elementNotExists('named', ['link', 'Delete'], $row1);

    // Revision 2 has delete revision operation: granted access.
    $row2 = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(2)');
    $this->assertSession()->elementExists('named', ['link', 'Delete'], $row2);

    // Revision 3 does not have delete revision operation: no access.
    $row3 = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(3)');
    $this->assertSession()->elementNotExists('named', ['link', 'Delete'], $row3);

    // Deleting latest is allowed if entity access permits it.
    $entity->setName('view all revisions, delete revision, force allow delete revision');
    $entity->setNewRevision();
    $entity->save();

    $this->drupalGet($entity->toUrl('version-history'));
    $this->assertSession()->elementsCount('css', 'table tbody tr', 4);

    $row1 = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1)');
    $this->assertSession()->elementTextContains('css', 'table tbody tr:nth-child(1)', 'Current revision');
    $this->assertSession()->elementExists('named', ['link', 'Delete'], $row1);
  }

}
