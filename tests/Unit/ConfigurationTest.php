<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Configuration;
use DDTrace\Integrations\AbstractIntegrationConfiguration;
use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Guzzle\GuzzleIntegration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Integrations\Web\WebIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration;

final class ConfigurationTest extends BaseTestCase
{
    protected function setUp()
    {
        parent::setUp();
        putenv('DD_TRACE_ENABLED');
        putenv('DD_DISTRIBUTED_TRACING');
        putenv('DD_PRIORITY_SAMPLING');
        putenv('DD_INTEGRATIONS_DISABLED');
        putenv('DD_TRACE_DEBUG');
    }

    public function testTracerEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isEnabled());
    }

    public function testTracerDisabled()
    {
        putenv('DD_TRACE_ENABLED=false');
        $this->assertFalse(Configuration::get()->isEnabled());
    }

    public function testDebugModeDisabledByDefault()
    {
        $this->assertFalse(Configuration::get()->isDebugModeEnabled());
    }

    public function testDebugModeCanBeEnabled()
    {
        putenv('DD_TRACE_DEBUG=true');
        $this->assertTrue(Configuration::get()->isDebugModeEnabled());
    }

    public function testDistributedTracingEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isDistributedTracingEnabled());
    }

    public function testDistributedTracingDisabled()
    {
        putenv('DD_DISTRIBUTED_TRACING=false');
        $this->assertFalse(Configuration::get()->isDistributedTracingEnabled());
    }

    public function testPrioritySamplingEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isPrioritySamplingEnabled());
    }

    public function testPrioritySamplingDisabled()
    {
        putenv('DD_PRIORITY_SAMPLING=false');
        $this->assertFalse(Configuration::get()->isPrioritySamplingEnabled());
    }

    public function testAllIntegrationsEnabledByDefault()
    {
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('any_one'));
    }

    public function testIntegrationsDisabled()
    {
        putenv('DD_INTEGRATIONS_DISABLED=one,two');
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('one'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('two'));
        $this->assertTrue(Configuration::get()->isIntegrationEnabled('three'));
    }

    public function testIntegrationsDisabledIfGlobalDisabled()
    {
        putenv('DD_INTEGRATIONS_DISABLED=one');
        putenv('DD_TRACE_ENABLED=false');
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('one'));
        $this->assertFalse(Configuration::get()->isIntegrationEnabled('two'));
    }

    public function testAppNameFallbackPriorities()
    {
        putenv('ddtrace_app_name');
        putenv('DD_TRACE_APP_NAME');
        $this->assertSame(
            'fallback_name',
            Configuration::get()->appName('fallback_name')
        );

        putenv('ddtrace_app_name=foo_app');
        $this->assertSame('foo_app', Configuration::get()->appName());

        Configuration::clear();
        putenv('ddtrace_app_name=foo_app');
        putenv('DD_TRACE_APP_NAME=bar_app');
        $this->assertSame('bar_app', Configuration::get()->appName());
    }

    /**
     * @dataProvider dataProviderIntegrationLevelConfigurationGeneration
     * @param string $name The integration name
     * @param string $method
     * @param string $expectedClass
     */
    public function testIntegrationLevelConfigurationGeneration($name, $method, $expectedClass)
    {
        /** @var AbstractIntegrationConfiguration $config */
        $config = Configuration::get()->$method();
        $this->assertInstanceOf($expectedClass, $config);
        $this->assertSame($name, $config->getIntegrationName());
    }

    public function dataProviderIntegrationLevelConfigurationGeneration()
    {
        return [
            [CurlIntegration::NAME, 'curl', 'DDTrace\Integrations\Curl\CurlConfiguration',],
            [
                ElasticSearchIntegration::NAME,
                'elasticSearch',
                'DDTrace\Integrations\ElasticSearch\ElasticSearchConfiguration',
            ],
            [EloquentIntegration::NAME, 'eloquent', 'DDTrace\Integrations\Eloquent\EloquentConfiguration',],
            [GuzzleIntegration::NAME, 'guzzle', 'DDTrace\Integrations\Guzzle\GuzzleConfiguration',],
            [LaravelIntegration::NAME, 'laravel', 'DDTrace\Integrations\Laravel\LaravelConfiguration',],
            [MemcachedIntegration::NAME, 'memcached', 'DDTrace\Integrations\Memcached\MemcachedConfiguration',],
            [MongoIntegration::NAME, 'mongo', 'DDTrace\Integrations\Mongo\MongoConfiguration',],
            [MysqliIntegration::NAME, 'mysqli', 'DDTrace\Integrations\Mysqli\MysqliConfiguration',],
            [PDOIntegration::NAME, 'pdo', 'DDTrace\Integrations\Pdo\PdoConfiguration',],
            [PredisIntegration::NAME, 'predis', 'DDTrace\Integrations\Predis\PredisConfiguration',],
            [SymfonyIntegration::NAME, 'symfony', 'DDTrace\Integrations\Symfony\SymfonyConfiguration',],
            [WebIntegration::NAME, 'web', 'DDTrace\Integrations\Web\WebConfiguration',],
            [
                ZendFrameworkIntegration::NAME,
                'zendFramework',
                'DDTrace\Integrations\ZendFramework\ZendFrameworkConfiguration',
            ],
        ];
    }
}
