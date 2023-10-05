<?php

namespace Drupal\Tests\ban\Kernel\Migrate\d7;

use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\migrate_drupal\Kernel\d7\MigrateDrupal7TestBase;

/**
 * Migrate blocked IPs.
 *
 * @group ban
 */
class MigrateBlockedIpsTest extends MigrateDrupal7TestBase {

  use SchemaCheckTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['ban'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ban', ['ban_ip']);
  }

  /**
   * Tests migration of blocked IPs.
   */
  public function testBlockedIps() {
    $this->startCollectingMessages();
    $this->executeMigration('d7_blocked_ips');
    $this->assertEmpty($this->migrateMessages);
    $this->assertTrue(\Drupal::service('ban.ip_manager')->isBanned('111.111.111.111'));
  }

}
