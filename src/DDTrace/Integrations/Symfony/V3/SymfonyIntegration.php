<?php

namespace DDTrace\Integrations\Symfony\V3;

use DDTrace\Integrations\Symfony\SymfonyIntegration as DDSymfonyIntegration;

class SymfonyIntegration
{
    public static function load()
    {
        dd_trace('Symfony\Component\HttpKernel\Kernel', 'initializeBundles', function () {
            $this->initializeBundles();
            $name = DDSymfonyIntegration::BUNDLE_NAME;

            // If the user has already registered the bundle, we do not register it again.
            if (isset($this->bundles[$name])) {
                return;
            }

            $bundle = new SymfonyBundle();

            // Simulating behavior of bundle initialization for bundles without any parent bundle based on:
            // https://github.com/symfony/symfony/blob/05efd1243fb3910fbaaedabf9b4758604b397c0f/src/Symfony/Component/HttpKernel/Kernel.php#L481
            $this->bundles[$name] = $bundle;
            $this->bundleMap[$name] = [$bundle];
        });
    }
}
