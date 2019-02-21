<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\Integrations\Integration;
use DDTrace\Util\Versions;

class SymfonyIntegration
{
    const NAME = 'symfony';
    const BUNDLE_NAME = 'datadog_symfony_bundle';

    public static function load()
    {
        $instance = new self();
        return $instance->doLoad();
    }

    private function doLoad()
    {
        // This is necessary because Symfony\Component\HttpKernel\Kernel::boot it is not properly traced if we do not
        // wrap the context when it is called, which if Symfony\Component\HttpKernel\Kernel::handle.
        dd_trace('Symfony\Component\HttpKernel\Kernel', 'handle', function () {
            $args =  func_get_args();
            return call_user_func_array([$this, 'handle'], $args);
        });

        dd_trace('Symfony\Component\HttpKernel\Kernel', 'boot', function () {
            $result = call_user_func_array([$this, 'boot'], func_get_args());

            $name = SymfonyIntegration::BUNDLE_NAME;
            if (!isset($this->bundles[$name])
                    && defined('\Symfony\Component\HttpKernel\Kernel::VERSION')) {

                $version = \Symfony\Component\HttpKernel\Kernel::VERSION;

                $bundle = null;
                if (Versions::versionMatches('3.4', $version) || Versions::versionMatches('3.3', $version)) {
                    $bundle = \DDTrace\Integrations\Symfony\V3\SymfonyBundle();
                } elseif (Versions::versionMatches('4', $version)) {
                    $bundle = \DDTrace\Integrations\Symfony\V4\SymfonyBundle();
                }

                if ($bundle) {
                    // Simulating behavior of bundle initialization for bundles without any parent bundle based on:
                    // https://github.com/symfony/symfony/blob/05efd1243fb3910fbaaedabf9b4758604b397c0f/src/Symfony/Component/HttpKernel/Kernel.php#L481
                    $this->bundles[$name] = $bundle;
                    $this->bundleMap[$name] = [$bundle];

                    $bundle->setContainer($this->container);
                    $bundle->boot();
                }
            }

            return $result;
        });

        return Integration::LOADED;
    }
}
