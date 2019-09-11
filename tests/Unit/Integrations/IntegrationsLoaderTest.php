<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Configuration;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Unit\BaseTestCase;

final class IntegrationsLoaderTest extends BaseTestCase
{
    private static $dummyIntegrations = [
        'integration_1' => 'DDTrace\Tests\Unit\Integrations\DummyIntegration1',
        'integration_2' => 'DDTrace\Tests\Unit\Integrations\DummyIntegration2',
    ];

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        putenv('DD_TRACE_SANDBOX_ENABLED=false');
    }

    public function testGlobalLoaderDefaultsToOfficiallySupportedIntegrations()
    {
        $this->assertEquals(
            IntegrationsLoader::$officiallySupportedIntegrations,
            IntegrationsLoader::get()->getIntegrations()
        );
    }

    public function testIntegrationsCanBeProvidedToLoader()
    {
        $integration = [
            'name' => 'class',
        ];
        $this->assertEquals($integration, (new IntegrationsLoader($integration))->getIntegrations());
    }

    public function testGlobalConfigCanDisableLoading()
    {
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isEnabled' => false,
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));

        DummyIntegration1::$value = Integration::LOADED;
        $loader = new IntegrationsLoader(self::$dummyIntegrations);
        $loader->loadAll();

        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testSingleIntegrationLoadingCanBeDisabled()
    {
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isEnabled' => true,
            'isIntegrationEnabled' => false,
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));

        DummyIntegration1::$value = Integration::LOADED;
        $loader = new IntegrationsLoader(self::$dummyIntegrations);
        $loader->loadAll();

        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testIntegrationsAreLoaded()
    {
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isEnabled' => true,
            'isIntegrationEnabled' => true,
            'isDebugModeEnabled' => false,
            'isSandboxEnabled' => false,
        ]));
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        DummyIntegration1::$value = Integration::LOADED;
        DummyIntegration2::$value = Integration::NOT_AVAILABLE;
        $loader->loadAll();

        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_2'));
    }

    public function testIntegrationAlreadyLoadedIsNotReloaded()
    {
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isEnabled' => true,
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
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::NOT_AVAILABLE;
        $loader->loadAll();
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testIntegrationNotAvailableIsNotReloaded()
    {
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isEnabled' => true,
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
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::LOADED;
        $loader->loadAll();
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_1'));
    }

    public function testIntegrationNotLoadedIsReloaded()
    {
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isEnabled' => true,
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
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::LOADED;
        $loader->loadAll();
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testWeDidNotForgetToRegisterALibraryForAutoLoading()
    {
        $expected = $this->normalize(glob(__DIR__ . '/../../../src/DDTrace/Integrations/*', GLOB_ONLYDIR));
        $loaded = $this->normalize(array_keys(IntegrationsLoader::get()->getIntegrations()));

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
