<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Configuration;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Util\Versions;

final class IntegrationsLoaderTest extends BaseTestCase
{
    private static $dummyIntegrations = [
        'integration_1' => 'DDTrace\Tests\Unit\Integrations\DummyIntegration1',
        'integration_2' => 'DDTrace\Tests\Unit\Integrations\DummyIntegration2',
    ];

    public function testIntegrationsCanBeProvidedToLoader()
    {
        $integration = [
            'name' => 'class',
        ];
        $integrations = (new IntegrationsLoader($integration))->getIntegrations();
        self::assertArrayHasKey('name', $integrations);
        self::assertEquals('class', $integrations['name']);
    }

    public function testGlobalConfigCanDisableLoading()
    {
        putenv('DD_TRACE_ENABLED=0');
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));

        DummyIntegration1::$value = Integration::LOADED;
        $loader = new IntegrationsLoader(self::$dummyIntegrations);
        $loader->loadAll();
        putenv('DD_TRACE_ENABLED');

        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testSingleIntegrationLoadingCanBeDisabled()
    {
        putenv('DD_TRACE_ENABLED=1');
        putenv('DD_INTEGRATIONS_DISABLED=integration_1');
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));

        DummyIntegration1::$value = Integration::LOADED;
        $loader = new IntegrationsLoader(self::$dummyIntegrations);
        $loader->loadAll();
        putenv('DD_INTEGRATIONS_DISABLED');
        putenv('DD_TRACE_ENABLED');

        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testIntegrationsAreLoaded()
    {
        putenv('DD_TRACE_ENABLED=true');
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isIntegrationEnabled' => true,
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        DummyIntegration1::$value = Integration::LOADED;
        DummyIntegration2::$value = Integration::NOT_AVAILABLE;
        $loader->loadAll();
        putenv('DD_TRACE_ENABLED');

        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_2'));
    }

    public function testIntegrationAlreadyLoadedIsNotReloaded()
    {
        putenv('DD_TRACE_ENABLED=1');
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isIntegrationEnabled' => true,
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        // Initially the integration is not loaded
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // We load it
        DummyIntegration1::$value = Integration::LOADED;
        $loader->loadAll();
        putenv('DD_TRACE_ENABLED');
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::NOT_AVAILABLE;
        $loader->loadAll();
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testIntegrationNotAvailableIsNotReloaded()
    {
        putenv('DD_TRACE_ENABLED=1');
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isIntegrationEnabled' => true,
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        // Initially the integration is not loaded
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // We load it
        DummyIntegration1::$value = Integration::NOT_AVAILABLE;
        $loader->loadAll();
        putenv('DD_TRACE_ENABLED');
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::LOADED;
        $loader->loadAll();
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_1'));
    }

    public function testIntegrationNotLoadedIsReloaded()
    {
        putenv('DD_TRACE_ENABLED=1');
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isIntegrationEnabled' => true,
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        // Initially the integration is not loaded
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // We load it, but the integration returned Integration::NOT_LOADED
        DummyIntegration1::$value = Integration::NOT_LOADED;
        $loader->loadAll();
        putenv('DD_TRACE_ENABLED');
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::LOADED;
        $loader->loadAll();
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testWeDidNotForgetToRegisterALibraryForAutoLoading()
    {
        if (Versions::phpVersionMatches('5.4')) {
            $this->markTestSkipped('Sandboxed tests are skipped on PHP 5.4 so we cannot check for all integrations.');
        }
        $expected = $this->normalize(glob(__DIR__ . '/../../../src/DDTrace/Integrations/*', GLOB_ONLYDIR));
        \ksort($expected);
        $integrations = IntegrationsLoader::get()->getIntegrations();
        \ksort($integrations);
        $loaded = $this->normalize(array_keys($integrations));

        // If this test fails you need to add an entry to IntegrationsLoader::LIBRARIES array.
        $this->assertEquals(array_values($expected), array_values($loaded));
    }

    /**
     * Normalizes integrations folders/names to a simplified format suitable for easy comparison.
     *
     * @param array $array_map
     * @return array
     */
    private function normalize(array $array_map)
    {
        return array_map(function ($entry) {
            if (strrpos($entry, '/')) {
                $name = substr($entry, strrpos($entry, '/') + 1);
            } else {
                $name = $entry;
            }
            return strtolower($name);
        }, $array_map);
    }
}

class DummyIntegration1
{
    public static $value = null;

    public static function load()
    {
        return self::$value;
    }
}

class DummyIntegration2
{
    public static $value = null;

    public static function load()
    {
        return self::$value;
    }
}
