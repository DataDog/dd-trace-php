<?php

namespace Drupal\Tests\dblog\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Drupal\Tests\rest\Functional\CookieResourceTestTrait;
use Drupal\Tests\rest\Functional\ResourceTestBase;

/**
 * Tests the watchdog database log resource.
 *
 * @group dblog
 */
class DbLogResourceTest extends ResourceTestBase {

  use CookieResourceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $auth = 'cookie';

  /**
   * {@inheritdoc}
   */
  protected static $resourceConfigId = 'dblog';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['rest', 'dblog'];

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected static $entityTypeId = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $auth = isset(static::$auth) ? [static::$auth] : [];
    $this->provisionResource([static::$format], $auth);
  }

  /**
   * Writes a log messages and retrieves it via the REST API.
   */
  public function testWatchdog(): void {
    // Write a log message to the DB.
    $this->container->get('logger.channel.rest')->notice('Test message');
    // Get the ID of the written message.
    $id = Database::getConnection()->select('watchdog', 'w')
      ->fields('w', ['wid'])
      ->condition('type', 'rest')
      ->orderBy('wid', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $this->initAuthentication();
    $url = Url::fromRoute(
      'rest.dblog.GET',
      ['id' => $id, '_format' => static::$format]
    );
    $request_options = $this->getAuthenticationRequestOptions('GET');

    $response = $this->request('GET', $url, $request_options);
    $this->assertResourceErrorResponse(
      403,
      "The 'restful get dblog' permission is required.",
      $response,
      ['4xx-response', 'http_response'],
      ['user.permissions']
    );

    // Create a user account that has the required permissions to read
    // the watchdog resource via the REST API.
    $this->setUpAuthorization('GET');

    $response = $this->request('GET', $url, $request_options);
    $this->assertResourceResponse(
      200,
      FALSE,
      $response,
      ['config:rest.resource.dblog', 'http_response'],
      ['user.permissions'],
      FALSE,
      'MISS'
    );
    $log = Json::decode((string) $response->getBody());
    $this->assertEquals($id, $log['wid'], 'Log ID is correct.');
    $this->assertEquals('rest', $log['type'], 'Type of log message is correct.');
    $this->assertEquals('Test message', $log['message'], 'Log message text is correct.');

    // Request an unknown log entry.
    $url->setRouteParameter('id', 9999);
    $response = $this->request('GET', $url, $request_options);
    $this->assertResourceErrorResponse(404, "Log entry with ID '9999' was not found", $response);

    // Make a bad request (a true malformed request would never be a route
    // match).
    $url->setRouteParameter('id', 0);
    $response = $this->request('GET', $url, $request_options);
    $this->assertResourceErrorResponse(400, 'No log entry ID was provided', $response);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpAuthorization($method) {
    switch ($method) {
      case 'GET':
        $this->grantPermissionsToTestedRole(['restful get dblog']);
        break;

      default:
        throw new \UnexpectedValueException();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function assertNormalizationEdgeCases($method, Url $url, array $request_options): void {}

  /**
   * {@inheritdoc}
   */
  protected function getExpectedUnauthorizedAccessMessage($method) {
    switch ($method) {
      case 'GET':
        return "The 'restful get dblog' permission is required.";

      default:
        return parent::getExpectedUnauthorizedAccessMessage($method);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getExpectedUnauthorizedAccessCacheability() {
    return (new CacheableMetadata())
      ->setCacheTags(['4xx-response', 'http_response'])
      ->setCacheContexts(['user.permissions']);
  }

  /**
   * Empty function.
   *
   * Needed by PHPStan for unused function
   * CookieResourceTestTrait::assertResponseWhenMissingAuthentication().
   */
  protected function getExpectedUnauthorizedEntityAccessCacheability($is_authenticated) {}

}
