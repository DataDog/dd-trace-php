<?php

namespace DDTrace\Integrations\Symfony\V3;

use DDTrace\Integrations\Symfony\SymfonyIntegration as DDSymfonyIntegration;

class SymfonyIntegration
{
    public static function load()
    {
        // If this line is not here then the Symfony\Component\HttpKernel\Kernel::boot
        // is not traced in php 5.6
        dd_trace('AppKernel', 'handle', function() {
            return call_user_func_array([$this, 'handle'], func_get_args());
        });

        dd_trace('Symfony\Component\HttpKernel\Kernel', 'boot', function() {
            $result = call_user_func_array([$this, 'boot'], func_get_args());

            $name = DDSymfonyIntegration::BUNDLE_NAME;
            if (!isset($this->bundles[$name])) {
                $bundle = new SymfonyBundle();
                // Simulating behavior of bundle initialization for bundles without any parent bundle based on:
                // https://github.com/symfony/symfony/blob/05efd1243fb3910fbaaedabf9b4758604b397c0f/src/Symfony/Component/HttpKernel/Kernel.php#L481
                $this->bundles[$name] = $bundle;
                $this->bundleMap[$name] = [$bundle];

                $bundle->setContainer($this->container);
                $bundle->boot();
            }

            return $result;
        });
    }
}
