<?php

namespace Drupal\Tests\help_topics\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\help_topics\HelpTopicTwig;
use Drupal\Tests\UnitTestCase;
use Twig\Template;
use Twig\TemplateWrapper;

/**
 * Unit test for the HelpTopicTwig class.
 *
 * Note that the toUrl() and toLink() methods are not covered, because they
 * have calls to new Url() and new Link() in them, so they cannot be unit
 * tested.
 *
 * @coversDefaultClass \Drupal\help_topics\HelpTopicTwig
 * @group help_topics
 */
class HelpTopicTwigTest extends UnitTestCase {

  /**
   * The help topic instance to test.
   *
   * @var \Drupal\help_topics\HelpTopicTwig
   */
  protected $helpTopic;

  /**
   * The plugin information to use for setting up a test topic.
   *
   * @var array
   */
  const PLUGIN_INFORMATION = [
    'id' => 'test.topic',
    'provider' => 'test',
    'label' => 'This is the topic label',
    'top_level' => TRUE,
    'related' => ['something'],
    'body' => '<p>This is the topic body</p>',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->helpTopic = new HelpTopicTwig([],
      self::PLUGIN_INFORMATION['id'],
      self::PLUGIN_INFORMATION,
      $this->getTwigMock());
  }

  /**
   * @covers ::getBody
   * @covers ::getLabel
   */
  public function testText() {
    $this->assertEquals($this->helpTopic->getBody(),
      ['#markup' => self::PLUGIN_INFORMATION['body']]);
    $this->assertEquals($this->helpTopic->getLabel(),
      self::PLUGIN_INFORMATION['label']);
  }

  /**
   * @covers ::getProvider
   * @covers ::isTopLevel
   * @covers ::getRelated
   */
  public function testDefinition() {
    $this->assertEquals($this->helpTopic->getProvider(),
      self::PLUGIN_INFORMATION['provider']);
    $this->assertEquals($this->helpTopic->isTopLevel(),
      self::PLUGIN_INFORMATION['top_level']);
    $this->assertEquals($this->helpTopic->getRelated(),
      self::PLUGIN_INFORMATION['related']);
  }

  /**
   * @covers ::getCacheContexts
   * @covers ::getCacheTags
   * @covers ::getCacheMaxAge
   */
  public function testCacheInfo() {
    $this->assertEquals([], $this->helpTopic->getCacheContexts());
    $this->assertEquals(['core.extension'], $this->helpTopic->getCacheTags());
    $this->assertEquals(Cache::PERMANENT, $this->helpTopic->getCacheMaxAge());
  }

  /**
   * Creates a mock Twig loader class for the test.
   */
  protected function getTwigMock() {
    $twig = $this
      ->getMockBuilder('Drupal\Core\Template\TwigEnvironment')
      ->disableOriginalConstructor()
      ->getMock();

    $template = $this->getMockForAbstractClass(Template::class, [$twig], '', TRUE, TRUE, TRUE, ['render']);
    $template
      ->method('render')
      ->willReturn(self::PLUGIN_INFORMATION['body']);

    $twig
      ->method('load')
      ->willReturn(new TemplateWrapper($twig, $template));

    return $twig;
  }

}
