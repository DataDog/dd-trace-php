<?php

namespace DDTrace\Integrations\Symfony\V3;

use DDTrace\Integrations\Symfony\SymfonyIntegration as DDSymfonyIntegration;

class SymfonyIntegration
{
    public static function load()
    {
        // This is necessary because Symfony\Component\HttpKernel\Kernel::boot it is not properly traced if we do not
        // wrap the context when it is called, which if Symfony\Component\HttpKernel\Kernel::handle.
        dd_trace('Symfony\Component\HttpKernel\Kernel', 'handle', function () {
            $args =  func_get_args();
            return call_user_func_array([$this, 'handle'], $args);
        });

        dd_trace('Symfony\Component\HttpKernel\Kernel', 'boot', function () {
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
