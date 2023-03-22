<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Configuration;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\BaseTestCase;
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
        $this->putEnvAndReloadConfig(['DD_TRACE_ENABLED=0']);

        DummyIntegration1::$value = Integration::LOADED;
        $loader = new IntegrationsLoader(self::$dummyIntegrations);
        $loader->loadAll();
        $this->putEnvAndReloadConfig(['DD_TRACE_ENABLED']);

        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testSingleIntegrationLoadingCanBeDisabled()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1',
            'DD_INTEGRATIONS_DISABLED=pdo',
        ]);

        DummyIntegration1::$value = Integration::LOADED;
        $loader = new IntegrationsLoader(self::$dummyIntegrations);
        $loader->loadAll();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED',
            'DD_INTEGRATIONS_DISABLED',
        ]);

        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('pdo'));
    }

    public function testIntegrationsAreLoaded()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1',
        ]);
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        DummyIntegration1::$value = Integration::LOADED;
        DummyIntegration2::$value = Integration::NOT_AVAILABLE;
        $loader->loadAll();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED',
        ]);

        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_2'));
    }

    public function testIntegrationAlreadyLoadedIsNotReloaded()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1',
        ]);
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        // Initially the integration is not loaded
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // We load it
        DummyIntegration1::$value = Integration::LOADED;
        $loader->loadAll();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED',
        ]);
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::NOT_AVAILABLE;
        $loader->loadAll();
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testIntegrationNotAvailableIsNotReloaded()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1',
        ]);
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        // Initially the integration is not loaded
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // We load it
        DummyIntegration1::$value = Integration::NOT_AVAILABLE;
        $loader->loadAll();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED',
        ]);
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::LOADED;
        $loader->loadAll();
        $this->assertSame(Integration::NOT_AVAILABLE, $loader->getLoadingStatus('integration_1'));
    }

    public function testIntegrationNotLoadedIsReloaded()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1',
        ]);
        $loader = new IntegrationsLoader(self::$dummyIntegrations);

        // Initially the integration is not loaded
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // We load it, but the integration returned Integration::NOT_LOADED
        DummyIntegration1::$value = Integration::NOT_LOADED;
        $loader->loadAll();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_ENABLED',
        ]);
        $this->assertSame(Integration::NOT_LOADED, $loader->getLoadingStatus('integration_1'));

        // If now we change the returned value, it won't be reflected in the loadings statuses as it is not reloaded
        DummyIntegration1::$value = Integration::LOADED;
        $loader->loadAll();
        $this->assertSame(Integration::LOADED, $loader->getLoadingStatus('integration_1'));
    }

    public function testWeDidNotForgetToRegisterALibraryForAutoLoading()
    {
        $expected = $this->normalize(glob(__DIR__ . '/../../../src/Integrations/Integrations/*', GLOB_ONLYDIR));

        /*
         * We need a better way of validating loaded integrations:
         * 0) that validates deferred-loaded integrations, and
         * 1) does not require modifying this test.
         */
        $excluded = [];
        if (\PHP_MAJOR_VERSION < 7) {
            $excluded[] = 'mongodb';
            $excluded[] = 'phpredis'; // PHP 7 only integration
        } else {
            // Deferred loading integrations
            $excluded[] = 'cakephp';
            $excluded[] = 'codeigniter';
            $excluded[] = 'elasticsearch';
            $excluded[] = 'eloquent';
            $excluded[] = 'lumen';
            $excluded[] = 'memcached';
            $excluded[] = 'mongodb';
            $excluded[] = 'nette';
            $excluded[] = 'pdo';
            $excluded[] = 'phpredis';
            $excluded[] = 'predis';
            $excluded[] = 'roadrunner';
            $excluded[] = 'slim';
            $excluded[] = 'symfony';
            $excluded[] = 'wordpress';
            $excluded[] = 'yii';
            $excluded[] = 'zendframework';
        }
        if (\PHP_MAJOR_VERSION >= 8) {
            // Integrations that do not support PHP 8
            $excluded[] = 'mongo';
        }
        foreach ($excluded as $integrationToExclude) {
            $index = array_search($integrationToExclude, $expected, true);
            unset($expected[$index]);
        }

        \ksort($expected);

        $integrations = IntegrationsLoader::get()->getIntegrations();
        \ksort($integrations);
        $loaded = $this->normalize(array_keys($integrations));

        self::assertEquals(array_values($expected), array_values($loaded));
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

    public function init()
    {
        return self::$value;
    }
}

class DummyIntegration2
{
    public static $value = null;

    public function init()
    {
        return self::$value;
    }
}
